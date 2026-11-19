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

use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ResponsibilityActivityBase;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Responsibility\DefaultActivities;
use GlpiPlugin\Grcmanager\Services\Responsibility\ResponsibilityRules as Rules;
use Group;
use Html;
use Log;
use Session;
use User;

/**
 * Issue #612 : matrice des responsabilités du SMSI (ISO 27001, article 5.3 et mesure A.5.3).
 *
 * Une fiche est une activité du SMSI (appréciation des risques, revue des accès, sauvegardes...).
 * Pour chaque activité, des utilisateurs ou des groupes de GLPI réalisent, approuvent, sont
 * consultés ou informés ; un seul approuve. La matrice affiche les activités en lignes et les
 * personnes ou groupes en colonnes, avec les alertes : personne n'approuve, une même personne
 * réalise et approuve une activité sensible, groupe sans membre actif, utilisateur désactivé.
 *
 * - Droit général du plugin et entité de l'activité (check() du cœur). Les utilisateurs et les
 *   groupes proposés et acceptés sont ceux de l'entité de l'activité.
 * - Les alertes ne modifient jamais rien : ni affectation, ni droit, ni statut.
 */
class ResponsibilityActivity extends ResponsibilityActivityBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const ASSIGNMENTS_TABLE = 'glpi_plugin_grcmanager_responsibilityassignments';

    public const FK = 'plugin_grcmanager_responsibilityactivities_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_responsibilityactivities';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ISMS activity', 'ISMS activities', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-users-group';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => __('Responsibility matrix', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getRoles(): array
    {
        return [
            Rules::ROLE_RESPONSIBLE => __('Performs', 'grcmanager'),
            Rules::ROLE_ACCOUNTABLE => __('Approves', 'grcmanager'),
            Rules::ROLE_CONSULTED   => __('Consulted', 'grcmanager'),
            Rules::ROLE_INFORMED    => __('Informed', 'grcmanager'),
        ];
    }

    /**
     * Lettre et couleur de chaque rôle dans la matrice.
     *
     * @return array<string, array{letter: string, class: string, icon: string}>
     */
    public static function roleStyles(): array
    {
        return [
            Rules::ROLE_RESPONSIBLE => [
                'letter' => _x('responsibility', 'P', 'grcmanager'),
                'class'  => 'bg-primary-subtle text-primary-emphasis', 'icon' => 'ti-tool',
            ],
            Rules::ROLE_ACCOUNTABLE => [
                'letter' => _x('responsibility', 'A', 'grcmanager'),
                'class'  => 'bg-success-subtle text-success-emphasis', 'icon' => 'ti-signature',
            ],
            Rules::ROLE_CONSULTED   => [
                'letter' => _x('responsibility', 'C', 'grcmanager'),
                'class'  => 'bg-info-subtle text-info-emphasis', 'icon' => 'ti-message-question',
            ],
            Rules::ROLE_INFORMED    => [
                'letter' => _x('responsibility', 'I', 'grcmanager'),
                'class'  => 'bg-secondary-subtle text-secondary-emphasis', 'icon' => 'ti-bell',
            ],
        ];
    }

    /**
     * Libellés traduits des activités proposées par défaut.
     *
     * @return array<string, string>
     */
    public static function defaultLabels(): array
    {
        return [
            'risk_assessment'          => __('Information security risk assessment', 'grcmanager'),
            'risk_acceptance'          => __('Acceptance of residual risks', 'grcmanager'),
            'policy_approval'          => __('Approval of security policies', 'grcmanager'),
            'asset_inventory'          => __('Inventory of information assets', 'grcmanager'),
            'access_rights'            => __('Granting and removing access rights', 'grcmanager'),
            'access_review'            => __('Periodic review of access rights', 'grcmanager'),
            'privileged_access'        => __('Management of privileged accounts', 'grcmanager'),
            'change_management'        => __('Approval of changes in production', 'grcmanager'),
            'backups'                  => __('Backups and restore tests', 'grcmanager'),
            'log_review'               => __('Review of logs and security events', 'grcmanager'),
            'vulnerability_management' => __('Management of technical vulnerabilities', 'grcmanager'),
            'incident_management'      => __('Handling of security incidents', 'grcmanager'),
            'supplier_security'        => __('Security of suppliers', 'grcmanager'),
            'awareness_training'       => __('Security awareness and training', 'grcmanager'),
            'business_continuity'      => __('Business continuity and recovery plans', 'grcmanager'),
            'internal_audit'           => __('Internal audit of the ISMS', 'grcmanager'),
            'management_review'        => __('Management review', 'grcmanager'),
        ];
    }

    /**
     * Nom affiché d'une activité : son nom, ou le libellé traduit de l'activité par défaut.
     *
     * @param array<string, mixed> $row
     */
    public static function label(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return self::defaultLabels()[(string) ($row['default_key'] ?? '')] ?? '';
    }

    public function getName($options = [])
    {
        $label = self::label($this->fields);

        return $label !== '' ? $label : parent::getName($options);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        $key = (string) ($input['default_key'] ?? '');
        $input['default_key'] = DefaultActivities::exists($key) ? $key : '';

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // La clé d'une activité par défaut ne change jamais ; l'entité, seulement vers une entité visible.
        unset($input['default_key']);
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        if (array_key_exists('_control_codes', $input)) {
            $input['control_codes'] = Rules::normalizeCodes((array) $input['_control_codes']);
        } elseif (array_key_exists('control_codes', $input)) {
            $input['control_codes'] = Rules::normalizeCodes($input['control_codes']);
        }
        if (array_key_exists('is_sensitive', $input)) {
            $input['is_sensitive'] = (int) (bool) $input['is_sensitive'];
        }
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
        }
        if (self::label(array_merge($current, $input)) === '') {
            Session::addMessageAfterRedirect(__('Give the activity a name.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->applyAssignmentInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->applyAssignmentInput();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::ASSIGNMENTS_TABLE, [self::FK => (int) $this->getID()]);
    }

    /**
     * Affectations d'une activité.
     *
     * @param list<int> $activityIds
     * @return array<int, list<array{id: int, itemtype: string, items_id: int, role: string}>>
     */
    public static function assignmentsFor(array $activityIds): array
    {
        global $DB;

        $byActivity = [];
        if ($activityIds === []) {
            return $byActivity;
        }
        foreach (
            $DB->request([
                'FROM'  => self::ASSIGNMENTS_TABLE,
                'WHERE' => [self::FK => $activityIds],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $byActivity[(int) $row[self::FK]][] = [
                'id'       => (int) $row['id'],
                'itemtype' => (string) $row['itemtype'],
                'items_id' => (int) $row['items_id'],
                'role'     => (string) $row['role'],
            ];
        }

        return $byActivity;
    }

    /**
     * L'utilisateur ou le groupe appartient-il à l'entité de l'activité (sous-entités comprises
     * pour une activité récursive) ?
     */
    public static function principalAllowed(string $itemtype, int $id, int $entityId, bool $recursive): bool
    {
        if ($id <= 0) {
            return false;
        }
        if ($itemtype === 'User') {
            $user = new User();

            return $user->getFromDB($id) && EntityPerimeter::userReachesEntity($id, $entityId, $recursive);
        }
        $group = new Group();
        if (!$group->getFromDB($id)) {
            return false;
        }
        $groupEntity = (int) $group->fields['entities_id'];
        if ($groupEntity === $entityId) {
            return true;
        }
        // Groupe d'une entité parente visible des sous-entités, ou d'une sous-entité d'une activité récursive.
        $ancestors = getAncestorsOf('glpi_entities', $entityId);
        if (!empty($group->fields['is_recursive']) && in_array($groupEntity, $ancestors)) {
            return true;
        }

        return $recursive && in_array($groupEntity, getSonsOf('glpi_entities', $entityId));
    }

    /**
     * Ajouts et retraits saisis dans le formulaire. Une affectation refusée (doublon, deuxième
     * approbateur, personne hors de l'entité) est signalée, le reste de la fiche est enregistré.
     */
    private function applyAssignmentInput(): void
    {
        global $DB;

        $activityId = (int) $this->getID();
        $label = $this->getName();
        $removed = array_map('intval', (array) ($this->input['_remove_assignments'] ?? []));
        if ($removed !== []) {
            foreach (self::assignmentsFor([$activityId])[$activityId] ?? [] as $row) {
                if (in_array($row['id'], $removed, true)) {
                    $DB->delete(self::ASSIGNMENTS_TABLE, ['id' => $row['id'], self::FK => $activityId]);
                    $this->logAssignment($row, false);
                }
            }
        }

        $role = Rules::normalizeRole($this->input['_add_role'] ?? '');
        if ($role === null) {
            return;
        }
        $entityId = (int) ($this->fields['entities_id'] ?? 0);
        $recursive = !empty($this->fields['is_recursive']);
        foreach (['User' => '_add_users_id', 'Group' => '_add_groups_id'] as $itemtype => $field) {
            $id = (int) ($this->input[$field] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!self::principalAllowed($itemtype, $id, $entityId, $recursive)) {
                Session::addMessageAfterRedirect(
                    __('This user or group does not belong to the entity of the activity.', 'grcmanager'),
                    false,
                    ERROR
                );
                continue;
            }
            $new = ['itemtype' => $itemtype, 'items_id' => $id, 'role' => $role];
            $check = Rules::checkNew(self::assignmentsFor([$activityId])[$activityId] ?? [], $new);
            if ($check === 'accountable_taken') {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Only one person or group approves "%s": remove the current one first.', 'grcmanager'),
                        $label
                    ),
                    false,
                    ERROR
                );
                continue;
            }
            if ($check === 'duplicate') {
                continue;
            }
            $DB->insert(self::ASSIGNMENTS_TABLE, [
                self::FK        => $activityId,
                'date_creation' => date('Y-m-d H:i:s'),
            ] + $new);
            $this->logAssignment($new, true);
        }
    }

    /**
     * Historique de l'activité : qui a été ajouté ou retiré, avec quel rôle.
     *
     * @param array{itemtype: string, items_id: int, role: string} $row
     */
    private function logAssignment(array $row, bool $added): void
    {
        $who = self::principalLabels([$row])[$row['itemtype'] . ':' . $row['items_id']] ?? '';
        $text = sprintf('%s (%s)', $who, self::getRoles()[$row['role']] ?? $row['role']);
        Log::history(
            (int) $this->getID(),
            self::class,
            [0, $added ? '' : $text, $added ? $text : ''],
            0,
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }

    /**
     * Noms des utilisateurs et des groupes cités, clé « User:12 » ou « Group:3 ».
     *
     * @param list<array{itemtype: string, items_id: int}> $rows
     * @return array<string, string>
     */
    public static function principalLabels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $key = $row['itemtype'] . ':' . $row['items_id'];
            if (isset($labels[$key])) {
                continue;
            }
            $item = $row['itemtype'] === 'Group' ? new Group() : new User();
            if (!$item->getFromDB((int) $row['items_id'])) {
                $labels[$key] = sprintf(__('Deleted item #%d', 'grcmanager'), (int) $row['items_id']);
            } elseif ($item instanceof User) {
                $labels[$key] = (string) getUserName((int) $row['items_id']);
            } else {
                $labels[$key] = (string) $item->fields['completename'];
            }
        }

        return $labels;
    }

    /**
     * Membres actifs des groupes cités.
     *
     * @param list<int> $groupIds
     * @return array<int, list<int>>
     */
    private static function activeMembers(array $groupIds): array
    {
        global $DB;

        $members = [];
        if ($groupIds === []) {
            return $members;
        }
        foreach (
            $DB->request([
                'SELECT'    => ['glpi_groups_users.groups_id', 'glpi_groups_users.users_id'],
                'FROM'      => 'glpi_groups_users',
                'LEFT JOIN' => [
                    'glpi_users' => ['ON' => ['glpi_groups_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'     => [
                    'glpi_groups_users.groups_id' => $groupIds,
                    'glpi_users.is_active'        => 1,
                    'glpi_users.is_deleted'       => 0,
                ],
            ]) as $row
        ) {
            $members[(int) $row['groups_id']][] = (int) $row['users_id'];
        }

        return $members;
    }

    /**
     * Utilisateurs cités qui sont actifs (ni désactivés, ni à la corbeille, ni hors de leur période
     * de validité).
     *
     * @param list<int> $userIds
     * @return array<int, bool>
     */
    private static function activeUsers(array $userIds): array
    {
        global $DB;

        $active = [];
        if ($userIds === []) {
            return $active;
        }
        $now = date('Y-m-d H:i:s');
        foreach (
            $DB->request([
                'SELECT' => ['id', 'begin_date', 'end_date'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $userIds, 'is_active' => 1, 'is_deleted' => 0],
            ]) as $row
        ) {
            $begin = (string) ($row['begin_date'] ?? '');
            $end = (string) ($row['end_date'] ?? '');
            if (($begin === '' || $begin <= $now) && ($end === '' || $end >= $now)) {
                $active[(int) $row['id']] = true;
            }
        }

        return $active;
    }

    /**
     * Activités des entités de la session.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleActivities(): array
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => EntityPerimeter::criteria(self::getTable()),
                'ORDER' => ['ranking', 'id'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Texte d'une alerte.
     *
     * @param array<string, mixed> $alert
     * @param array<string, string> $labels
     */
    public static function alertMessage(array $alert, array $labels): string
    {
        switch ($alert['type']) {
            case Rules::ALERT_NO_ACCOUNTABLE:
                return __('Nobody approves this activity.', 'grcmanager');
            case Rules::ALERT_SEVERAL_ACCOUNTABLE:
                return __('Several people or groups approve this activity: keep only one.', 'grcmanager');
            case Rules::ALERT_NO_RESPONSIBLE:
                return __('Nobody performs this activity.', 'grcmanager');
            case Rules::ALERT_SEGREGATION:
                $names = [];
                foreach ($alert['users'] ?? [] as $id) {
                    $names[] = $labels['User:' . $id] ?? (string) getUserName((int) $id);
                }
                foreach ($alert['groups'] ?? [] as $id) {
                    $names[] = $labels['Group:' . $id] ?? '';
                }

                return sprintf(
                    __('Segregation of duties: %s both performs and approves this sensitive activity.', 'grcmanager'),
                    implode(', ', array_filter($names))
                );
            case Rules::ALERT_EMPTY_GROUP:
                return sprintf(
                    __('The group %s has no active member.', 'grcmanager'),
                    $labels['Group:' . ($alert['items_id'] ?? 0)] ?? ''
                );
            case Rules::ALERT_INACTIVE_USER:
                return sprintf(
                    __('%s is disabled or no longer exists in GLPI.', 'grcmanager'),
                    $labels['User:' . ($alert['items_id'] ?? 0)] ?? ''
                );
        }

        return '';
    }

    /**
     * Matrice des activités des entités de la session : lignes, colonnes (groupes puis
     * utilisateurs) et alertes.
     *
     * @return array{rows: list<array<string, mixed>>, columns: list<array<string, mixed>>,
     *     alerts: list<array<string, mixed>>, counts: array{high: int, medium: int, activities: int}}
     */
    public static function matrix(): array
    {
        $activities = self::visibleActivities();
        $assignments = self::assignmentsFor(array_map(static fn (array $a): int => (int) $a['id'], $activities));
        $all = array_merge([], ...array_values($assignments));
        $labels = self::principalLabels($all);
        $groupIds = $userIds = [];
        foreach ($all as $row) {
            if ($row['itemtype'] === 'Group') {
                $groupIds[$row['items_id']] = $row['items_id'];
            } else {
                $userIds[$row['items_id']] = $row['items_id'];
            }
        }
        $members = self::activeMembers(array_values($groupIds));
        $active = self::activeUsers(array_values($userIds));

        $columns = [];
        foreach ($labels as $key => $label) {
            [$itemtype, $id] = explode(':', $key);
            $id = (int) $id;
            $flag = null;
            if ($itemtype === 'Group' && ($members[$id] ?? []) === []) {
                $flag = 'empty';
            } elseif ($itemtype === 'User' && !isset($active[$id])) {
                $flag = 'inactive';
            }
            $columns[] = ['key' => $key, 'itemtype' => $itemtype, 'label' => $label, 'flag' => $flag];
        }
        // Groupes d'abord, puis utilisateurs, par ordre alphabétique.
        usort(
            $columns,
            static fn (array $a, array $b): int => [$a['itemtype'], $a['label']] <=> [$b['itemtype'], $b['label']]
        );

        $soaIds = EntityPerimeter::controlIdsByCode();
        $rows = $alerts = [];
        $counts = ['high' => 0, 'medium' => 0, 'activities' => count($activities)];
        foreach ($activities as $activity) {
            $id = (int) $activity['id'];
            $own = $assignments[$id] ?? [];
            $cells = $byRole = [];
            foreach ($own as $row) {
                $key = $row['itemtype'] . ':' . $row['items_id'];
                $cells[$key][] = $row['role'];
                $byRole[$row['role']][] = $labels[$key] ?? '';
            }
            $activityAlerts = [];
            foreach (Rules::alerts($activity, $own, $members, $active) as $alert) {
                $alert['message'] = self::alertMessage($alert, $labels);
                $counts[$alert['severity']]++;
                $activityAlerts[] = $alert;
                $alerts[] = $alert + ['activity' => self::label($activity), 'url' => self::getFormURLWithID($id)];
            }
            $codes = [];
            foreach (Rules::splitCodes((string) $activity['control_codes']) as $code) {
                $codes[] = [
                    'code' => $code,
                    'url'  => isset($soaIds[$code]) ? Control::getFormURLWithID($soaIds[$code]) : '',
                ];
            }
            $rows[] = [
                'id'           => $id,
                'label'        => self::label($activity),
                'url'          => self::getFormURLWithID($id),
                'is_sensitive' => !empty($activity['is_sensitive']),
                'codes'        => $codes,
                'cells'        => $cells,
                'by_role'      => $byRole,
                'alerts'       => $activityAlerts,
            ];
        }

        return ['rows' => $rows, 'columns' => $columns, 'alerts' => $alerts, 'counts' => $counts];
    }

    /**
     * Ajoute dans l'entité les activités par défaut qui n'y sont pas encore visibles. N'en modifie
     * aucune. Renvoie le nombre d'activités ajoutées.
     *
     * @param list<string>|null $existingKeys clés déjà présentes (null : celles des entités de la session)
     */
    public static function addDefaults(int $entityId, ?array $existingKeys = null): int
    {
        global $DB;

        if ($existingKeys === null) {
            $existingKeys = array_column(self::visibleActivities(), 'default_key');
        }
        $added = 0;
        $ranking = 0;
        $now = date('Y-m-d H:i:s');
        foreach (DefaultActivities::CATALOG as $key => $entry) {
            $ranking += 10;
            if (in_array($key, $existingKeys, true)) {
                continue;
            }
            $DB->insert(self::getTable(), [
                'entities_id'   => $entityId,
                'is_recursive'  => 1,
                'default_key'   => $key,
                'name'          => '',
                'is_sensitive'  => (int) $entry['sensitive'],
                'control_codes' => implode(',', $entry['codes']),
                'ranking'       => $ranking,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Synthèse affichée sur l'exigence 5.3, null pour les autres ou sans droit.
     *
     * @return array{activities: int, high: int, medium: int, url: string}|null
     */
    public static function clauseReadiness(string $code): ?array
    {
        if ($code !== '5.3' || !self::canView()) {
            return null;
        }
        $counts = self::matrix()['counts'];

        return $counts + ['url' => self::getSearchURL()];
    }

    /**
     * @return array<string, string> code => « A.5.3 Titre », mesures de la SoA de l'entité.
     */
    private static function controlChoices(int $entityId): array
    {
        $choices = [];
        $codes = array_keys(EntityPerimeter::controlIdsByCode($entityId));
        usort($codes, IsmsClauseCatalog::compareCodes(...));
        foreach ($codes as $code) {
            $choices[(string) $code] = $code . ' ' . Control::getControlTitle((string) $code);
        }

        return $choices;
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
            'id' => 3, 'table' => $table, 'field' => 'is_sensitive',
            'name' => __('Sensitive activity', 'grcmanager'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $entityId = $this->isNewItem() ? Session::getActiveEntity() : (int) $this->fields['entities_id'];
        $assignments = $this->isNewItem() ? [] : (self::assignmentsFor([(int) $ID])[(int) $ID] ?? []);
        $labels = self::principalLabels($assignments);
        $alerts = [];
        if (!$this->isNewItem()) {
            foreach (self::matrix()['rows'] as $row) {
                if ($row['id'] === (int) $ID) {
                    $alerts = $row['alerts'];
                }
            }
        }
        $roleOrder = array_flip(Rules::ROLES);
        usort($assignments, static fn (array $a, array $b): int => $roleOrder[$a['role']] <=> $roleOrder[$b['role']]);
        foreach ($assignments as &$row) {
            $row['label'] = $labels[$row['itemtype'] . ':' . $row['items_id']] ?? '';
        }
        unset($row);

        TemplateRenderer::getInstance()->display('@grcmanager/responsibility_activity_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'default_label' => self::defaultLabels()[(string) ($this->fields['default_key'] ?? '')] ?? '',
            'controls'      => self::controlChoices($entityId),
            'codes'         => Rules::splitCodes((string) ($this->fields['control_codes'] ?? '')),
            'assignments'   => $assignments,
            'roles'         => self::getRoles(),
            'role_styles'   => self::roleStyles(),
            'alerts'        => $alerts,
            'entity_id'     => $entityId,
            'recursive'     => !empty($this->fields['is_recursive']),
            'user_label'    => User::getTypeName(1),
            'group_label'   => Group::getTypeName(1),
        ]);

        return true;
    }

    /**
     * Page de la matrice : alertes, tableau activités x personnes et groupes, exports archivés.
     */
    public static function showMatrix(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/responsibility_matrix.html.twig', [
            'matrix'      => self::matrix(),
            'roles'       => self::getRoles(),
            'role_styles' => self::roleStyles(),
            'can_create'  => self::canCreate(),
            'can_update'  => self::canUpdate(),
            'form_url'    => self::getFormURL(),
            'page_url'    => self::getSearchURL(),
            'export_url'  => self::exportUrl(),
            'exports'     => ResponsibilityExport::recent(),
        ]);
    }

    public static function exportUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/responsibilityactivity.export.php';
    }

    /**
     * Matrice en PDF (activités et rôles, alertes), pour l'entité active.
     */
    public static function renderPdf(): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/responsibility_matrix.html.twig', [
            'report_title'       => __('ISMS responsibility matrix', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'perimeter'          => Dropdown::getDropdownName(Entity::getTable(), Session::getActiveEntity()),
            'matrix'             => self::matrix(),
            'roles'              => self::getRoles(),
        ]);
    }
}
