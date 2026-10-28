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
use GlpiPlugin\Grcmanager\Compatibility\Base\PolicyBase;
use GlpiPlugin\Grcmanager\Services\Policy\CharterSignature;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Session;

/**
 * Security policy library (issue #28, ISO/IEC 27001:2022 A.5.1/A.5.1.1/A.5.1.2): the control
 * A.5.1 ("Politiques de sécurité de l'information") existed until now only as a checkbox row in
 * the SoA (PluginGrcmanagerControl), with no actual operational tool behind it. This class gives
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

    public static function getTypeName($nb = 0)
    {
        return _n('Security policy', 'Security policies', $nb, 'grcmanager');
    }

    /**
     * This class' primary display column is `title`, not GLPI's default `name` (which this table
     * doesn't even have). Front-page-bar bug found live: `front/policy.form.php` is this plugin's
     * only front controller that calls `displayFullPageForItem()` (needed so the native
     * "Documents" tab actually renders, see that file's own comment) — that path builds the page
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
            ->addStandardTab(Document_Item::class, $tabs, $options);

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
     * PluginGrcmanagerControl::prepareInputForAdd()/prepareInputForUpdate().
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateStatus(array $input)
    {
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
        if (array_key_exists('is_it_charter', $input)) {
            $input['is_it_charter'] = empty($input['is_it_charter']) ? 0 : 1;
        }

        return $input;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/policy_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'statuses'       => self::getStatuses(),
            'default_status' => PolicyLifecycle::STATUS_DRAFT,
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
            '%d politique(s) en attente de revue, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
