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

namespace GlpiPlugin\Grcmanager\Services\People;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AccountHygiene;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidence;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidenceProvider;
use GlpiPlugin\Grcmanager\Training;

/**
 * Partie dépendante de GLPI (global $DB) de l'issue #546 : lit, pour un ensemble de comptes, les
 * données déjà tenues par le plugin (formations, prise de connaissance des politiques, revues des
 * accès, hygiène des comptes, fiche du collaborateur) et la charte signée dans assetsign quand ce
 * plugin est présent. Lecture seule, quelques requêtes groupées quel que soit le nombre de comptes.
 * Voir PersonnelComplianceRules pour l'évaluation.
 */
final class PersonnelComplianceProvider
{
    public const RECORDS_TABLE = 'glpi_plugin_grcmanager_personnelcompliances';
    private const TRAININGS_USERS_TABLE = 'glpi_plugin_grcmanager_trainings_users';

    /**
     * Comptes actifs suivis : habilités dans les entités actives de la session ($restrict), ou dans
     * toutes les entités (tâche planifiée).
     *
     * @param array{entities_id?: int, groups_id?: int, users_id?: list<int>} $filters
     * @return array<int, array<string, mixed>> indexé par id
     */
    public static function users(array $filters = [], bool $restrict = true): array
    {
        global $DB;

        $where = ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0];
        if ($restrict) {
            $where += getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true);
        }
        if (($filters['entities_id'] ?? -1) >= 0) {
            $where[] = [
                'glpi_profiles_users.entities_id' => array_values(getSonsOf('glpi_entities', $filters['entities_id'])),
            ];
        }
        if (isset($filters['users_id'])) {
            $where['glpi_users.id'] = $filters['users_id'] === [] ? [0] : $filters['users_id'];
        }
        $criteria = [
            'SELECT'     => [
                'glpi_users.id', 'glpi_users.name', 'glpi_users.firstname', 'glpi_users.realname',
                'glpi_users.begin_date', 'glpi_users.end_date', 'glpi_users.date_creation',
                'glpi_users.last_login', 'glpi_users.is_deleted_ldap', 'glpi_users.users_id_supervisor',
                'glpi_users.entities_id',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => ['glpi_users.realname', 'glpi_users.firstname', 'glpi_users.name'],
        ];
        if (($filters['groups_id'] ?? 0) > 0) {
            $criteria['INNER JOIN']['glpi_groups_users'] = [
                'ON' => ['glpi_groups_users' => 'users_id', 'glpi_users' => 'id'],
            ];
            $criteria['WHERE']['glpi_groups_users.groups_id'] = $filters['groups_id'];
        }

        $users = [];
        foreach ($DB->request($criteria) as $row) {
            $users[(int) $row['id']] = $row;
        }

        return $users;
    }

    /**
     * Faits de chaque compte, au format attendu par PersonnelComplianceRules::evaluate().
     *
     * @param array<int, array<string, mixed>> $users résultat de users()
     * @return array<int, array<string, mixed>>
     */
    public static function facts(array $users, string $now): array
    {
        $ids = array_keys($users);
        if ($ids === []) {
            return [];
        }
        $records = self::records($ids);
        $trainings = self::trainings($ids);
        $policies = self::policies($ids);
        $reviews = self::accessReviews($ids);
        $charters = self::charters($ids);

        $facts = [];
        foreach ($users as $id => $user) {
            $record = $records[$id] ?? [];
            $begin = (string) ($user['begin_date'] ?? '');
            $facts[$id] = [
                'arrival'        => $begin !== '' ? $begin : ($user['date_creation'] ?? null),
                'screening'      => [
                    'status' => (string) ($record['screening_status'] ?? ''),
                    'date'   => $record['screening_date'] ?? null,
                ],
                'nda'            => [
                    'date'    => $record['nda_date'] ?? null,
                    'version' => (string) ($record['nda_version'] ?? ''),
                ],
                'trainings'      => array_values($trainings[$id] ?? []),
                'charter'        => $charters === null ? null : ($charters[$id] ?? ['status' => 'none']),
                'policies'       => $policies[$id] ?? [],
                'access_review'  => $reviews[$id] ?? [],
                // Seuls les contrôles sérieux d'hygiène (compte qui ne devrait plus être actif) sont
                // un écart : un collaborateur peut très bien ne jamais se connecter à GLPI.
                'account_checks' => array_values(array_filter(
                    AccountHygieneRules::checks($user, $now, AccountHygieneRules::DEFAULT_INACTIVITY_DAYS),
                    static fn (string $check): bool => AccountHygiene::isSerious($check)
                )),
            ];
        }

        return $facts;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function records(array $ids): array
    {
        global $DB;

        $records = [];
        foreach ($DB->request(['FROM' => self::RECORDS_TABLE, 'WHERE' => ['users_id' => $ids]]) as $row) {
            $records[(int) $row['users_id']] = $row;
        }

        return $records;
    }

    /**
     * Formations obligatoires, sessions regroupées sous leur formation principale : une session
     * suivie suffit, la plus récente compte pour le renouvellement.
     *
     * @param list<int> $ids
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function trainings(array $ids): array
    {
        global $DB;

        $trainings = Training::getTable();
        $rank = ['pending' => 0, 'exempted' => 1, 'completed' => 2];
        $byUser = [];
        $mainIds = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'l.users_id', 'l.completion_status', 'l.completion_date', 'l.date_creation',
                    't.id', 't.title', 't.plugin_grcmanager_trainings_id', 't.renewal_period_months',
                ],
                'FROM'       => self::TRAININGS_USERS_TABLE . ' AS l',
                'INNER JOIN' => [
                    $trainings . ' AS t' => ['FKEY' => ['l' => 'plugin_grcmanager_trainings_id', 't' => 'id']],
                ],
                'WHERE'      => ['l.users_id' => $ids, 't.is_mandatory' => 1],
                'ORDER'      => ['l.completion_date DESC'],
            ]) as $row
        ) {
            $main = (int) $row['plugin_grcmanager_trainings_id'] ?: (int) $row['id'];
            $mainIds[$main] = true;
            $entry = [
                'title'           => (string) $row['title'],
                'status'          => (string) $row['completion_status'],
                'completion_date' => $row['completion_date'],
                'assigned'        => $row['date_creation'],
                'renewal_months'  => (int) $row['renewal_period_months'],
            ];
            $current = $byUser[(int) $row['users_id']][$main] ?? null;
            if ($current === null || ($rank[$entry['status']] ?? 0) > ($rank[$current['status']] ?? 0)) {
                $byUser[(int) $row['users_id']][$main] = $entry;
            }
        }
        if ($mainIds !== []) {
            $titles = [];
            $rows = $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => $trainings,
                'WHERE'  => ['id' => array_keys($mainIds)],
            ]);
            foreach ($rows as $row) {
                $titles[(int) $row['id']] = (string) $row['title'];
            }
            foreach ($byUser as $userId => $entries) {
                foreach (array_keys($entries) as $main) {
                    $byUser[$userId][$main]['title'] = $titles[$main] ?? $byUser[$userId][$main]['title'];
                }
            }
        }

        return $byUser;
    }

    /**
     * Campagne en vigueur (la plus récente) de chaque politique dont la personne fait partie.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private static function policies(array $ids): array
    {
        global $DB;

        $current = [];
        foreach (
            $DB->request([
                'SELECT'  => ['plugin_grcmanager_policies_id', 'MAX' => 'id AS id'],
                'FROM'    => PolicyAcknowledgement::CAMPAIGNS_TABLE,
                'GROUPBY' => 'plugin_grcmanager_policies_id',
            ]) as $row
        ) {
            $current[] = (int) $row['id'];
        }
        if ($current === []) {
            return [];
        }

        $policies = [];
        foreach (
            $DB->request([
                'SELECT'     => ['l.users_id', 'l.date_acknowledged', 'c.version', 'c.due_date', 'p.title'],
                'FROM'       => PolicyAcknowledgement::LINES_TABLE . ' AS l',
                'INNER JOIN' => [
                    PolicyAcknowledgement::CAMPAIGNS_TABLE . ' AS c' => [
                        'FKEY' => ['l' => 'plugin_grcmanager_policycampaigns_id', 'c' => 'id'],
                    ],
                    Policy::getTable() . ' AS p' => ['FKEY' => ['c' => 'plugin_grcmanager_policies_id', 'p' => 'id']],
                ],
                'WHERE'      => ['l.users_id' => $ids, 'c.id' => $current],
                'ORDER'      => ['p.title'],
            ]) as $row
        ) {
            $policies[(int) $row['users_id']][] = [
                'title'        => (string) $row['title'],
                'version'      => (string) $row['version'],
                'due_date'     => $row['due_date'],
                'acknowledged' => $row['date_acknowledged'],
            ];
        }

        return $policies;
    }

    /**
     * Dernière décision de revue des accès et échéance de la revue en attente.
     *
     * @param list<int> $ids
     * @return array<int, array{last_decision: ?string, pending_due: ?string}>
     */
    private static function accessReviews(array $ids): array
    {
        global $DB;

        $reviews = [];
        foreach (
            $DB->request([
                'SELECT'     => ['l.users_id', 'l.decision', 'l.date_decision', 'c.status', 'c.due_date'],
                'FROM'       => AccessReview::LINES_TABLE . ' AS l',
                'INNER JOIN' => [
                    AccessReview::getTable() . ' AS c' => [
                        'FKEY' => ['l' => 'plugin_grcmanager_accessreviews_id', 'c' => 'id'],
                    ],
                ],
                'WHERE'      => ['l.users_id' => $ids, 'c.status' => ['open', 'closed']],
            ]) as $row
        ) {
            $id = (int) $row['users_id'];
            $reviews[$id] ??= ['last_decision' => null, 'pending_due' => null];
            if ($row['decision'] !== 'pending' && !empty($row['date_decision'])) {
                if ($reviews[$id]['last_decision'] === null || $row['date_decision'] > $reviews[$id]['last_decision']) {
                    $reviews[$id]['last_decision'] = (string) $row['date_decision'];
                }
            } elseif ($row['decision'] === 'pending' && $row['status'] === 'open' && !empty($row['due_date'])) {
                if ($reviews[$id]['pending_due'] === null || $row['due_date'] < $reviews[$id]['pending_due']) {
                    $reviews[$id]['pending_due'] = (string) $row['due_date'];
                }
            }
        }

        return $reviews;
    }

    /**
     * Charte signée dans assetsign (campagne de charte la plus récente de la personne), ou null si
     * assetsign est absent ou antérieur aux campagnes de charte : la ligne est alors « non suivie ».
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>|null
     */
    public static function charters(array $ids): ?array
    {
        global $DB;

        if (!self::charterAvailable()) {
            return null;
        }
        $campaigns = AssetsignEvidence::CAMPAIGNS_TABLE;
        $attestations = AssetsignEvidence::ATTESTATIONS_TABLE;
        $charters = [];
        foreach (
            $DB->request([
                'SELECT'     => ["$attestations.users_id", "$attestations.status", "$attestations.date_answered",
                    "$attestations.date_creation", "$campaigns.name"],
                'FROM'       => $attestations,
                'INNER JOIN' => [
                    $campaigns => ['FKEY' => [$attestations => 'plugin_assetsign_campaigns_id', $campaigns => 'id']],
                ],
                'WHERE'      => [
                    "$attestations.users_id" => $ids,
                    "$campaigns.type"        => AssetsignEvidence::CAMPAIGN_TYPE_CHARTER,
                ],
                'ORDER'      => ["$campaigns.id DESC"],
            ]) as $row
        ) {
            $id = (int) $row['users_id'];
            if (isset($charters[$id])) {
                continue;
            }
            $signed = (int) $row['status'] === AssetsignEvidence::ATTESTATION_SIGNED;
            $charters[$id] = [
                'status'   => $signed ? 'signed' : 'pending',
                'date'     => $signed ? $row['date_answered'] : null,
                'assigned' => $row['date_creation'],
                'campaign' => (string) $row['name'],
            ];
        }

        return $charters;
    }

    public static function charterAvailable(): bool
    {
        global $DB;

        return AssetsignEvidenceProvider::isAvailable()
            && $DB->tableExists(AssetsignEvidence::ATTESTATIONS_TABLE)
            && $DB->fieldExists(AssetsignEvidence::CAMPAIGNS_TABLE, 'type');
    }

    /**
     * Groupes de chaque compte (agrégation par service).
     *
     * @param list<int> $ids
     * @return array<int, list<int>>
     */
    public static function groups(array $ids): array
    {
        global $DB;

        if ($ids === []) {
            return [];
        }
        $groups = [];
        $rows = $DB->request([
            'SELECT' => ['users_id', 'groups_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['users_id' => $ids],
        ]);
        foreach ($rows as $row) {
            $groups[(int) $row['users_id']][] = (int) $row['groups_id'];
        }

        return $groups;
    }
}
