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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Config;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ContinuityPlanBase;
use GlpiPlugin\Grcmanager\Install\ContinuityPlanTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanDefaults;
use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use Group;
use Html;
use Log;
use Session;
use Supplier;
use User;

/**
 * Issue #1083 : plan de continuité ou de reprise, de quatre types : PCA et PRA (continuité et reprise
 * de l'activité, métier), PCI et PRI (continuité et reprise informatique). ISO 27001 A.5.29 et A.5.30.
 *
 * Le plan assemble les briques existantes au lieu de les recopier :
 * - processus métier couverts, avec leurs objectifs (DMIA/RTO, PDMA/RPO, durée maximale
 *   d'interruption) issus du BIA et leur dernier test de reprise (écart signalé au-delà du RTO) ;
 * - scénarios de crise et fournisseurs concernés ; cellule de crise et astreinte (groupes GLPI) ;
 * - contenu structuré : périmètre, déclenchement, rôles, étapes, repli, dépendances, communication.
 *
 * Cycle de vie (ContinuityPlanVersion) : version figée et approuvée par la direction, selon les règles
 * du circuit des politiques ; contenu non modifiable pendant l'approbation et après (une nouvelle
 * version le rouvre) ; date de revue calculée à l'approbation. Tests du plan (ContinuityPlanTesting) :
 * date, résultat, exercice de crise, écarts transformés en non-conformité à la demande.
 *
 * Droits : ceux de la continuité (Installer::CONTINUITY_RIGHT) et l'entité du plan. Un processus, un
 * scénario ou un fournisseur que la personne ne peut pas lire n'est jamais nommé. Aucun statut n'est
 * modifié automatiquement : seules les actions explicites du circuit changent le statut du plan.
 */
class ContinuityPlan extends ContinuityPlanBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const FK = 'plugin_grcmanager_continuityplans_id';

    /** Mesures de l'Annexe A dont les plans sont la preuve. */
    public const CONTROL_CODES = ['A.5.29', 'A.5.30'];

    /** Réglages (contexte de configuration du plugin). */
    public const REVIEW_MONTHS_KEY = 'continuity_plan_review_months';
    public const REMINDER_DAYS_KEY = 'continuity_plan_reminder_days';
    public const PREFILL_KEY = 'continuity_plan_prefill';

    /** Types d'éléments qu'un plan peut couvrir, et champ du formulaire de chacun. */
    public const LINKED_TYPES = [
        BusinessProcess::class => '_processes',
        CrisisScenario::class  => '_scenarios',
        Supplier::class        => '_suppliers',
    ];

    /** Contrôle interne : changement de statut demandé par le circuit d'approbation. */
    private const WORKFLOW_FLAG = '_continuity_plan_workflow';

    public static function getTable($classname = null)
    {
        return ContinuityPlanTables::PLANS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Continuity or recovery plan', 'Continuity and recovery plans', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-shield';
    }

    public function maybeRecursive()
    {
        return true;
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/continuityplan.php';
    }

    public static function getExportURL(int $planId, int $versionId = 0): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/continuityplan.export.php?id=' . $planId
            . ($versionId > 0 ? '&version=' . $versionId : '');
    }

    /** Sous-page de « Assistance > Crise et continuité », groupe « Se préparer ». */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(ContinuityPlanVersion::class, $tabs, $options)
            ->addStandardTab(ContinuityPlanTesting::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    // ------------------------------------------------------------------
    // Libellés
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            Rules::TYPE_PCA => __('Business continuity plan (BCP)', 'grcmanager'),
            Rules::TYPE_PRA => __('Business recovery plan (BRP)', 'grcmanager'),
            Rules::TYPE_PCI => __('IT continuity plan (ITCP)', 'grcmanager'),
            Rules::TYPE_PRI => __('IT disaster recovery plan (DRP)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeShortLabels(): array
    {
        return [
            Rules::TYPE_PCA => _x('plan type', 'BCP', 'grcmanager'),
            Rules::TYPE_PRA => _x('plan type', 'BRP', 'grcmanager'),
            Rules::TYPE_PCI => _x('plan type', 'ITCP', 'grcmanager'),
            Rules::TYPE_PRI => _x('plan type', 'DRP', 'grcmanager'),
        ];
    }

    /**
     * Statut du plan : libellé, classes du badge et icône.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function statusLabels(): array
    {
        return [
            Rules::STATUS_DRAFT    => [__('Draft', 'grcmanager'), 'bg-secondary-subtle text-secondary-emphasis', 'ti-pencil'],
            Rules::STATUS_PENDING  => [__('Awaiting approval', 'grcmanager'), 'bg-blue-subtle text-blue-emphasis', 'ti-hourglass'],
            Rules::STATUS_APPROVED => [__('Approved', 'grcmanager'), 'bg-green-subtle text-green-emphasis', 'ti-circle-check'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function gapLabels(): array
    {
        return [
            Rules::GAP_NOT_APPROVED     => __('Not approved', 'grcmanager'),
            Rules::GAP_REVIEW_OVERDUE   => __('Review overdue', 'grcmanager'),
            Rules::GAP_NEVER_TESTED     => __('Never tested', 'grcmanager'),
            Rules::GAP_TEST_OVERDUE     => __('Not tested for more than a year', 'grcmanager'),
            Rules::GAP_LAST_TEST_FAILED => __('Last test failed', 'grcmanager'),
            Rules::GAP_NO_PROCESS       => __('No covered process', 'grcmanager'),
            Rules::GAP_NO_CRISIS_TEAM   => __('No crisis team', 'grcmanager'),
            Rules::GAP_NO_PROCEDURE     => __('No recovery steps', 'grcmanager'),
            Rules::GAP_PERSON_LEFT      => __('A named person has left (inactive account)', 'grcmanager'),
        ];
    }

    /**
     * Libellés des champs texte du contenu, avec leur icône et leur aide.
     *
     * @return array<string, array{label: string, icon: string, hint: string}>
     */
    public static function textFieldLabels(): array
    {
        return [
            'scope'                => [
                'label' => __('Scope', 'grcmanager'),
                'icon'  => 'ti ti-map-2',
                'hint'  => __('Entities, services, sites and activities covered by the plan.', 'grcmanager'),
            ],
            'activation_criteria'  => [
                'label' => __('Activation criteria', 'grcmanager'),
                'icon'  => 'ti ti-bolt',
                'hint'  => __('Situations that trigger the plan, for example an unavailability longer than the RTO of a critical process.', 'grcmanager'),
            ],
            'activation_authority' => [
                'label' => __('Who activates the plan', 'grcmanager'),
                'icon'  => 'ti ti-user-check',
                'hint'  => __('Person or role allowed to activate the plan, and their deputy.', 'grcmanager'),
            ],
            'objectives'           => [
                'label' => __('Recovery objectives', 'grcmanager'),
                'icon'  => 'ti ti-target-arrow',
                'hint'  => __('The RTO and RPO of the covered processes come from their business impact analysis; add here the objectives of services that are not processes.', 'grcmanager'),
            ],
            'roles'                => [
                'label' => __('Roles and contacts', 'grcmanager'),
                'icon'  => 'ti ti-users-group',
                'hint'  => __('Who does what during the crisis, with internal and external contacts (suppliers, insurer, authorities).', 'grcmanager'),
            ],
            'procedures'           => [
                'label' => __('Steps of the plan', 'grcmanager'),
                'icon'  => 'ti ti-list-numbers',
                'hint'  => __('Numbered steps from activation to return to normal. Cite the response playbooks to follow.', 'grcmanager'),
            ],
            'fallback'             => [
                'label' => __('Fallback solutions', 'grcmanager'),
                'icon'  => 'ti ti-building-warehouse',
                'hint'  => __('Backup site, degraded mode, manual workarounds.', 'grcmanager'),
            ],
            'dependencies'         => [
                'label' => __('Dependencies and recovery order', 'grcmanager'),
                'icon'  => 'ti ti-sitemap',
                'hint'  => __('Applications, infrastructure and suppliers needed, in the order in which they are restored.', 'grcmanager'),
            ],
            'communication'        => [
                'label' => __('Communication', 'grcmanager'),
                'icon'  => 'ti ti-speakerphone',
                'hint'  => __('Who informs staff, customers, partners and authorities, and with which crisis message templates.', 'grcmanager'),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Réglages (Configuration > GRC Manager > Incidents et continuité)
    // ------------------------------------------------------------------

    /**
     * @return array{review_months: int, reminder_days: int, prefill: bool}
     */
    public static function settings(): array
    {
        $values = Config::getConfigurationValues(PolicyApprovalConfig::CONTEXT, [
            self::REVIEW_MONTHS_KEY, self::REMINDER_DAYS_KEY, self::PREFILL_KEY,
        ]);

        return [
            'review_months' => Rules::sanitizeReviewMonths(
                $values[self::REVIEW_MONTHS_KEY] ?? Rules::DEFAULT_REVIEW_MONTHS,
                false
            ),
            'reminder_days' => Rules::sanitizeReminderDays($values[self::REMINDER_DAYS_KEY] ?? Rules::DEFAULT_REMINDER_DAYS),
            'prefill'       => ($values[self::PREFILL_KEY] ?? '1') !== '0',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function saveSettings(array $input): void
    {
        $settings = [
            self::REVIEW_MONTHS_KEY => (string) Rules::sanitizeReviewMonths($input['review_months'] ?? 0, false),
            self::REMINDER_DAYS_KEY => (string) Rules::sanitizeReminderDays($input['reminder_days'] ?? 0),
            self::PREFILL_KEY       => empty($input['prefill']) ? '0' : '1',
        ];
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, $settings);
        AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $settings]);
    }

    // ------------------------------------------------------------------
    // Enregistrement
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        // Un plan naît en brouillon : seul le circuit d'approbation le fait changer de statut.
        $input['status'] = Rules::STATUS_DRAFT;
        unset($input['date_approved'], $input['next_review_date']);
        if (trim((string) ($input['version'] ?? '')) === '') {
            $input['version'] = '1.0';
        }

        return $this->normalizeInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        // Le droit est contrôlé sur l'entité actuelle du plan : un formulaire forgé ne doit pas
        // pouvoir l'envoyer dans une autre entité.
        unset($input['entities_id']);
        if (empty($input[self::WORKFLOW_FLAG])) {
            unset($input['status'], $input['date_approved'], $input['next_review_date']);
            $status = (string) ($this->fields['status'] ?? Rules::STATUS_DRAFT);
            if (Rules::isContentLocked($status)) {
                $changed = Rules::changedContentFields($this->fields, $input);
                if ($changed !== [] || $this->linksChanged($input)) {
                    Session::addMessageAfterRedirect(
                        htmlescape(__(
                            'This plan is awaiting approval or approved: its content is frozen. Prepare a new version in the Versions and approval tab to change it.',
                            'grcmanager'
                        )),
                        false,
                        ERROR
                    );

                    return false;
                }
            }
        }

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input)
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(htmlescape(__('The plan name is required.', 'grcmanager')), false, ERROR);

                return false;
            }
        }
        if (array_key_exists('plan_type', $input) && !Rules::isType((string) $input['plan_type'])) {
            $input['plan_type'] = Rules::TYPE_PCA;
        }
        if (array_key_exists('version', $input)) {
            $input['version'] = mb_substr(trim((string) $input['version']), 0, 16);
        }
        if (array_key_exists('review_months', $input)) {
            $input['review_months'] = Rules::sanitizeReviewMonths($input['review_months']);
        }
        foreach (['users_id', 'users_id_approver', 'groups_id_crisis', 'groups_id_oncall'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = max(0, (int) $input[$field]);
            }
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->applyLinkInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if (empty($this->input[self::WORKFLOW_FLAG])) {
            $this->applyLinkInput();
        }
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $planId = (int) $this->getID();
        $DB->delete(ContinuityPlanTables::ITEMS, [self::FK => $planId]);
        ContinuityPlanVersion::purgePlan($planId);
        $DB->delete(ContinuityPlanTables::TESTS, [self::FK => $planId]);
    }

    /**
     * Changement de statut par le circuit d'approbation (seul chemin vers « Approuvé »).
     *
     * @param array<string, mixed> $fields
     */
    public function workflowUpdate(array $fields): bool
    {
        return (bool) $this->update(['id' => (int) $this->getID(), self::WORKFLOW_FLAG => true] + $fields);
    }

    /**
     * La saisie change-t-elle les éléments couverts (champs `_processes`... avec `_links_sent`) ?
     *
     * @param array<string, mixed> $input
     */
    private function linksChanged(array $input): bool
    {
        if (empty($input['_links_sent'])) {
            return false;
        }
        $current = $this->linkedIds();
        foreach (self::LINKED_TYPES as $itemtype => $field) {
            $wanted = self::idsFrom($input[$field] ?? []);
            $visible = array_values(array_filter(
                $current[$itemtype] ?? [],
                fn (int $id): bool => $this->itemAllowed($itemtype, $id)
            ));
            sort($wanted);
            sort($visible);
            if ($wanted !== $visible) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function idsFrom(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $value),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * Éléments liés, par type.
     *
     * @return array<string, list<int>>
     */
    public function linkedIds(): array
    {
        global $DB;

        $ids = [];
        if ($this->isNewItem()) {
            return $ids;
        }
        foreach ($DB->request(['FROM' => ContinuityPlanTables::ITEMS, 'WHERE' => [self::FK => (int) $this->getID()]]) as $row) {
            $ids[(string) $row['itemtype']][] = (int) $row['items_id'];
        }

        return $ids;
    }

    /**
     * Éléments couverts choisis dans le formulaire. Seuls les liens vers des éléments que la personne
     * peut lire sont retirés ou ajoutés ; un élément hors de l'entité du plan est refusé et signalé.
     */
    private function applyLinkInput(): void
    {
        global $DB;

        if (empty($this->input['_links_sent'])) {
            return;
        }
        $planId = (int) $this->getID();
        $current = [];
        foreach ($DB->request(['FROM' => ContinuityPlanTables::ITEMS, 'WHERE' => [self::FK => $planId]]) as $row) {
            $current[(string) $row['itemtype']][(int) $row['items_id']] = (int) $row['id'];
        }
        $refused = false;
        foreach (self::LINKED_TYPES as $itemtype => $field) {
            $wanted = self::idsFrom($this->input[$field] ?? []);
            foreach ($current[$itemtype] ?? [] as $itemsId => $linkId) {
                if (!in_array($itemsId, $wanted, true) && $this->itemAllowed($itemtype, $itemsId)) {
                    $DB->delete(ContinuityPlanTables::ITEMS, ['id' => $linkId]);
                }
            }
            foreach ($wanted as $itemsId) {
                if (isset($current[$itemtype][$itemsId])) {
                    continue;
                }
                if (!$this->itemAllowed($itemtype, $itemsId)) {
                    $refused = true;
                    continue;
                }
                $DB->insert(ContinuityPlanTables::ITEMS, [
                    self::FK        => $planId,
                    'itemtype'      => $itemtype,
                    'items_id'      => $itemsId,
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        if ($refused) {
            Session::addMessageAfterRedirect(
                htmlescape(__('An element outside the entity of the plan was not linked.', 'grcmanager')),
                false,
                ERROR
            );
        }
    }

    /** Élément lisible par la personne et visible depuis l'entité du plan. */
    public function itemAllowed(string $itemtype, int $itemsId): bool
    {
        if ($itemsId <= 0 || !isset(self::LINKED_TYPES[$itemtype]) || !$itemtype::canView()) {
            return false;
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return false;
        }
        if (!empty($item->fields['is_deleted'])) {
            return false;
        }
        $planEntity = (int) ($this->fields['entities_id'] ?? Session::getActiveEntity());
        $itemEntity = (int) ($item->fields['entities_id'] ?? 0);

        return CrisisScenarioRules::processInScope(
            $itemEntity,
            !empty($item->fields['is_recursive']),
            $planEntity,
            !empty($this->fields['is_recursive']),
            array_map('intval', array_values(getAncestorsOf('glpi_entities', $planEntity))),
            array_map('intval', array_values(getAncestorsOf('glpi_entities', $itemEntity)))
        );
    }

    /**
     * Éléments proposés dans le formulaire pour un type : lisibles et visibles depuis l'entité du plan.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @return array<int, string>
     */
    public function linkChoices(string $itemtype): array
    {
        global $DB;

        $choices = [];
        if (!isset(self::LINKED_TYPES[$itemtype]) || !$itemtype::canView()) {
            return $choices;
        }
        $table = $itemtype::getTable();
        $where = getEntitiesRestrictCriteria($table, '', '', true);
        if ((new $itemtype())->maybeDeleted()) {
            $where['is_deleted'] = 0;
        }
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => $where,
                'ORDER'  => 'name',
                'LIMIT'  => 500,
            ]) as $row
        ) {
            if ($this->itemAllowed($itemtype, (int) $row['id'])) {
                $choices[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $choices;
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Processus couverts, lisibles : objectifs du BIA et dernier test de reprise (écart au-delà du RTO).
     *
     * @return list<array<string, mixed>>
     */
    public function coveredProcesses(): array
    {
        $durations = BusinessProcess::getDurationOptions();
        $processes = [];
        $process = new BusinessProcess();
        foreach ($this->linkedIds()[BusinessProcess::class] ?? [] as $processId) {
            if (!BusinessProcess::canView() || !$process->getFromDB($processId) || !$process->canViewItem()) {
                continue;
            }
            $rto = (int) $process->fields['rto_hours'];
            $test = BusinessProcessRecovery::lastTest($processId);
            $minutes = $test === null ? 0 : (int) $test['measured_minutes'];
            $processes[] = [
                'id'            => $processId,
                'name'          => (string) $process->fields['name'],
                'url'           => BusinessProcess::getFormURLWithID($processId),
                'critical'      => CrisisScenarioRules::isCritical($process->fields),
                'mtpd'          => $durations[(int) $process->fields['mtpd_hours']] ?? '',
                'rto'           => $durations[$rto] ?? '',
                'rpo'           => $durations[(int) $process->fields['rpo_hours']] ?? '',
                'last_test'     => $test === null ? '' : (string) $test['test_date'],
                'test_duration' => $test === null ? '' : BusinessProcessRecovery::formatMinutes($minutes),
                'test_exceeds'  => $test !== null && RecoveryStrategyRules::exceedsRto($rto, $minutes),
            ];
        }
        usort($processes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $processes;
    }

    /**
     * Scénarios de crise et fournisseurs couverts, lisibles.
     *
     * @return array{scenarios: list<array{name: string, url: string}>, suppliers: list<array{name: string, url: string}>}
     */
    public function coveredOthers(): array
    {
        $result = ['scenarios' => [], 'suppliers' => []];
        $linked = $this->linkedIds();
        foreach ([CrisisScenario::class => 'scenarios', Supplier::class => 'suppliers'] as $itemtype => $key) {
            if (!$itemtype::canView()) {
                continue;
            }
            $item = new $itemtype();
            foreach ($linked[$itemtype] ?? [] as $itemsId) {
                if ($item->getFromDB($itemsId) && $item->canViewItem()) {
                    $result[$key][] = ['name' => (string) $item->fields['name'], 'url' => $itemtype::getFormURLWithID($itemsId)];
                }
            }
            usort($result[$key], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }

        return $result;
    }

    /**
     * Un responsable ou un approbateur désigné a-t-il quitté l'organisation (compte inactif ou supprimé) ?
     *
     * @param array<string, mixed> $row
     */
    private static function personLeft(array $row): bool
    {
        $user = new User();
        foreach (['users_id', 'users_id_approver'] as $field) {
            $id = (int) ($row[$field] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!$user->getFromDB($id) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plans des entités de la session (hors corbeille), présentés : type, statut, revue, dernier test,
     * manques.
     *
     * @return list<array<string, mixed>>
     */
    public static function register(?string $today = null): array
    {
        global $DB;

        $today ??= date('Y-m-d');
        $rows = [];
        if (!self::canView() || !$DB->tableExists(self::getTable())) {
            return $rows;
        }
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => array_merge(['is_deleted' => 0], EntityPerimeter::criteria(self::getTable())),
                'ORDER' => ['plan_type', 'name'],
            ]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }
        if ($rows === []) {
            return [];
        }
        $processCount = [];
        $testsByPlan = [];
        foreach (
            $DB->request([
                'FROM'  => ContinuityPlanTables::ITEMS,
                'WHERE' => [self::FK => array_keys($rows), 'itemtype' => BusinessProcess::class],
            ]) as $link
        ) {
            $processCount[(int) $link[self::FK]] = ($processCount[(int) $link[self::FK]] ?? 0) + 1;
        }
        foreach ($DB->request(['FROM' => ContinuityPlanTables::TESTS, 'WHERE' => [self::FK => array_keys($rows)]]) as $test) {
            $testsByPlan[(int) $test[self::FK]][] = $test;
        }
        $settings = self::settings();
        $types = self::typeShortLabels();
        $typeLabels = self::typeLabels();
        $statuses = self::statusLabels();
        $gapLabels = self::gapLabels();
        $results = ContinuityPlanTesting::resultLabels();
        $presented = [];
        foreach ($rows as $id => $row) {
            $last = Rules::lastTest($testsByPlan[$id] ?? []);
            $testStatus = Rules::testStatus($last['test_date'] ?? null, $today);
            $reviewStatus = Rules::reviewStatus($row['next_review_date'] ?? null, $today, $settings['reminder_days']);
            $lastResult = $last === null ? '' : (string) $last['result'];
            $gaps = Rules::gaps($row, [
                'test_status'   => $testStatus,
                'last_result'   => $lastResult,
                'review_status' => $reviewStatus,
                'process_count' => $processCount[$id] ?? 0,
                'person_left'   => self::personLeft($row),
            ]);
            $status = $statuses[(string) $row['status']] ?? $statuses[Rules::STATUS_DRAFT];
            $presented[] = [
                'id'             => $id,
                'url'            => self::getFormURLWithID($id),
                'name'           => (string) $row['name'],
                'type'           => (string) $row['plan_type'],
                'type_short'     => $types[(string) $row['plan_type']] ?? '',
                'type_label'     => $typeLabels[(string) $row['plan_type']] ?? '',
                'is_it'          => Rules::isItPlan((string) $row['plan_type']),
                'version'        => (string) $row['version'],
                'status'         => (string) $row['status'],
                'status_label'   => $status[0],
                'status_class'   => $status[1],
                'status_icon'    => $status[2],
                'entity'         => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'owner'          => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'date_approved'  => (string) ($row['date_approved'] ?? ''),
                'next_review'    => (string) ($row['next_review_date'] ?? ''),
                'review_status'  => $reviewStatus,
                'last_test'      => $last === null ? '' : (string) $last['test_date'],
                'last_result'    => $lastResult,
                'result_label'   => $results[$lastResult] ?? null,
                'test_status'    => $testStatus,
                'process_count'  => $processCount[$id] ?? 0,
                'gaps'           => $gaps,
                'gap_labels'     => array_map(static fn (string $gap): string => $gapLabels[$gap] ?? $gap, $gaps),
                'awaiting_me'    => (string) $row['status'] === Rules::STATUS_PENDING
                    && ContinuityPlanVersion::awaitsUser($id, (int) Session::getLoginUserID()),
            ];
        }

        return $presented;
    }

    /**
     * Processus lisibles et nombre de plans (hors corbeille) qui couvrent chacun.
     *
     * @return array{processes: list<array<string, mixed>>, counts: array<int, int>}
     */
    private static function processCoverage(): array
    {
        global $DB;

        $processes = [];
        $counts = [];
        if (!BusinessProcess::canView() || !$DB->tableExists(self::getTable())) {
            return ['processes' => $processes, 'counts' => $counts];
        }
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'rto_hours'],
                'FROM'   => BusinessProcess::getTable(),
                'WHERE'  => EntityPerimeter::criteria(BusinessProcess::getTable()),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $processes[] = $row;
        }
        $links = ContinuityPlanTables::ITEMS;
        $plans = self::getTable();
        foreach (
            $DB->request([
                'SELECT'     => ["$links.items_id"],
                'FROM'       => $links,
                'INNER JOIN' => [$plans => ['ON' => [$links => self::FK, $plans => 'id']]],
                'WHERE'      => array_merge(
                    ["$links.itemtype" => BusinessProcess::class, "$plans.is_deleted" => 0],
                    EntityPerimeter::criteria($plans)
                ),
            ]) as $row
        ) {
            $counts[(int) $row['items_id']] = ($counts[(int) $row['items_id']] ?? 0) + 1;
        }

        return ['processes' => $processes, 'counts' => $counts];
    }

    /**
     * Indicateurs et processus critiques sans plan.
     *
     * @param list<array<string, mixed>> $plans plans présentés (register())
     * @return array{summary: array<string, int>, uncovered: list<array{name: string, url: string, rto: string}>}
     */
    public static function coverage(array $plans): array
    {
        $coverage = self::processCoverage();
        $durations = BusinessProcess::getDurationOptions();
        $uncovered = [];
        foreach (CrisisScenarioRules::uncoveredCriticalProcesses($coverage['processes'], $coverage['counts']) as $row) {
            $uncovered[] = [
                'name' => (string) $row['name'],
                'url'  => BusinessProcess::getFormURLWithID((int) $row['id']),
                'rto'  => $durations[(int) $row['rto_hours']] ?? '',
            ];
        }

        return [
            'summary'   => Rules::summary($plans, $coverage['processes'], $coverage['counts']),
            'uncovered' => $uncovered,
        ];
    }

    /**
     * Contenu du plan tel qu'il est figé à la demande d'approbation (et imprimé en PDF) : textes,
     * personnes et groupes nommés, éléments couverts lisibles avec leurs objectifs.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $fields = $this->fields;
        $snapshot = [
            'name'        => (string) $fields['name'],
            'plan_type'   => (string) $fields['plan_type'],
            'type_label'  => self::typeLabels()[(string) $fields['plan_type']] ?? '',
            'version'     => (string) $fields['version'],
            'entity'      => (string) Dropdown::getDropdownName('glpi_entities', (int) $fields['entities_id']),
            'owner'       => (int) $fields['users_id'] > 0 ? (string) getUserName((int) $fields['users_id']) : '',
            'crisis_team' => (int) $fields['groups_id_crisis'] > 0
                ? (string) Dropdown::getDropdownName(Group::getTable(), (int) $fields['groups_id_crisis']) : '',
            'oncall'      => (int) $fields['groups_id_oncall'] > 0
                ? (string) Dropdown::getDropdownName(Group::getTable(), (int) $fields['groups_id_oncall']) : '',
            'oncall_members' => self::groupMembers((int) $fields['groups_id_oncall']),
        ];
        foreach (Rules::TEXT_FIELDS as $field) {
            $snapshot[$field] = (string) ($fields[$field] ?? '');
        }
        $snapshot['processes'] = array_map(static fn (array $process): array => [
            'name'     => $process['name'],
            'critical' => $process['critical'],
            'mtpd'     => $process['mtpd'],
            'rto'      => $process['rto'],
            'rpo'      => $process['rpo'],
        ], $this->coveredProcesses());
        $others = $this->coveredOthers();
        $snapshot['scenarios'] = array_column($others['scenarios'], 'name');
        $snapshot['suppliers'] = array_column($others['suppliers'], 'name');

        return $snapshot;
    }

    /**
     * Membres actifs d'un groupe (astreinte), par nom.
     *
     * @return list<string>
     */
    private static function groupMembers(int $groupId): array
    {
        global $DB;

        if ($groupId <= 0) {
            return [];
        }
        $names = [];
        foreach (
            $DB->request([
                'SELECT'     => ['glpi_users.id'],
                'FROM'       => 'glpi_groups_users',
                'INNER JOIN' => ['glpi_users' => ['ON' => ['glpi_groups_users' => 'users_id', 'glpi_users' => 'id']]],
                'WHERE'      => [
                    'glpi_groups_users.groups_id' => $groupId,
                    'glpi_users.is_active'        => 1,
                    'glpi_users.is_deleted'       => 0,
                ],
            ]) as $row
        ) {
            $names[] = (string) getUserName((int) $row['id']);
        }
        sort($names, SORT_STRING);

        return $names;
    }

    // ------------------------------------------------------------------
    // Écrans
    // ------------------------------------------------------------------

    /** Page de la liste : indicateurs, processus critiques sans plan, plans et leurs manques. */
    public static function showRegister(): void
    {
        $plans = self::register();
        TemplateRenderer::getInstance()->display('@grcmanager/continuity_plan_register.html.twig', [
            'plans'        => $plans,
            'coverage'     => self::coverage($plans),
            'can_create'   => self::canCreate(),
            'add_url'      => self::getFormURL(),
            'types'        => self::typeLabels(),
            'controls'     => array_map(
                static fn (string $code): string => $code . ' ' . Control::getControlTitle($code),
                self::CONTROL_CODES
            ),
        ]);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $isNew = $this->isNewItem();
        $settings = self::settings();
        if ($isNew) {
            $type = (string) ($_GET['plan_type'] ?? Rules::TYPE_PCA);
            $this->fields['plan_type'] = Rules::isType($type) ? $type : Rules::TYPE_PCA;
            $this->fields['version'] = '1.0';
            $this->fields['status'] = Rules::STATUS_DRAFT;
            if ($settings['prefill']) {
                foreach (ContinuityPlanDefaults::texts($this->fields['plan_type']) as $field => $text) {
                    if (trim((string) ($this->fields[$field] ?? '')) === '') {
                        $this->fields[$field] = $text;
                    }
                }
            }
        }
        $presented = null;
        if (!$isNew) {
            foreach (self::register() as $row) {
                if ($row['id'] === (int) $ID) {
                    $presented = $row;
                }
            }
        }
        $status = (string) ($this->fields['status'] ?? Rules::STATUS_DRAFT);
        $linked = $this->linkedIds();
        $choices = [];
        foreach (self::LINKED_TYPES as $itemtype => $field) {
            $choices[$field] = [
                'visible' => $itemtype::canView(),
                'choices' => $this->linkChoices($itemtype),
                'values'  => array_values(array_filter(
                    $linked[$itemtype] ?? [],
                    fn (int $id): bool => $this->itemAllowed($itemtype, $id)
                )),
            ];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/continuity_plan_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'types'         => self::typeLabels(),
            'text_fields'   => self::textFieldLabels(),
            'links'         => $choices,
            'locked'        => !$isNew && Rules::isContentLocked($status),
            'status'        => self::statusLabels()[$status] ?? self::statusLabels()[Rules::STATUS_DRAFT],
            'plan'          => $presented,
            'processes'     => $isNew ? [] : $this->coveredProcesses(),
            'review_months' => $settings['review_months'],
            'export_url'    => $isNew ? '' : self::getExportURL((int) $ID),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Onglet « Plans de continuité » d'un processus métier
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            count(self::plansForProcess($item)),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_continuity_plans.html.twig', [
            'plans'      => self::plansForProcess($item),
            'list_url'   => self::getSearchURL(),
            'critical'   => CrisisScenarioRules::isCritical($item->fields),
        ]);

        return true;
    }

    /**
     * Plans lisibles (hors corbeille) qui couvrent le processus.
     *
     * @return list<array{name: string, url: string, type: string, status_label: string, status_class: string}>
     */
    public static function plansForProcess(CommonDBTM $process): array
    {
        global $DB;

        $plans = [];
        $plan = new self();
        $types = self::typeShortLabels();
        $statuses = self::statusLabels();
        foreach (
            $DB->request([
                'FROM'  => ContinuityPlanTables::ITEMS,
                'WHERE' => ['itemtype' => BusinessProcess::class, 'items_id' => (int) $process->getID()],
            ]) as $link
        ) {
            if (!$plan->getFromDB((int) $link[self::FK]) || !$plan->canViewItem() || !empty($plan->fields['is_deleted'])) {
                continue;
            }
            $status = $statuses[(string) $plan->fields['status']] ?? $statuses[Rules::STATUS_DRAFT];
            $plans[] = [
                'name'         => (string) $plan->fields['name'],
                'url'          => self::getFormURLWithID((int) $plan->getID()),
                'type'         => $types[(string) $plan->fields['plan_type']] ?? '',
                'version'      => (string) $plan->fields['version'],
                'status_label' => $status[0],
                'status_class' => $status[1],
            ];
        }
        usort($plans, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $plans;
    }

    /** Un élément purgé (processus, scénario, fournisseur) quitte les plans. */
    public static function forgetItem(string $itemtype, int $itemsId): void
    {
        global $DB;

        if ($DB->tableExists(ContinuityPlanTables::ITEMS)) {
            $DB->delete(ContinuityPlanTables::ITEMS, ['itemtype' => $itemtype, 'items_id' => $itemsId]);
        }
    }

    // ------------------------------------------------------------------
    // Preuve des mesures A.5.29 et A.5.30 (SoA)
    // ------------------------------------------------------------------

    /**
     * Preuve affichée sur la fiche des mesures A.5.29 et A.5.30 : plans par type, approuvés, testés
     * dans l'année, revue dépassée. Null pour les autres mesures ou sans le droit de la continuité.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CONTROL_CODES, true) || !self::canView()) {
            return null;
        }
        $plans = self::register();
        $byType = [];
        foreach (self::typeShortLabels() as $type => $label) {
            $byType[] = [
                'label' => $label,
                'count' => count(array_filter($plans, static fn (array $plan): bool => $plan['type'] === $type)),
            ];
        }
        $coverage = self::coverage($plans);

        return $coverage['summary'] + [
            'by_type' => $byType,
            'url'       => self::getSearchURL(),
            'plan_list' => array_map(static fn (array $plan): array => [
                'name'         => $plan['name'],
                'url'          => $plan['url'],
                'type_short'   => $plan['type_short'],
                'status_label' => $plan['status_label'],
                'status_class' => $plan['status_class'],
                'last_test'    => $plan['last_test'],
            ], array_slice($plans, 0, 10)),
        ];
    }

    // ------------------------------------------------------------------
    // PDF (impression hors ligne : un PRA doit rester lisible sans GLPI)
    // ------------------------------------------------------------------

    /**
     * Plan en HTML pour PdfRenderer : contenu actuel, ou contenu figé d'une version soumise.
     */
    public function pdfHtml(int $versionId = 0): string
    {
        $planId = (int) $this->getID();
        $version = $versionId > 0 ? ContinuityPlanVersion::getVersion($planId, $versionId) : null;
        $snapshot = $version !== null ? $version['snapshot'] : $this->snapshot();
        $statuses = self::statusLabels();

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/continuity_plan.html.twig', [
            'report_title'       => sprintf('%s : %s', $snapshot['type_label'] ?? '', $snapshot['name'] ?? ''),
            'generated_at_label' => sprintf(
                __('Generated on %1$s by %2$s', 'grcmanager'),
                Html::convDateTime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                getUserName((int) Session::getLoginUserID())
            ),
            'plan'               => $snapshot,
            'frozen'             => $version,
            'status_label'       => ($statuses[(string) $this->fields['status']] ?? $statuses[Rules::STATUS_DRAFT])[0],
            'date_approved'      => (string) ($this->fields['date_approved'] ?? ''),
            'next_review'        => (string) ($this->fields['next_review_date'] ?? ''),
            'text_fields'        => self::textFieldLabels(),
            'history'            => ContinuityPlanVersion::present($planId),
            'tests'              => ContinuityPlanTesting::forPlan($this),
            'show_contacts'      => OfficialContact::canView(),
            'contacts'           => OfficialContact::forContinuityPlan($this),
            'perimeter'          => EntityPerimeter::isMultiEntity(),
            'controls'           => array_map(
                static fn (string $code): string => $code . ' ' . Control::getControlTitle($code),
                self::CONTROL_CODES
            ),
        ]);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => $table, 'field' => 'plan_type', 'name' => __('Type'),
            'datatype' => 'specific', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'version', 'name' => __('Version', 'grcmanager'),
            'datatype' => 'string', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'status', 'name' => __('Status'),
            'datatype' => 'specific', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'date_approved', 'name' => __('Approval date', 'grcmanager'),
            'datatype' => 'date', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'next_review_date', 'name' => __('Next review', 'grcmanager'),
            'datatype' => 'date', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Owner', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 9, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'plan_type') {
            return htmlescape(self::typeLabels()[(string) $values[$field]] ?? (string) $values[$field]);
        }
        if ($field === 'status') {
            return htmlescape((self::statusLabels()[(string) $values[$field]] ?? [(string) $values[$field]])[0]);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
