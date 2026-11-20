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

use CommonGLPI;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules as Rules;
use GlpiPlugin\Grcmanager\Services\Perimeter\PerimeterRules;
use Html;
use Log;
use Session;
use User;

/**
 * Issue #773 : audit interne conforme à l'ISO 19011 (ISO 27001, article 9.2).
 *
 * - programme pluriannuel : matrice des processus du SMSI (activités de la matrice des
 *   responsabilités) par année du cycle de certification, couverture et alertes ;
 * - onglet « Programme et indépendance » de l'audit : processus audités, conflits d'indépendance
 *   des auditeurs (un auditeur qui réalise ou approuve un processus audité), justification tracée
 *   de chaque conflit, remise du rapport à la direction ;
 * - compétence des auditeurs (formations qualifiantes), affichée dans l'équipe d'audit.
 *
 * Droits : ceux de l'audit (lecture pour voir, modification pour enregistrer) ; les réglages du
 * programme demandent le droit de modifier les audits. Entités : audits et processus des entités
 * de la session ; un processus audité appartient à l'entité de l'audit (ou la couvre). Seuls les
 * audits internes entrent dans le programme et dans ces contrôles.
 */
class AuditProgramme extends CommonGLPI
{
    public const PROCESSES_TABLE = 'glpi_plugin_grcmanager_audits_processes';
    public const WAIVERS_TABLE = 'glpi_plugin_grcmanager_auditindependencewaivers';
    public const DELIVERIES_TABLE = 'glpi_plugin_grcmanager_auditreportdeliveries';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CYCLE_START_KEY = 'audit_cycle_start';
    public const QUALIFYING_TRAININGS_KEY = 'auditor_training_ids';

    /** Type d'audit (issue #772) : seuls les audits internes entrent dans le programme. */
    private const TYPE_FIELD = 'audit_type';
    private const TYPE_INTERNAL = 'internal';

    private const ACTIVITY_FK = 'plugin_grcmanager_responsibilityactivities_id';

    public static function getTypeName($nb = 0)
    {
        return __('Multi-year audit programme', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-stats';
    }

    public static function canView(): bool
    {
        return Audit::canView();
    }

    public static function canUpdate(): bool
    {
        return Audit::canUpdate();
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditprogramme.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditprogramme.form.php';
    }

    /** Sous-page de la rubrique « Audits et amélioration » (Menu::sections()). */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Audits internes
    // ---------------------------------------------------------------------------------------------

    /**
     * Critère des audits internes. Les audits de l'organisme de certification (issue #772) portent
     * un type `audit_type` autre que « internal » ; tant que la colonne n'existe pas, tous les
     * audits sont internes.
     *
     * @return array<string, mixed>
     */
    public static function internalCriteria(string $table = ''): array
    {
        global $DB;

        if (!$DB->fieldExists(Audit::getTable(), self::TYPE_FIELD)) {
            return [];
        }

        return [($table !== '' ? $table . '.' : '') . self::TYPE_FIELD => ['', self::TYPE_INTERNAL]];
    }

    public static function isInternal(Audit $audit): bool
    {
        if (!array_key_exists(self::TYPE_FIELD, $audit->fields)) {
            return true;
        }

        return in_array((string) $audit->fields[self::TYPE_FIELD], ['', self::TYPE_INTERNAL], true);
    }

    // ---------------------------------------------------------------------------------------------
    // Processus audités
    // ---------------------------------------------------------------------------------------------

    /**
     * Processus (activités du SMSI) que l'audit peut viser : ceux des entités de la session, dans
     * l'entité de l'audit ou visibles depuis elle.
     *
     * @return array<int, string> id => libellé
     */
    public static function processChoices(Audit $audit): array
    {
        global $DB;

        $table = ResponsibilityActivity::getTable();
        if (!$DB->tableExists($table)) {
            return [];
        }
        $entityId = (int) ($audit->fields['entities_id'] ?? 0);
        $parents = EntityPerimeter::parents();
        $chain = PerimeterRules::chain($entityId, $parents);
        $subtree = PerimeterRules::subtree($entityId, $parents);
        $recursive = !empty($audit->fields['is_recursive']);
        $choices = [];
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => EntityPerimeter::criteria($table),
                'ORDER' => ['ranking', 'id'],
            ]) as $row
        ) {
            $fits = Rules::processFitsAudit(
                (int) $row['entities_id'],
                !empty($row['is_recursive']),
                $chain,
                $subtree,
                $recursive
            );
            if ($fits) {
                $choices[(int) $row['id']] = ResponsibilityActivity::label($row);
            }
        }

        return $choices;
    }

    /** @return list<int> */
    public static function processIds(int $auditId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => [self::ACTIVITY_FK],
                'FROM'   => self::PROCESSES_TABLE,
                'WHERE'  => ['plugin_grcmanager_audits_id' => $auditId],
                'ORDER'  => 'id',
            ]) as $row
        ) {
            $ids[] = (int) $row[self::ACTIVITY_FK];
        }

        return $ids;
    }

    /**
     * Remplace les processus de l'audit ; seuls ceux de processChoices() sont retenus.
     *
     * @param array<mixed> $processIds
     */
    public static function setProcesses(Audit $audit, array $processIds): void
    {
        global $DB;

        $allowed = array_keys(self::processChoices($audit));
        $wanted = array_values(array_intersect(array_unique(array_map('intval', $processIds)), $allowed));
        $current = self::processIds($audit->getID());
        $removed = array_diff($current, $wanted);
        $added = array_diff($wanted, $current);
        if ($removed !== []) {
            $DB->delete(self::PROCESSES_TABLE, [
                'plugin_grcmanager_audits_id' => $audit->getID(),
                self::ACTIVITY_FK             => array_values($removed),
            ]);
        }
        foreach ($added as $processId) {
            $DB->insert(self::PROCESSES_TABLE, [
                'plugin_grcmanager_audits_id' => $audit->getID(),
                self::ACTIVITY_FK             => $processId,
                'date_creation'               => date('Y-m-d H:i:s'),
            ]);
        }
        if ($added !== [] || $removed !== []) {
            $labels = self::processChoices($audit);
            $names = array_map(static fn (int $id): string => $labels[$id] ?? (string) $id, $wanted);
            Log::history($audit->getID(), Audit::class, [
                0,
                '',
                sprintf(__('Audited processes: %s', 'grcmanager'), $names === [] ? '-' : implode(', ', $names)),
            ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Indépendance des auditeurs
    // ---------------------------------------------------------------------------------------------

    /**
     * Auditeurs de l'audit : l'auditeur de la fiche et les auditeurs de l'équipe.
     *
     * @return list<int>
     */
    public static function auditors(Audit $audit, ?int $leadAuditor = null): array
    {
        $auditors = [$leadAuditor ?? (int) ($audit->fields['users_id'] ?? 0)];
        foreach (AuditTeam::getMembers($audit->getID()) as $member) {
            if ($member['role'] === AuditTeam::ROLE_AUDITOR) {
                $auditors[] = $member['users_id'];
            }
        }

        return array_values(array_unique(array_filter($auditors, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<int> $userIds
     * @return array<int, list<int>>
     */
    private static function groupsOf(array $userIds): array
    {
        global $DB;

        $groups = [];
        if ($userIds === []) {
            return $groups;
        }
        foreach (
            $DB->request([
                'SELECT' => ['users_id', 'groups_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['users_id' => $userIds],
            ]) as $row
        ) {
            $groups[(int) $row['users_id']][] = (int) $row['groups_id'];
        }

        return $groups;
    }

    /**
     * Conflits d'indépendance de l'audit, chacun avec sa justification éventuelle.
     *
     * @return list<array<string, mixed>>
     */
    public static function conflicts(Audit $audit, ?int $leadAuditor = null): array
    {
        $auditors = self::auditors($audit, $leadAuditor);
        $processIds = self::processIds($audit->getID());
        if ($auditors === [] || $processIds === []) {
            return [];
        }
        $assignments = ResponsibilityActivity::assignmentsFor($processIds);
        $conflicts = Rules::conflicts($auditors, self::groupsOf($auditors), $assignments);
        $waivers = self::waivers($audit->getID());
        $labels = self::processLabels($processIds);
        $roles = ResponsibilityActivity::getRoles();
        foreach ($conflicts as &$conflict) {
            $key = Rules::conflictKey($conflict['users_id'], $conflict['process_id']);
            $conflict['key'] = $key;
            $conflict['user'] = (string) getUserName($conflict['users_id']);
            $conflict['process'] = $labels[$conflict['process_id']] ?? '';
            $conflict['role_label'] = $roles[$conflict['role']] ?? $conflict['role'];
            $conflict['group'] = $conflict['groups_id'] > 0
                ? (string) \Dropdown::getDropdownName('glpi_groups', $conflict['groups_id'])
                : '';
            $conflict['waiver'] = $waivers[$key] ?? null;
        }
        unset($conflict);

        return $conflicts;
    }

    /**
     * @param list<int> $processIds
     * @return array<int, string>
     */
    private static function processLabels(array $processIds): array
    {
        global $DB;

        $labels = [];
        if ($processIds === []) {
            return $labels;
        }
        $rows = $DB->request(['FROM' => ResponsibilityActivity::getTable(), 'WHERE' => ['id' => $processIds]]);
        foreach ($rows as $row) {
            $labels[(int) $row['id']] = ResponsibilityActivity::label($row);
        }

        return $labels;
    }

    /**
     * Justifications enregistrées, par clé « auditeur:processus ».
     *
     * @return array<string, array<string, mixed>>
     */
    public static function waivers(int $auditId): array
    {
        global $DB;

        $waivers = [];
        foreach (
            $DB->request([
                'FROM'  => self::WAIVERS_TABLE,
                'WHERE' => ['plugin_grcmanager_audits_id' => $auditId],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $waivers[Rules::conflictKey((int) $row['users_id'], (int) $row[self::ACTIVITY_FK])] = [
                'id'            => (int) $row['id'],
                'justification' => (string) $row['justification'],
                'author'        => (string) getUserName((int) $row['users_id_author']),
                'date'          => Html::convDateTime($row['date_creation']),
            ];
        }

        return $waivers;
    }

    /**
     * Enregistre la justification d'un conflit existant (texte d'au moins
     * ProgrammeRules::MIN_JUSTIFICATION_LENGTH caractères), tracée dans l'historique de l'audit.
     */
    public static function justify(Audit $audit, int $userId, int $processId, mixed $text): bool
    {
        global $DB;

        $justification = Rules::cleanJustification($text);
        if (!Rules::isValidJustification($justification)) {
            return false;
        }
        $key = Rules::conflictKey($userId, $processId);
        $conflict = null;
        foreach (self::conflicts($audit) as $candidate) {
            if ($candidate['key'] === $key) {
                $conflict = $candidate;
            }
        }
        if ($conflict === null || $conflict['waiver'] !== null) {
            return false;
        }
        $DB->insert(self::WAIVERS_TABLE, [
            'plugin_grcmanager_audits_id' => $audit->getID(),
            'users_id'                    => $userId,
            self::ACTIVITY_FK             => $processId,
            'justification'               => $justification,
            'users_id_author'             => (int) Session::getLoginUserID(),
            'date_creation'               => date('Y-m-d H:i:s'),
        ]);
        Log::history($audit->getID(), Audit::class, [
            0,
            '',
            sprintf(
                __('Independence conflict justified: %1$s on %2$s (%3$s)', 'grcmanager'),
                $conflict['user'],
                $conflict['process'],
                $justification
            ),
        ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

        return true;
    }

    /**
     * Appelé avant la mise à jour d'un audit interne : refuse de le démarrer, de le terminer ou de
     * changer son auditeur en cours d'audit tant qu'un conflit d'indépendance n'est pas justifié.
     *
     * @param array<string, mixed> $input
     */
    public static function allowsUpdate(Audit $audit, array $input): bool
    {
        if ($audit->isNewItem() || !self::isInternal($audit)) {
            return true;
        }
        $oldStatus = (string) ($audit->fields['status'] ?? '');
        $newStatus = (string) ($input['status'] ?? $oldStatus);
        $lead = (int) ($input['users_id'] ?? ($audit->fields['users_id'] ?? 0));
        if (!Rules::isGuardedChange($oldStatus, $newStatus, $lead !== (int) ($audit->fields['users_id'] ?? 0))) {
            return true;
        }
        $open = array_filter(self::conflicts($audit, $lead), static fn (array $c): bool => $c['waiver'] === null);
        if ($open === []) {
            return true;
        }
        Session::addMessageAfterRedirect(
            sprintf(
                __(
                    'Independence of the auditors: %s. Justify each conflict in the "Programme and independence" '
                        . 'tab, or change the auditor, before starting or closing the audit.',
                    'grcmanager'
                ),
                implode(', ', array_map(
                    static fn (array $c): string => sprintf(
                        __('%1$s on %2$s', 'grcmanager'),
                        $c['user'],
                        $c['process']
                    ),
                    $open
                ))
            ),
            false,
            ERROR
        );

        return false;
    }

    // ---------------------------------------------------------------------------------------------
    // Remise du rapport à la direction
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function delivery(int $auditId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::DELIVERIES_TABLE,
            'WHERE' => ['plugin_grcmanager_audits_id' => $auditId],
            'LIMIT' => 1,
        ])->current();

        return $row === null ? null : $row;
    }

    /**
     * Remises des rapports, par audit.
     *
     * @param list<int> $auditIds
     * @return array<int, array{date: string, recipient: string}>
     */
    public static function deliveriesFor(array $auditIds): array
    {
        global $DB;

        $deliveries = [];
        if ($auditIds === []) {
            return $deliveries;
        }
        $rows = $DB->request([
            'FROM'  => self::DELIVERIES_TABLE,
            'WHERE' => ['plugin_grcmanager_audits_id' => $auditIds],
        ]);
        foreach ($rows as $row) {
            $recipient = (int) $row['users_id_recipient'];
            $deliveries[(int) $row['plugin_grcmanager_audits_id']] = [
                'date'      => (string) Html::convDate($row['delivery_date']),
                'recipient' => $recipient > 0 ? (string) getUserName($recipient) : '',
            ];
        }

        return $deliveries;
    }

    /**
     * Enregistre la date de remise du rapport et son destinataire (une personne qui a un profil
     * dans l'entité de l'audit).
     */
    public static function saveDelivery(Audit $audit, mixed $date, int $recipientId, mixed $comment): bool
    {
        global $DB;

        $date = is_string($date) ? trim($date) : '';
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return false;
        }
        $user = new User();
        if (
            $recipientId <= 0
            || !$user->getFromDB($recipientId)
            || !EntityPerimeter::userReachesItem($recipientId, $audit)
        ) {
            return false;
        }
        $DB->updateOrInsert(self::DELIVERIES_TABLE, [
            'delivery_date'      => $date,
            'users_id_recipient' => $recipientId,
            'comment'            => ChecklistRules::cleanLongText($comment),
            'users_id'           => (int) Session::getLoginUserID(),
            'date_mod'           => date('Y-m-d H:i:s'),
        ], ['plugin_grcmanager_audits_id' => $audit->getID()]);
        Log::history($audit->getID(), Audit::class, [
            0,
            '',
            sprintf(
                __('Audit report delivered to management on %1$s (%2$s)', 'grcmanager'),
                Html::convDate($date),
                getUserName($recipientId)
            ),
        ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Compétence des auditeurs
    // ---------------------------------------------------------------------------------------------

    /** @return list<int> formations qualifiantes choisies */
    public static function qualifyingTrainingIds(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::QUALIFYING_TRAININGS_KEY]);
        $ids = json_decode((string) ($values[self::QUALIFYING_TRAININGS_KEY] ?? '[]'), true);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /** @param array<mixed> $trainingIds */
    public static function setQualifyingTrainingIds(array $trainingIds): void
    {
        $existing = array_keys(Training::getMainTrainings());
        $ids = array_values(array_filter(
            array_intersect(array_unique(array_map('intval', $trainingIds)), $existing),
            static fn (int $id): bool => $id > 0
        ));
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::QUALIFYING_TRAININGS_KEY => json_encode($ids)]);
    }

    /**
     * Compétence de chaque utilisateur (formations qualifiantes et leurs sessions).
     *
     * @param list<int> $userIds
     * @return array<int, array{status: string, trainings: list<array<string, mixed>>}>
     */
    public static function competences(array $userIds, ?string $today = null): array
    {
        global $DB;

        $today ??= date('Y-m-d');
        $qualifying = self::qualifyingTrainingIds();
        $rows = array_fill_keys($userIds, []);
        if ($qualifying !== [] && $userIds !== []) {
            $trainings = Training::getTable();
            $links = Training::PARTICIPANTS_TABLE;
            foreach (
                $DB->request([
                    'SELECT'     => [
                        "$links.users_id", "$links.completion_status", "$links.completion_date",
                        "$links.assessment_result", "$trainings.title", "$trainings.renewal_period_months",
                    ],
                    'FROM'       => $links,
                    'INNER JOIN' => [
                        $trainings => ['FKEY' => [$links => 'plugin_grcmanager_trainings_id', $trainings => 'id']],
                    ],
                    'WHERE'      => [
                        "$links.users_id" => $userIds,
                        'OR'              => [
                            "$trainings.id"                             => $qualifying,
                            "$trainings.plugin_grcmanager_trainings_id" => $qualifying,
                        ],
                    ],
                    'ORDER'      => "$links.completion_date DESC",
                ]) as $row
            ) {
                $rows[(int) $row['users_id']][] = [
                    'title'                 => (string) $row['title'],
                    'completion_status'     => (string) $row['completion_status'],
                    'completion_date'       => $row['completion_date'] !== null
                        ? (string) $row['completion_date']
                        : null,
                    'assessment_result'     => (string) ($row['assessment_result'] ?? 'not_applicable'),
                    'renewal_period_months' => (int) $row['renewal_period_months'],
                ];
            }
        }
        $competences = [];
        foreach ($rows as $userId => $trainingRows) {
            $competence = Rules::competence($trainingRows, $qualifying !== [], $today);
            foreach ($competence['trainings'] as &$training) {
                $training['date_text'] = Html::convDate($training['completion_date']);
            }
            unset($training);
            $competences[(int) $userId] = $competence;
        }

        return $competences;
    }

    // ---------------------------------------------------------------------------------------------
    // Programme pluriannuel
    // ---------------------------------------------------------------------------------------------

    public static function cycleStart(mixed $requested = null): int
    {
        if ($requested === null || $requested === '') {
            $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CYCLE_START_KEY]);
            $requested = $values[self::CYCLE_START_KEY] ?? null;
        }

        return Rules::cycleStart($requested, (int) date('Y'));
    }

    public static function setCycleStart(mixed $year): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::CYCLE_START_KEY => Rules::cycleStart($year, (int) date('Y')),
        ]);
    }

    /**
     * Matrice du programme, alertes comprises, pour les processus et audits internes des entités
     * de la session.
     *
     * @return array<string, mixed>
     */
    public static function programme(int $start, ?string $today = null): array
    {
        global $DB;

        $today ??= date('Y-m-d');
        [$from, $to] = Rules::cycleBounds($start);
        $processes = [];
        foreach (ResponsibilityActivity::visibleActivities() as $row) {
            $processes[(int) $row['id']] = ResponsibilityActivity::label($row);
        }

        $table = Audit::getTable();
        $audits = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title', 'status', 'actual_date', 'planned_date'],
                'FROM'   => $table,
                'WHERE'  => array_merge(
                    ['NOT' => ['status' => 'cancelled']],
                    self::internalCriteria(),
                    EntityPerimeter::criteria($table)
                ),
                'ORDER'  => 'id',
            ]) as $row
        ) {
            $date = Rules::auditDate($row['actual_date'], $row['planned_date']);
            if ($date === '' || $date < $from || $date > $to) {
                continue;
            }
            $audits[(int) $row['id']] = [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['title'],
                'status'    => (string) $row['status'],
                'date'      => $date,
                'processes' => [],
            ];
        }
        if ($audits !== []) {
            foreach (
                $DB->request([
                    'FROM'  => self::PROCESSES_TABLE,
                    'WHERE' => ['plugin_grcmanager_audits_id' => array_keys($audits)],
                ]) as $row
            ) {
                $processId = (int) $row[self::ACTIVITY_FK];
                if (isset($processes[$processId])) {
                    $audits[(int) $row['plugin_grcmanager_audits_id']]['processes'][] = $processId;
                }
            }
        }

        $matrix = Rules::matrix($processes, array_values($audits), $start, $today);
        $statuses = Audit::getStatuses();
        $decorate = static function (array $audit) use ($statuses, $today): array {
            return $audit + [
                'state'        => Rules::state([$audit], $today),
                'url'          => Audit::getFormURLWithID($audit['id']),
                'status_label' => $statuses[$audit['status']] ?? $audit['status'],
                'date_text'    => Html::convDate($audit['date']),
            ];
        };
        foreach ($matrix['rows'] as &$row) {
            foreach ($row['cells'] as &$cell) {
                $cell['audits'] = array_map($decorate, $cell['audits']);
            }
            unset($cell);
        }
        unset($row);
        $matrix['unassigned'] = array_map($decorate, $matrix['unassigned']);

        // Exigences du SMSI (articles 4 à 10) qu'aucune grille d'audit du cycle n'a couvertes.
        $coverage = AuditChecklist::coverage(Rules::CYCLE_LENGTH, $today, $from);
        $clauses = array_values(array_filter(
            $coverage['rows'],
            static fn (array $row): bool => !$row['audited'] && $row['type'] === ChecklistRules::TYPE_CLAUSE
        ));
        $controlsNever = count(array_filter(
            $coverage['rows'],
            static fn (array $row): bool => !$row['audited'] && $row['type'] === ChecklistRules::TYPE_CONTROL
        ));

        return $matrix + [
            'start'            => $start,
            'from'             => Html::convDate($from),
            'to'               => Html::convDate($to),
            'current_year'     => (int) substr($today, 0, 4),
            'clauses_never'    => $clauses,
            'controls_never'   => $controlsNever,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet de l'audit
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !Audit::canView() || !self::isInternal($item)) {
            return '';
        }
        $open = count(array_filter(self::conflicts($item), static fn (array $c): bool => $c['waiver'] === null));

        return self::createTabEntry(__('Programme and independence', 'grcmanager'), $open, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Audit || !$item->can($item->getID(), READ) || !self::isInternal($item)) {
            return false;
        }
        self::showForAudit($item);

        return true;
    }

    public static function showForAudit(Audit $audit): void
    {
        $delivery = self::delivery($audit->getID());
        $auditors = self::auditors($audit);

        TemplateRenderer::getInstance()->display('@grcmanager/audit_programme_tab.html.twig', [
            'audit'          => $audit,
            'can_edit'       => $audit->can($audit->getID(), UPDATE),
            'action'         => self::getFormURL(),
            'choices'        => self::processChoices($audit),
            'selected'       => self::processIds($audit->getID()),
            'conflicts'      => self::conflicts($audit),
            'auditors'       => array_map(static fn (int $id): string => (string) getUserName($id), $auditors),
            'min_length'     => Rules::MIN_JUSTIFICATION_LENGTH,
            'delivery'       => $delivery,
            'delivery_date'  => $delivery['delivery_date'] ?? '',
            'delivery_text'  => Html::convDate($delivery['delivery_date'] ?? null),
            'recipient_id'   => (int) ($delivery['users_id_recipient'] ?? 0),
            'recipient'      => $delivery !== null ? (string) getUserName((int) $delivery['users_id_recipient']) : '',
            'programme_url'  => self::getSearchURL(),
            'raci_url'       => ResponsibilityActivity::getSearchURL(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Nettoyage
    // ---------------------------------------------------------------------------------------------

    public static function purgeAudit(int $auditId): void
    {
        global $DB;

        foreach ([self::PROCESSES_TABLE, self::WAIVERS_TABLE, self::DELIVERIES_TABLE] as $table) {
            $DB->delete($table, ['plugin_grcmanager_audits_id' => $auditId]);
        }
    }

    public static function purgeProcess(int $processId): void
    {
        global $DB;

        $DB->delete(self::PROCESSES_TABLE, [self::ACTIVITY_FK => $processId]);
        $DB->delete(self::WAIVERS_TABLE, [self::ACTIVITY_FK => $processId]);
    }
}
