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

namespace GlpiPlugin\Grcmanager\Services\Responsibility;

/**
 * Issue #612 : règles de la matrice des responsabilités du SMSI (ISO 27001, article 5.3 et mesure
 * A.5.3), sans GLPI (ni $DB, ni __()) pour être testées seules.
 *
 * Pour chaque activité, un utilisateur ou un groupe de GLPI réalise, approuve, est consulté ou est
 * informé. Une seule personne ou un seul groupe approuve. Les alertes ne font que signaler : rien
 * n'est jamais modifié automatiquement (affectations, droits, statuts).
 */
final class ResponsibilityRules
{
    public const ROLE_RESPONSIBLE = 'responsible';
    public const ROLE_ACCOUNTABLE = 'accountable';
    public const ROLE_CONSULTED = 'consulted';
    public const ROLE_INFORMED = 'informed';

    /** @var list<string> */
    public const ROLES = [self::ROLE_RESPONSIBLE, self::ROLE_ACCOUNTABLE, self::ROLE_CONSULTED, self::ROLE_INFORMED];

    /** @var list<string> */
    public const PRINCIPALS = ['User', 'Group'];

    public const ALERT_NO_ACCOUNTABLE = 'no_accountable';
    public const ALERT_SEVERAL_ACCOUNTABLE = 'several_accountable';
    public const ALERT_NO_RESPONSIBLE = 'no_responsible';
    public const ALERT_SEGREGATION = 'segregation';
    public const ALERT_EMPTY_GROUP = 'empty_group';
    public const ALERT_INACTIVE_USER = 'inactive_user';

    /** Gravité de chaque alerte : high (à corriger avant l'audit) ou medium. */
    public const SEVERITIES = [
        self::ALERT_NO_ACCOUNTABLE      => 'high',
        self::ALERT_SEVERAL_ACCOUNTABLE => 'high',
        self::ALERT_SEGREGATION         => 'high',
        self::ALERT_NO_RESPONSIBLE      => 'medium',
        self::ALERT_EMPTY_GROUP         => 'medium',
        self::ALERT_INACTIVE_USER       => 'medium',
    ];

    /** Mesures de l'Annexe A : « A.5.3 », « A.8.13 »... */
    private const CODE_PATTERN = '/^A\.[5-8]\.\d{1,2}$/';

    public static function normalizeRole(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::ROLES, true) ? $value : null;
    }

    public static function normalizePrincipal(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::PRINCIPALS, true) ? $value : null;
    }

    /**
     * Codes de mesures valides, uniques et triés, séparés par des virgules (colonne control_codes).
     *
     * @param mixed $value liste de codes ou chaîne « A.5.3,A.5.15 »
     */
    public static function normalizeCodes(mixed $value): string
    {
        $codes = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
        $valid = [];
        foreach ($codes as $code) {
            $code = is_scalar($code) ? trim((string) $code) : '';
            if (preg_match(self::CODE_PATTERN, $code) === 1) {
                $valid[$code] = true;
            }
        }
        $valid = array_keys($valid);
        usort($valid, static fn (string $a, string $b): int => strnatcmp($a, $b));

        return implode(',', $valid);
    }

    /**
     * @return list<string>
     */
    public static function splitCodes(string $codes): array
    {
        return $codes === '' ? [] : explode(',', self::normalizeCodes($codes));
    }

    /**
     * Peut-on ajouter cette affectation ? Non si elle existe déjà, ou si quelqu'un d'autre approuve
     * déjà l'activité (une seule personne ou un seul groupe approuve).
     *
     * @param list<array{itemtype: string, items_id: int, role: string}> $assignments
     * @param array{itemtype: string, items_id: int, role: string} $new
     * @return 'ok'|'duplicate'|'accountable_taken'
     */
    public static function checkNew(array $assignments, array $new): string
    {
        foreach ($assignments as $row) {
            $same = $row['itemtype'] === $new['itemtype'] && (int) $row['items_id'] === (int) $new['items_id'];
            if ($same && $row['role'] === $new['role']) {
                return 'duplicate';
            }
            if ($new['role'] === self::ROLE_ACCOUNTABLE && $row['role'] === self::ROLE_ACCOUNTABLE) {
                return 'accountable_taken';
            }
        }

        return 'ok';
    }

    /**
     * Personnes qui réalisent et approuvent la même activité (directement ou par un groupe), et
     * groupe qui fait les deux. Seuls les utilisateurs actifs comptent pour les groupes.
     *
     * @param list<array{itemtype: string, items_id: int, role: string}> $assignments
     * @param array<int, list<int>> $groupMembers id du groupe => utilisateurs membres
     * @return array{users: list<int>, groups: list<int>}
     */
    public static function segregationConflicts(array $assignments, array $groupMembers): array
    {
        $people = [self::ROLE_RESPONSIBLE => [], self::ROLE_ACCOUNTABLE => []];
        $groups = [self::ROLE_RESPONSIBLE => [], self::ROLE_ACCOUNTABLE => []];
        foreach ($assignments as $row) {
            $role = $row['role'];
            if (!isset($people[$role])) {
                continue;
            }
            $id = (int) $row['items_id'];
            if ($row['itemtype'] === 'Group') {
                $groups[$role][$id] = true;
                foreach ($groupMembers[$id] ?? [] as $userId) {
                    $people[$role][(int) $userId] = true;
                }
            } else {
                $people[$role][$id] = true;
            }
        }
        $users = array_keys(array_intersect_key($people[self::ROLE_RESPONSIBLE], $people[self::ROLE_ACCOUNTABLE]));
        $sameGroups = array_keys(array_intersect_key($groups[self::ROLE_RESPONSIBLE], $groups[self::ROLE_ACCOUNTABLE]));
        sort($users);
        sort($sameGroups);

        return ['users' => $users, 'groups' => $sameGroups];
    }

    /**
     * Alertes d'une activité.
     *
     * @param array{is_sensitive?: mixed} $activity
     * @param list<array{itemtype: string, items_id: int, role: string}> $assignments
     * @param array<int, list<int>> $groupMembers id du groupe => utilisateurs actifs membres (groupe
     *     absent de GLPI : absent du tableau)
     * @param array<int, bool> $activeUsers id => true pour chaque utilisateur actif
     * @return list<array{type: string, severity: string, itemtype?: string, items_id?: int, users?: list<int>,
     *     groups?: list<int>}>
     */
    public static function alerts(array $activity, array $assignments, array $groupMembers, array $activeUsers): array
    {
        $alerts = [];
        $roles = array_count_values(array_column($assignments, 'role'));
        $accountable = $roles[self::ROLE_ACCOUNTABLE] ?? 0;
        if ($accountable === 0) {
            $alerts[] = self::alert(self::ALERT_NO_ACCOUNTABLE);
        } elseif ($accountable > 1) {
            $alerts[] = self::alert(self::ALERT_SEVERAL_ACCOUNTABLE);
        }
        if (($roles[self::ROLE_RESPONSIBLE] ?? 0) === 0) {
            $alerts[] = self::alert(self::ALERT_NO_RESPONSIBLE);
        }
        if (!empty($activity['is_sensitive'])) {
            $conflicts = self::segregationConflicts($assignments, $groupMembers);
            if ($conflicts['users'] !== [] || $conflicts['groups'] !== []) {
                $alerts[] = self::alert(self::ALERT_SEGREGATION) + $conflicts;
            }
        }
        $seen = [];
        foreach ($assignments as $row) {
            $id = (int) $row['items_id'];
            $key = $row['itemtype'] . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($row['itemtype'] === 'Group' && ($groupMembers[$id] ?? []) === []) {
                $alerts[] = self::alert(self::ALERT_EMPTY_GROUP) + ['itemtype' => 'Group', 'items_id' => $id];
            } elseif ($row['itemtype'] === 'User' && !isset($activeUsers[$id])) {
                $alerts[] = self::alert(self::ALERT_INACTIVE_USER) + ['itemtype' => 'User', 'items_id' => $id];
            }
        }

        return $alerts;
    }

    /**
     * @return array{type: string, severity: string}
     */
    private static function alert(string $type): array
    {
        return ['type' => $type, 'severity' => self::SEVERITIES[$type]];
    }
}
