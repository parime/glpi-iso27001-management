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

use Glpi\ContentTemplates\Parameters\CommonITILObjectParameters;
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentBase;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentParameters;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;

/**
 * A security incident (unauthorized access, data leak, malware, phishing...), as its own native
 * GLPI ITIL object alongside Ticket/Problem/Change — not a Ticket sub-type, since a security
 * incident has its own actors/workflow/notifications and belongs in a dedicated register rather
 * than mixed into the general helpdesk queue. Absorbed from the sibling plugin
 * glpi-security-incidents (ROADMAP.md "Version 2.0", now archived) into this plugin as one merged
 * object: the operational ITIL workflow (actors, tasks, notifications, CVE tracking) AND the
 * ISO/IEC 27001:2022 Annexe A A.5.24-27 compliance fields (`category`/`severity`/`cia_impact`/
 * `root_cause`/`lessons_learned`/link to a PluginGrcmanagerRisk) that used to live on a separate,
 * lightweight `PluginGrcmanagerSecurityIncident` CommonDBTM register (issue #29) — ONE record per
 * real-world incident now, not two that could drift apart or be double-entered. The ISO
 * classification fields render in their own accordion via `Hooks::POST_ITIL_INFO_SECTION` (see
 * `plugin_grcmanager_post_itil_info_section()` in hook.php), alongside the pre-existing "Analyse"
 * accordion (`impact_content`/`control_list_content`/`rollback_plan_content`) also absorbed from
 * the same plugin.
 *
 * Legacy global-namespace `PluginXxxYyy` convention (not this plugin's own PSR-4
 * `GlpiPlugin\Grcmanager\*`, used everywhere else in this plugin) — deliberate, not an oversight:
 * several GLPI core mechanisms that a `CommonITILObject` subclass must hook into derive internal
 * identifiers straight from `strtolower(static::class)` with no namespace-awareness (confirmed the
 * hard way on the original plugin — `CommonITILObject::getITILTemplateToUse()` tried to `SELECT` a
 * column literally named after the PSR-4 namespace when this class was namespaced).
 *
 * Which parts of this object are exposed is controlled by `SecurityIncidentModuleConfig` (see
 * front/config.php): the ISO classification fields and the base ITIL object itself are never
 * gated (they're the reason this class exists at all), but the CVE tab, incident templates, and
 * dashboard cards can each be turned off independently.
 *
 * Modeled directly on GLPI core's own `Change` class (the closest native analogue).
 */
class PluginGrcmanagerSecurityIncident extends SecurityIncidentBase
{
    public const USERLINKCLASS = PluginGrcmanagerSecurityIncident_User::class;
    public const GROUPLINKCLASS = PluginGrcmanagerSecurityIncident_Group::class;
    public const SUPPLIERLINKCLASS = PluginGrcmanagerSecurityIncident_Supplier::class;
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTypeName($nb = 0)
    {
        return _n('Security incident', 'Security incidents', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-exclamation';
    }

    /**
     * Translated labels for the ISO classification fields absorbed from the plugin's former
     * lightweight register (issue #29) — kept as static methods here (not constants, and not on
     * the GLPI-independent SecurityIncidentRules) since `__()` calls aren't compile-time constant
     * expressions.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            'data_breach'         => __('Data breach', 'grcmanager'),
            'malware'             => __('Malware', 'grcmanager'),
            'unauthorized_access' => __('Unauthorized access', 'grcmanager'),
            'availability'        => __('Unavailability / denial of service', 'grcmanager'),
            'other'               => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSeverities(): array
    {
        return [
            'minor'    => __('Minor', 'grcmanager'),
            'major'    => __('Major', 'grcmanager'),
            'critical' => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string> label per CIA axis, in SecurityIncidentRules::CIA_AXES order.
     */
    public static function getCiaAxisLabels(): array
    {
        return [
            'confidentiality' => __('Confidentiality', 'grcmanager'),
            'integrity'       => __('Integrity', 'grcmanager'),
            'availability'    => __('Availability', 'grcmanager'),
        ];
    }

    public static function getSectorizedDetails(): array
    {
        return ['helpdesk', self::class];
    }

    /**
     * `CommonITILObject`'s own status-array methods explicitly say "to be overridden by class" and
     * default to an empty array — confirmed the hard way on the original plugin:
     * `handleNewItemNotifications()` fataled with "Empty IN are not allowed"
     * (`getSolvedStatusArray()`/`getClosedStatusArray()` both `[]`, merged into a `NOT IN ()` SQL
     * clause) the first time a real incident was created after notifications were wired up. Uses
     * only the base, universally-shared lifecycle constants (`INCOMING`/`ASSIGNED`/`PLANNED`/
     * `WAITING`/`SOLVED`/`CLOSED`, defined on `CommonITILObject` itself) rather than `Change`'s own
     * richer set — a security incident's workflow doesn't need change-specific approval/testing/
     * rollback states.
     */
    public static function getAllStatusArray($withmetaforsearch = false)
    {
        $status = [
            self::INCOMING => _x('status', 'New'),
            self::ASSIGNED => __('Processing (assigned)'),
            self::PLANNED  => __('Processing (planned)'),
            self::WAITING  => __('Pending'),
            self::SOLVED   => __('Solved'),
            self::CLOSED   => _x('status', 'Closed'),
        ];

        if ($withmetaforsearch) {
            $status['notold']    = _x('status', 'Not solved');
            $status['notclosed'] = _x('status', 'Not closed');
            $status['process']   = __('Processing');
            $status['old']       = _x('status', 'Solved + Closed');
            $status['all']       = __('All');
        }

        return $status;
    }

    public static function getClosedStatusArray()
    {
        return [self::CLOSED];
    }

    public static function getSolvedStatusArray()
    {
        return [self::SOLVED];
    }

    public static function getNewStatusArray()
    {
        return [self::INCOMING];
    }

    public static function getProcessStatusArray()
    {
        return [self::ASSIGNED, self::PLANNED];
    }

    public static function getDefaultValues($entity = 0)
    {
        $usersId         = is_numeric(Session::getLoginUserID(false)) ? Session::getLoginUserID() : 0;
        $defaultUseNotif = Entity::getUsedConfig('is_notif_enable_default', $_SESSION['glpiactive_entity'] ?? 0, '', 1);

        return [
            '_users_id_requester'        => $usersId,
            '_users_id_requester_notif'  => ['use_notification' => $defaultUseNotif, 'alternative_email' => ''],
            '_groups_id_requester'       => 0,
            '_users_id_assign'           => 0,
            '_users_id_assign_notif'     => ['use_notification' => $defaultUseNotif, 'alternative_email' => ''],
            '_groups_id_assign'          => 0,
            '_users_id_observer'         => 0,
            '_users_id_observer_notif'   => ['use_notification' => $defaultUseNotif, 'alternative_email' => ''],
            '_groups_id_observer'        => 0,
            '_suppliers_id_assign'       => 0,
            '_suppliers_id_assign_notif' => ['use_notification' => $defaultUseNotif, 'alternative_email' => ''],
            'priority'                   => 3,
            'urgency'                    => 3,
            'impact'                     => 3,
            'content'                    => '',
            'entities_id'                => $_SESSION['glpiactive_entity'] ?? 0,
            'name'                       => '',
            'itilcategories_id'          => 0,
            'actiontime'                 => 0,
            'date'                       => 'NULL',
            '_add_validation'            => 0,
            '_validation_targets'        => [],
            '_tasktemplates_id'          => [],
            'impact_content'             => '',
            'control_list_content'       => '',
            'rollback_plan_content'      => '',
            'items_id'                   => 0,
            '_actors'                    => [],
            'status'                     => self::INCOMING,
            'time_to_resolve'            => 'NULL',
            'itemtype'                   => '',
            'locations_id'               => 0,
            // ISO/IEC 27001:2022 Annexe A A.5.24-27 classification, absorbed from the plugin's own
            // former lightweight register (issue #29) — see SecurityIncidentRules.
            'category'                   => SecurityIncidentRules::DEFAULT_CATEGORY,
            'severity'                   => SecurityIncidentRules::DEFAULT_SEVERITY,
            'cia_impact'                 => '',
            'root_cause'                 => '',
            'lessons_learned'            => '',
            'plugin_grcmanager_risks_id' => 0,
        ];
    }

    public static function getItemLinkClass(): string
    {
        return PluginGrcmanagerSecurityIncident_Item::class;
    }

    /**
     * Required, not optional — same class of surprise as `getRuleCollectionClassInstance()` (see
     * `RulePluginGrcmanagerSecurityIncidentCollection`'s own docblock): unlike most
     * `CommonITILObject` extension points, this one is a hardcoded `switch` in core listing only
     * `Ticket`/`Change`/`Problem`, with no generic fallback and no way to register a plugin's own
     * itemtype from outside. Not abstract, so overriding it here is enough — no core patch needed.
     */
    public static function getItemsTable()
    {
        return PluginGrcmanagerSecurityIncident_Item::getTable();
    }

    public static function getContentTemplatesParametersClassInstance(): CommonITILObjectParameters
    {
        return new SecurityIncidentParameters();
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights();
        unset($values[READ]);

        $values[self::READALL] = __('See all');
        $values[self::READMY]  = __('See (author)');

        return $values;
    }

    public static function canView(): bool
    {
        return Session::haveRightsOr(self::$rightname, [self::READALL, self::READMY]);
    }

    public function canViewItem(): bool
    {
        if (!$this->checkEntity(true)) {
            return false;
        }

        return Session::haveRight(self::$rightname, self::READALL)
            || (Session::haveRight(self::$rightname, self::READMY)
                && ($this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
                    || $this->isUser(CommonITILActor::OBSERVER, Session::getLoginUserID())
                    || (isset($_SESSION['glpigroups'])
                        && ($this->haveAGroup(CommonITILActor::REQUESTER, $_SESSION['glpigroups'])
                            || $this->haveAGroup(CommonITILActor::OBSERVER, $_SESSION['glpigroups'])))
                    || $this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
                    || (isset($_SESSION['glpigroups'])
                        && $this->haveAGroup(CommonITILActor::ASSIGN, $_SESSION['glpigroups']))));
    }

    public function canCreateItem(): bool
    {
        if (!Session::haveAccessToEntity($this->getEntityID())) {
            return false;
        }

        return Session::haveRight(self::$rightname, CREATE);
    }

    public function canSolve()
    {
        return self::isAllowedStatus($this->fields['status'], self::SOLVED)
            && !in_array($this->fields['status'], static::getClosedStatusArray(), true)
            && (Session::haveRight(self::$rightname, UPDATE)
                || (Session::haveRight(self::$rightname, self::READMY)
                    && ($this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
                        || (isset($_SESSION['glpigroups'])
                            && $this->haveAGroup(CommonITILActor::ASSIGN, $_SESSION['glpigroups'])))));
    }

    /**
     * No separate "Analysis"/"Classification" tab for either the impact/controls/rollback fields
     * or the ISO category/severity/CIA/root-cause fields: both render directly in the main field
     * panel via `Hooks::POST_ITIL_INFO_SECTION` (two accordions, see hook.php), exactly where
     * `Change`'s own native "Analysis" accordion lives. Keeping either as a separate tab would show
     * the same fields editable in two different places.
     *
     * The CVE tab is conditioned on `SecurityIncidentModuleConfig` (issue: absorption toggles) so
     * an administrator who doesn't track CVEs can hide it entirely rather than see an always-empty
     * tab.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);

        if (SecurityIncidentModuleConfig::load()['securityincident_cve_enabled']) {
            $this->addStandardTab(PluginGrcmanagerSecurityIncidentCve::class, $tabs, $options);
        }

        $this->addStandardTab(PluginGrcmanagerSecurityIncident_Item::class, $tabs, $options);
        $this->addStandardTab(PluginGrcmanagerSecurityIncidentCost::class, $tabs, $options);
        $this->addStandardTab(KnowbaseItem_Item::class, $tabs, $options);
        $this->addStandardTab(Notepad::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function cleanDBonPurge()
    {
        $task = new PluginGrcmanagerSecurityIncidentTask();
        $task->deleteByCriteria(['plugin_grcmanager_securityincidents_id' => $this->fields['id']]);

        $this->deleteChildrenAndRelationsFromDb([
            PluginGrcmanagerSecurityIncident_Item::class,
            PluginGrcmanagerSecurityIncidentCve::class,
            PluginGrcmanagerSecurityIncidentCost::class,
        ]);

        parent::cleanDBonPurge();
    }

    public function post_addItem()
    {
        parent::post_addItem();

        $this->handleNewItemNotifications();
    }

    public function post_updateItem($history = true)
    {
        global $CFG_GLPI;

        parent::post_updateItem($history);

        $doNotif = count($this->updates) > 0;
        if (isset($this->input['_disablenotif'])) {
            $doNotif = false;
        }

        if ($doNotif && $CFG_GLPI['use_notifications']) {
            $mailType = 'update';
            if (isset($this->input['status']) && in_array('status', $this->updates, true)) {
                if (in_array($this->input['status'], static::getSolvedStatusArray(), true)) {
                    $mailType = 'solved';
                } elseif (in_array($this->input['status'], static::getClosedStatusArray(), true)) {
                    $mailType = 'closed';
                }
            }

            $this->getFromDB($this->fields['id']);
            NotificationEvent::raiseEvent($mailType, $this);
        }
    }

    /**
     * Normalizes the ISO classification fields absorbed from the plugin's former lightweight
     * register (issue #29) and enforces clause A.5.27 ("tirer des enseignements des incidents") in
     * practice: closing an incident (`status` = CLOSED) without a documented root cause AND
     * documented lessons learned is refused, exactly like the standalone register used to. Neither
     * is required to open an incident or move it through any other status.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);

        return $input === false ? false : $this->normalizeIsoFields($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);

        return $input === false ? false : $this->normalizeIsoFields($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeIsoFields(array $input)
    {
        if (array_key_exists('category', $input)) {
            $input['category'] = SecurityIncidentRules::normalizeCategory($input['category']);
        }

        if (array_key_exists('severity', $input)) {
            $input['severity'] = SecurityIncidentRules::normalizeSeverity($input['severity']);
        }

        if (array_key_exists('cia_impact', $input)) {
            $input['cia_impact'] = SecurityIncidentRules::normalizeCiaImpact($input['cia_impact']);
        }

        if (array_key_exists('plugin_grcmanager_risks_id', $input)) {
            $input['plugin_grcmanager_risks_id'] = SecurityIncidentRules::normalizeLinkedRiskId(
                $input['plugin_grcmanager_risks_id']
            );
            // Un utilisateur ne lie qu'un risque qu'il peut lire : sinon le titre relu dans la
            // liste des incidents énumérerait le registre (revue de sécurité 2.11.0). La valeur
            // enregistrée reste inchangée.
            $riskId = (int) $input['plugin_grcmanager_risks_id'];
            if (
                $riskId > 0
                && Session::getLoginUserID() !== false
                && !(new PluginGrcmanagerRisk())->can($riskId, READ)
            ) {
                unset($input['plugin_grcmanager_risks_id']);
            }
        }

        // Issue #128 : cases « Soumis à NIS2 » / « Violation de données personnelles » et dates
        // auxquelles l'équipe a effectivement notifié (vide = pas encore).
        foreach (['nis2_applicable', 'personal_data_breach', 'is_exercise'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = empty($input[$flag]) ? 0 : 1;
            }
        }
        foreach (RegulatoryDeadlines::DEADLINES as [, , $doneField]) {
            if (!array_key_exists($doneField, $input)) {
                continue;
            }
            $value = trim((string) $input[$doneField]);
            if ($value === '' || $value === 'NULL') {
                $input[$doneField] = 'NULL';
            } elseif (($time = strtotime(str_replace('T', ' ', $value))) !== false) {
                // Champ « datetime-local » du navigateur : « 2026-10-18T20:00 ».
                $input[$doneField] = date('Y-m-d H:i:s', $time);
            } else {
                unset($input[$doneField]);
            }
        }

        $status = (int) ($input['status'] ?? $this->fields['status'] ?? self::INCOMING);

        if ($status === self::CLOSED) {
            $rootCause      = (string) ($input['root_cause'] ?? $this->fields['root_cause'] ?? '');
            $lessonsLearned = (string) ($input['lessons_learned'] ?? $this->fields['lessons_learned'] ?? '');

            if (SecurityIncidentRules::isClosureDocumentationMissing($rootCause, $lessonsLearned)) {
                Session::addMessageAfterRedirect(
                    __('The root cause and lessons learned are required to close a security incident.', 'grcmanager'),
                    false,
                    ERROR
                );

                return false;
            }
        }

        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = $this->getSearchOptionsMain();

        // ISO/IEC 27001:2022 Annexe A A.5.24-27 classification fields absorbed from the plugin's
        // former lightweight register (issue #29) — IDs 220+ deliberately clear of every ID
        // CommonITILObject/Change use for their own fields (checked against a real GLPI 11
        // instance: the highest core-used id below 400 is 154, Change's own highest is 211).
        $tab[] = [
            'id'       => 220,
            'table'    => $this->getTable(),
            'field'    => 'category',
            'name'     => __('Category', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 221,
            'table'    => $this->getTable(),
            'field'    => 'severity',
            'name'     => __('Severity', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 222,
            'table'    => $this->getTable(),
            'field'    => 'cia_impact',
            'name'     => __('CIA impact', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 223,
            'table'    => $this->getTable(),
            'field'    => 'root_cause',
            'name'     => __('Root cause', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 224,
            'table'    => $this->getTable(),
            'field'    => 'lessons_learned',
            'name'     => __('Lessons learned', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 225,
            'table'    => $this->getTable(),
            'field'    => 'plugin_grcmanager_risks_id',
            'name'     => PluginGrcmanagerRisk::getTypeName(1),
            'datatype' => 'specific',
        ];

        // Issue #128 : notifications réglementaires.
        $tab[] = [
            'id'       => 226,
            'table'    => $this->getTable(),
            'field'    => 'nis2_applicable',
            'name'     => __('Subject to NIS2', 'grcmanager'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 227,
            'table'    => $this->getTable(),
            'field'    => 'personal_data_breach',
            'name'     => __('Personal data breach', 'grcmanager'),
            'datatype' => 'bool',
        ];
        $id = 228;
        foreach (self::getRegulatoryLabels() as $key => $label) {
            $tab[] = [
                'id'       => $id++,
                'table'    => $this->getTable(),
                'field'    => RegulatoryDeadlines::DEADLINES[$key][2],
                'name'     => sprintf(__('%s: notified on', 'grcmanager'), $label),
                'datatype' => 'datetime',
            ];
        }
        // Issue #168 : incident fictif créé par un exercice de crise.
        $tab[] = [
            'id'       => 232,
            'table'    => $this->getTable(),
            'field'    => 'is_exercise',
            'name'     => __('Crisis exercise', 'grcmanager'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    /**
     * Issue #128 : libellés des échéances réglementaires (voir RegulatoryDeadlines).
     *
     * @return array<string, string>
     */
    public static function getRegulatoryLabels(): array
    {
        return [
            'nis2_early_warning' => __('NIS2 — early warning (24 h)', 'grcmanager'),
            'nis2_notification'  => __('NIS2 — incident notification (72 h)', 'grcmanager'),
            'nis2_final_report'  => __('NIS2 — final report (1 month)', 'grcmanager'),
            'gdpr_notification'  => __('GDPR — notification to the supervisory authority (72 h)', 'grcmanager'),
        ];
    }

    /**
     * @return array{description?: string}
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'regulatorydeadlines'
            ? ['description' => __(
                'Reports NIS2 / GDPR notification deadlines that are near or past with a follow-up',
                'grcmanager'
            )]
            : [];
    }

    /**
     * Issue #128 : pour chaque incident ouvert soumis à NIS2 ou au RGPD, un suivi privé quand une
     * échéance de notification approche (moins d'un quart du délai restant) puis quand elle est
     * dépassée — une seule fois par niveau. Le suivi déclenche les notifications GLPI habituelles
     * aux acteurs de l'incident. Rien n'est envoyé à une autorité, aucun statut ne change.
     */
    public static function cronRegulatorydeadlines(CronTask $task): int
    {
        global $DB;

        $labels = self::getRegulatoryLabels();
        $count = 0;
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                'NOT'        => ['status' => self::getClosedStatusArray()],
                'OR'         => ['nis2_applicable' => 1, 'personal_data_breach' => 1],
            ],
        ]);
        foreach ($rows as $row) {
            $alerted = json_decode((string) ($row['regulatory_alerts'] ?? ''), true);
            $alerted = is_array($alerted) ? array_values(array_map('strval', $alerted)) : [];
            $toAlert = RegulatoryDeadlines::toAlert($row, $alerted);
            if ($toAlert === []) {
                continue;
            }
            foreach ($toAlert as $deadline) {
                $message = $deadline['status'] === RegulatoryDeadlines::STATUS_OVERDUE
                    ? __('Deadline passed: “%1$s” was due no later than %2$s.', 'grcmanager')
                    : __('Deadline approaching: “%1$s” is due no later than %2$s.', 'grcmanager');
                (new ITILFollowup())->add([
                    'itemtype'   => self::class,
                    'items_id'   => (int) $row['id'],
                    'is_private' => 1,
                    'content'    => Fragment::ticketContent([
                        sprintf(
                            $message,
                            $labels[$deadline['key']] ?? $deadline['key'],
                            Html::convDateTime($deadline['due'])
                        ),
                        __(
                            'Nothing is sent to the authority automatically: make the notification, then enter its '
                                . 'date in “Regulatory notifications” on the incident.',
                            'grcmanager'
                        ),
                    ]),
                ]);
                $alerted[] = $deadline['key'] . ':' . $deadline['status'];
                $count++;
            }
            $DB->update(self::getTable(), ['regulatory_alerts' => json_encode($alerted)], ['id' => (int) $row['id']]);
        }
        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'category':
                return self::categoryBadge($values[$field] ?? null);

            case 'severity':
                return self::severityBadge($values[$field] ?? null);

            case 'cia_impact':
                return self::ciaImpactBadges((string) ($values[$field] ?? ''));

            case 'plugin_grcmanager_risks_id':
                return self::riskLink((int) ($values[$field] ?? 0));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'category':
                return Dropdown::showFromArray($name, self::getCategories(), $options);

            case 'severity':
                return Dropdown::showFromArray($name, self::getSeverities(), $options);

            case 'cia_impact':
                return Dropdown::showFromArray($name, self::getCiaAxisLabels(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function categoryBadge(?string $value): string
    {
        $map = [
            'data_breach'         => ['bg-red-lt', 'ti-file-shredder', __('Data breach', 'grcmanager')],
            'malware'             => ['bg-orange-lt', 'ti-virus', __('Malware', 'grcmanager')],
            'unauthorized_access' => ['bg-purple-lt', 'ti-lock-open', __('Unauthorized access', 'grcmanager')],
            'availability'        => [
                'bg-yellow-lt',
                'ti-plug-connected-x',
                __('Unavailability / denial of service', 'grcmanager'),
            ],
            'other'               => ['bg-secondary-lt', 'ti-dots', __('Other', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function severityBadge(?string $value): string
    {
        $map = [
            'minor'    => ['bg-yellow-lt', 'ti-alert-circle', __('Minor', 'grcmanager')],
            'major'    => ['bg-orange-lt', 'ti-alert-triangle', __('Major', 'grcmanager')],
            'critical' => ['bg-red-lt', 'ti-alert-octagon', __('Critical', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function ciaImpactBadges(string $csv): string
    {
        $axes = SecurityIncidentRules::splitCiaImpact($csv);

        if ($axes === []) {
            return '';
        }

        $labels = self::getCiaAxisLabels();
        $badges = [];

        foreach ($axes as $axis) {
            $badges[] = Badge::html('bg-azure-lt', null, $labels[$axis] ?? $axis);
        }

        return implode(' ', $badges);
    }

    private static function riskLink(int $riskId): string
    {
        if (!SecurityIncidentRules::isLinkedToRisk($riskId)) {
            return '';
        }

        // Titre du risque seulement pour qui peut lire le registre des risques.
        $risk = new PluginGrcmanagerRisk();
        if (!$risk->can($riskId, READ)) {
            return '';
        }

        return Fragment::link($risk->getFormURLWithID($riskId), (string) $risk->fields['title']);
    }
}
