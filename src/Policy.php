<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Grcmanager;

use CronTask;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\PolicyBase;
use GlpiPlugin\Grcmanager\Services\Policy\AcknowledgementRules;
use GlpiPlugin\Grcmanager\Services\Policy\CharterSignature;
use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Html;
use KnowbaseItem_Item;
use Session;

/**
 * Security policy library (issue #28, ISO/IEC 27001:2022 A.5.1/A.5.1.1/A.5.1.2): the control
 * A.5.1 ("Politiques de sécurité de l'information") existed until now only as a checkbox row in
 * the SoA (Control), with no actual operational tool behind it. This class gives
 * the RSSI a real place to manage security policy documents (charte informatique, politique de
 * mots de passe, PCA...) with a real lifecycle: draft/approved/archived status, a free-text
 * version string (e.g. "1.2"), an approval date, and a next review date driving the same kind of
 * automatic reminder already in place for the risk register (see PolicyReviewReminderService,
 * mirroring GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService).
 *
 * The actual policy document (PDF, Word...) is never stored by this plugin itself: attached via
 * GLPI's own native Document/Document_Item polymorphic relation (see defineTabs() below), the same
 * mechanism every core GLPI itemtype uses for its own "Documents" tab, confirmed by reading GLPI
 * 11 core (src/Computer.php's own defineTabs(), src/Document_Item.php, src/Document.php). Adding
 * a new file-storage mechanism of this plugin's own was explicitly out of scope for this issue.
 */
class Policy extends PolicyBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Issue #775, lot 2 : bouton « Enregistrer la revue » d'une procédure d'exploitation. */
    public const REVIEW_MARKER = '_record_procedure_review';

    /** Revue tracée pendant cette mise à jour (journal d'audit dans post_updateItem()). */
    private bool $reviewRecorded = false;

    /**
     * GLPI notification event name (see inc/notificationtargetpolicy.class.php and
     * src/Services/Policy/PolicyReviewReminderService.php), shared here as a single source of
     * truth so the event string never drifts between the NotificationTarget, the reminder service,
     * and the Cron entry point below.
     */
    public const REVIEW_DUE_EVENT = PolicyReviewReminderService::EVENT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_policies';
    }

    /**
     * Issue #1165 : la bibliothèque des politiques est commune à toutes les entités. Les colonnes
     * `entities_id` (0) et `is_recursive` (1) n'existent que pour l'onglet natif « Base de
     * connaissances », qui filtre toujours sur elles (voir Install/PolicyKnowbaseScopeTables) :
     * elles ne rendent pas la politique cloisonnée par entité (recherche, droits, formulaire).
     */
    public function isEntityAssign()
    {
        return false;
    }

    /** Issue #1038 : entrée de la rubrique « Politiques et procédures » (src/Navigation/Section/). */
    public static function getMenuContent()
    {
        return Menu::withSubPages(self::class, parent::getMenuContent());
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Security policy', 'Security policies', $nb, 'grcmanager');
    }

    /**
     * This class' primary display column is `title`, not GLPI's default `name` (which this table
     * doesn't even have). Front-page-bar bug found live: `front/policy.form.php` is this plugin's
     * only front controller that calls `displayFullPageForItem()` (needed so the native
     * "Documents" tab actually renders, see that file's own comment): that path builds the page
     * header/breadcrumb from `getNameField()`, so without this override it silently read a
     * nonexistent `name` field and printed the literal string "N/A" instead of the policy's real
     * title. Every sibling class here (Risk, Audit...) uses a plain `Html::header()` call instead,
     * which never looks up a name field at all, so none of them needed this override.
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            PolicyLifecycle::STATUS_DRAFT    => __('Draft', 'grcmanager'),
            PolicyLifecycle::STATUS_APPROVED => __('Approved', 'grcmanager'),
            PolicyLifecycle::STATUS_ARCHIVED => __('Archived', 'grcmanager'),
        ];
    }

    /**
     * Issue #775 : catégorie du document. Une procédure d'exploitation (A.5.37) est un document de
     * la bibliothèque comme les autres, avec en plus son domaine.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            OperatingProcedureRules::CATEGORY_POLICY    => self::getTypeName(1),
            OperatingProcedureRules::CATEGORY_PROCEDURE => OperatingProcedure::getTypeName(1),
        ];
    }

    /**
     * Grants this class GLPI's own native "Documents" tab (Document_Item), so a policy's actual
     * file(s) (PDF, Word...) can be attached without this plugin inventing any storage mechanism
     * of its own - confirmed against GLPI 11 core that a plain CommonDBTM gets NO tab for free:
     * every core itemtype that has a "Documents" tab (Computer, Ticket, KnowbaseItem...) adds it
     * explicitly in its own defineTabs() override, exactly as done here.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(PolicyVersion::class, $tabs, $options)
            ->addStandardTab(PolicyAcknowledgement::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            // Issue #775, lot 2 : le texte d'une procédure peut être un article de la base de
            // connaissances de GLPI, lié par l'onglet natif.
            ->addStandardTab(KnowbaseItem_Item::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'title',
            'name'     => __('Title', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'version',
            'name'     => __('Version', 'grcmanager'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'approval_date',
            'name'     => __('Approval date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'next_review_date',
            'name'     => __('Next review', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see Risk::rawSearchOptions().
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // Issue #775 : catégorie (politique ou procédure d'exploitation) et domaine d'une procédure.
        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'category',
            'name'     => __('Category', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'procedure_domain',
            'name'     => __('Procedure domain', 'grcmanager'),
            'datatype' => 'specific',
        ];

        return $tab;
    }

    /**
     * Translates the raw `status` DB value into a color-coded Tabler badge, same UX convention as
     * every other enum column in this plugin (see Risk::getSpecificValueToDisplay()).
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            return self::statusBadge($values[$field] ?? null);
        }
        if ($field === 'category') {
            $category = OperatingProcedureRules::sanitizeCategory($values[$field] ?? null);

            return htmlescape(self::getCategories()[$category]);
        }
        if ($field === 'procedure_domain') {
            return htmlescape(OperatingProcedure::domainLabel((string) ($values[$field] ?? '')));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widget for `status`, same lesson (and same reason) as
     * Risk::getSpecificValueToSelect(): 'specific' falls through to a free-text
     * box by default, not self-explanatory for a non-technical user.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        if ($field === 'status') {
            return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        if ($field === 'category') {
            return Dropdown::showFromArray($name, self::getCategories(), $options);
        }
        if ($field === 'procedure_domain') {
            return Dropdown::showFromArray(
                $name,
                OperatingProcedure::domainChoices((string) $options['value']),
                $options
            );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            PolicyLifecycle::STATUS_DRAFT    => ['bg-secondary-lt', 'ti-pencil', __('Draft', 'grcmanager')],
            PolicyLifecycle::STATUS_APPROVED => ['bg-green-lt', 'ti-circle-check', __('Approved', 'grcmanager')],
            PolicyLifecycle::STATUS_ARCHIVED => ['bg-yellow-lt', 'ti-archive', __('Archived', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    /**
     * Enforces ISO 27001 A.5.1.1: a policy cannot be marked "approved" without a recorded approval
     * date (see PolicyLifecycle::isApprovalDateMissing()). Returning false here aborts add()/
     * update() the same way GLPI core itself treats a false return from prepareInputForAdd()/
     * prepareInputForUpdate(), with a real, visible error message, same convention already used by
     * Control::prepareInputForAdd()/prepareInputForUpdate().
     *
     * Issue #611 : circuit d'approbation activé, « Approuvée » ne s'obtient que par les approbateurs
     * désignés (PolicyVersion::answer()), la date d'approbation n'est plus saisie, et le contenu est
     * figé pendant l'approbation et après (nouvelle version à préparer).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        // Issue #775, lot 2 : la revue tracée ne se saisit jamais à la main.
        unset($input['last_review_date'], $input['users_id_lastreviewer'], $input[self::REVIEW_MARKER]);
        // Issue #1165 : valeurs fixes (racine, récursive), jamais saisies.
        unset($input['entities_id'], $input['is_recursive']);

        return $this->validateStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        $this->reviewRecorded = false;
        $recordReview = !empty($input[self::REVIEW_MARKER]);
        unset($input['last_review_date'], $input['users_id_lastreviewer'], $input[self::REVIEW_MARKER]);
        unset($input['entities_id'], $input['is_recursive']);
        $input = $this->validateStatus($input);
        if ($input === false || !$recordReview) {
            return $input;
        }

        return $this->recordReview($input);
    }

    /**
     * Issue #775, lot 2 : revue périodique tracée d'une procédure d'exploitation approuvée (date du
     * jour, personne connectée), même sans nouvelle version. La prochaine revue est celle saisie sur
     * la fiche si elle a été avancée au-delà d'aujourd'hui, un an plus tard sinon. Le journal d'audit
     * garde chaque revue.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function recordReview(array $input)
    {
        $category = (string) ($input['category'] ?? ($this->fields['category'] ?? ''));
        $status = (string) ($input['status'] ?? ($this->fields['status'] ?? ''));
        if (!OperatingProcedureRules::canRecordReview($category, $status)) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'A review can only be recorded on an approved operating procedure.',
                'grcmanager'
            )), false, ERROR);

            return false;
        }
        $today = date('Y-m-d');
        $input['last_review_date'] = $today;
        $input['users_id_lastreviewer'] = (int) Session::getLoginUserID();
        $input['next_review_date'] = OperatingProcedureRules::nextReviewAfterReview(
            $today,
            $input['next_review_date'] ?? null,
            $this->fields['next_review_date'] ?? null
        );
        $this->reviewRecorded = true;

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateStatus(array $input)
    {
        $fromWorkflow = !empty($input['_policy_approval']);
        unset($input['_policy_approval']);
        if (!$fromWorkflow && PolicyApprovalConfig::isEnabled() && !$this->checkApprovalWorkflow($input)) {
            return false;
        }

        $status = PolicyLifecycle::sanitize(
            (string) ($input['status'] ?? ($this->fields['status'] ?? PolicyLifecycle::STATUS_DRAFT))
        );
        $approvalDate = $input['approval_date'] ?? ($this->fields['approval_date'] ?? null);

        if (PolicyLifecycle::isApprovalDateMissing($status, $approvalDate)) {
            Session::addMessageAfterRedirect(
                __('An approval date is required to mark a policy as approved.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        $input['status'] = $status;
        if (array_key_exists('acknowledgement_days', $input)) {
            $input['acknowledgement_days'] = AcknowledgementRules::sanitizeDays($input['acknowledgement_days']);
        }
        if (array_key_exists('is_it_charter', $input)) {
            $input['is_it_charter'] = empty($input['is_it_charter']) ? 0 : 1;
        }
        if (array_key_exists('approval_quorum', $input)) {
            $input['approval_quorum'] = PolicyApprovalRules::sanitizeQuorum($input['approval_quorum']);
        }
        // Issue #775 : seule une procédure d'exploitation a un domaine.
        if (
            $this->isNewItem()
            || array_key_exists('category', $input)
            || array_key_exists('procedure_domain', $input)
        ) {
            $category = OperatingProcedureRules::sanitizeCategory(
                $input['category'] ?? ($this->fields['category'] ?? OperatingProcedureRules::CATEGORY_POLICY)
            );
            $input['category'] = $category;
            $input['procedure_domain'] = OperatingProcedureRules::sanitizeDomain(
                $input['procedure_domain'] ?? ($this->fields['procedure_domain'] ?? ''),
                $category
            );
        }

        return $input;
    }

    /**
     * Issue #611 : règles d'une saisie manuelle quand le circuit est activé. La date d'approbation
     * saisie est ignorée ; passer à « Approuvée » est refusé ; le contenu figé ne change pas.
     *
     * @param array<string, mixed> $input
     */
    private function checkApprovalWorkflow(array &$input): bool
    {
        $currentStatus = (string) ($this->fields['status'] ?? '');
        if (array_key_exists('approval_date', $input)) {
            if ($this->isNewItem()) {
                unset($input['approval_date']);
            } else {
                $input['approval_date'] = $this->fields['approval_date'];
            }
        }
        $wanted = (string) ($input['status'] ?? $currentStatus);
        if ($wanted === PolicyLifecycle::STATUS_APPROVED && $currentStatus !== PolicyLifecycle::STATUS_APPROVED) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'With the approval workflow, a policy becomes "Approved" only once its designated approvers have '
                    . 'approved it: use the "Approval" tab.',
                'grcmanager'
            )), false, ERROR);

            return false;
        }
        if ($this->isNewItem()) {
            return true;
        }
        $hasPending = PolicyVersion::hasPending($this->getID());
        if (
            PolicyApprovalRules::isContentLocked($currentStatus, $hasPending)
            && PolicyApprovalRules::changedContentFields($this->fields, $input) !== []
        ) {
            $message = $hasPending
                ? __(
                    'This version is awaiting approval: its content is frozen. Withdraw the request to modify it.',
                    'grcmanager'
                )
                : __(
                    'This version has been approved: its content is frozen. Prepare a new version from the '
                        . '"Approval" tab to modify it.',
                    'grcmanager'
                );
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return false;
        }

        return true;
    }

    /**
     * Issue #535 : une nouvelle version d'une politique déjà diffusée relance la campagne de prise
     * de connaissance (PolicyAcknowledgement::relaunchIfNewVersion()). Rien d'autre ne change.
     */
    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if ($this->reviewRecorded) {
            $this->reviewRecorded = false;
            AuditLog::record('review', self::class, $this->getID(), 0, 'success', [
                'last_review_date' => (string) $this->fields['last_review_date'],
                'next_review_date' => (string) $this->fields['next_review_date'],
            ]);
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Review recorded. Next review: %s.', 'grcmanager'),
                Html::convDate((string) $this->fields['next_review_date'])
            )));
        }
        if (array_intersect(['version', 'status'], $this->updates) === []) {
            return;
        }
        $count = PolicyAcknowledgement::relaunchIfNewVersion($this);
        if ($count > 0) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('New version %1$s: an acknowledgement campaign has been launched for %2$d person(s).', 'grcmanager'),
                (string) $this->fields['version'],
                $count
            )));
        }
    }

    public function cleanDBonPurge()
    {
        PolicyAcknowledgement::purgePolicy($this->getID());
        PolicyVersion::purgePolicy($this->getID());
        // Issue #703 : plus citée par le registre NIS2 Italia - ACN.
        AcnRequirement::forgetPolicy((int) $this->getID());
        // Issues #879 et #545 : liens aux mesures de la SoA et trace de l'import d'un modèle.
        PolicyCoverage::forgetPolicy((int) $this->getID());
        PolicyTemplate::forgetPolicy((int) $this->getID());
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $statuses = self::getStatuses();
        if (
            PolicyApprovalConfig::isEnabled()
            && ($this->fields['status'] ?? '') !== PolicyLifecycle::STATUS_APPROVED
        ) {
            unset($statuses[PolicyLifecycle::STATUS_APPROVED]);
        }
        // Issue #775 : nouvelle procédure ouverte depuis le registre, catégorie et domaine choisis.
        $category = OperatingProcedureRules::sanitizeCategory(
            $this->isNewItem() ? ($options['category'] ?? null) : ($this->fields['category'] ?? null)
        );
        $domain = OperatingProcedureRules::sanitizeDomain(
            $this->isNewItem() ? ($options['procedure_domain'] ?? '') : ($this->fields['procedure_domain'] ?? ''),
            OperatingProcedureRules::CATEGORY_PROCEDURE
        );
        TemplateRenderer::getInstance()->display('@grcmanager/policy_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'statuses'       => $statuses,
            'default_status' => PolicyLifecycle::STATUS_DRAFT,
            'categories'     => self::getCategories(),
            'category'       => $category,
            'domains'        => OperatingProcedure::domainChoices($domain),
            'domain'         => $domain,
            // Issue #775, lot 2 : revue tracée d'une procédure d'exploitation.
            'review_marker'  => self::REVIEW_MARKER,
            'can_review'     => !$this->isNewItem() && $this->can($this->getID(), UPDATE)
                && OperatingProcedureRules::canRecordReview($category, (string) ($this->fields['status'] ?? '')),
            'last_reviewer'  => (int) ($this->fields['users_id_lastreviewer'] ?? 0) > 0
                ? (string) getUserName((int) $this->fields['users_id_lastreviewer']) : '',
            // Issue #775, lot 3 : historique de toutes les revues sur la fiche.
            'review_history' => $category === OperatingProcedureRules::CATEGORY_PROCEDURE && !$this->isNewItem()
                ? OperatingProcedure::reviewHistory($this->getID()) : [],
            // Issue #611 : circuit d'approbation activé, « Approuvée » ne se choisit plus ici.
            'approval_workflow' => PolicyApprovalConfig::isEnabled(),
            'content_locked'    => PolicyApprovalConfig::isEnabled() && !$this->isNewItem()
                && PolicyApprovalRules::isContentLocked(
                    (string) ($this->fields['status'] ?? ''),
                    PolicyVersion::hasPending($this->getID())
                ),
        ]);
        if (!$this->isNewItem() && !empty($this->fields['is_it_charter'])) {
            $this->showCharterSignatures();
        }

        return true;
    }

    /**
     * Issue #283 : signatures de la charte (campagnes de charte d'assetsign, lecture seule, comme
     * pour les mesures A.5.10 et A.6.3) et invitation à relancer une campagne quand la version
     * approuvée est plus récente que la dernière campagne d'une entité. Rien n'est lancé
     * automatiquement.
     */
    private function showCharterSignatures(): void
    {
        $summary = \GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidenceProvider::fetchCharterSummary();
        $rows = [];
        foreach ($summary ?? [] as $row) {
            $rows[] = $row + [
                'entity'  => Dropdown::getDropdownName('glpi_entities', $row['entities_id']),
                'outdated' => CharterSignature::needsNewCampaign(
                    (string) ($this->fields['status'] ?? ''),
                    $this->fields['approval_date'] ?? null,
                    $row['date']
                ),
            ];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/policy_charter_signatures.html.twig', [
            'available'     => $summary !== null,
            'rows'          => $rows,
            'version'       => (string) ($this->fields['version'] ?? ''),
            'campaigns_url' => \GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidenceProvider::campaignsUrl(),
        ]);
    }

    /**
     * GLPI Cron entry point, registered via CronTask::Register() in the plugin installer
     * (src/Install/Installer.php), same structure as Risk::cronReviewreminder().
     * Finds policies whose next review date has passed or is within the reminder window and
     * raises a real GLPI Notification for each (see PolicyReviewReminderService,
     * inc/notificationtargetpolicy.class.php).
     *
     * @return int 0 if no policy was due, 1 otherwise
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new PolicyReviewReminderService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            __('%d policy(ies) awaiting review, %d notification(s) raised.', 'grcmanager'),
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
