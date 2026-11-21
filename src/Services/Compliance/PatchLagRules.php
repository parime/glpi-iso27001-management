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

namespace GlpiPlugin\Grcmanager\Services\Compliance;

/**
 * Issue #854 : retard des correctifs de sécurité des systèmes, d'après l'inventaire natif de GLPI.
 * Logique pure, sans dépendance à GLPI : mise à jour reconnue parmi les logiciels inventoriés,
 * criticité d'un actif d'après sa classification C/I/D (et son exposition sur Internet), délai
 * cible par criticité, état « à jour », « en retard » ou « inconnu », ordre de priorité,
 * synthèses.
 *
 * Le plugin lit seulement l'inventaire : il ne lance aucune mise à jour et ne modifie rien.
 */
final class PatchLagRules
{
    /** Criticité d'un actif pour le délai cible. */
    public const TIER_CRITICAL = 'critical';
    public const TIER_IMPORTANT = 'important';
    public const TIER_STANDARD = 'standard';
    public const TIERS = [self::TIER_CRITICAL, self::TIER_IMPORTANT, self::TIER_STANDARD];

    /** Délais cibles par défaut, en jours depuis le dernier correctif connu. */
    public const DEFAULT_TARGETS = [self::TIER_CRITICAL => 15, self::TIER_IMPORTANT => 30, self::TIER_STANDARD => 60];

    /** Bornes d'un délai cible saisi, en jours. */
    public const MIN_TARGET = 1;
    public const MAX_TARGET = 365;

    /** État d'un actif. */
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_LATE = 'late';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUSES = [self::STATUS_LATE, self::STATUS_UNKNOWN, self::STATUS_UP_TO_DATE];

    /** Origine de la date du dernier correctif. */
    public const SOURCE_UPDATE = 'update';
    public const SOURCE_PACKAGE = 'package';
    public const SOURCE_NONE = 'none';

    /** Inventaire plus ancien que ce nombre de jours : les données peuvent ne plus être à jour. */
    public const STALE_INVENTORY_DAYS = 30;

    /**
     * Catégories de logiciel que l'agent d'inventaire ou l'administrateur donne aux mises à jour,
     * en minuscules.
     */
    private const UPDATE_CATEGORIES = [
        'hotfix', 'hotfixes', 'update', 'updates', 'security update', 'security updates', 'patch', 'patches',
        'mise à jour', 'mises à jour', 'correctif', 'correctifs', 'actualización', 'aggiornamento',
    ];

    /**
     * Types d'ordinateur usuels d'un serveur (SMBIOS ou saisie), en minuscules.
     */
    private const SERVER_TYPES = [
        'server', 'serveur', 'servidor', 'rack mount chassis', 'rack mount', 'blade', 'blade enclosure',
        'multi-system chassis',
    ];

    /**
     * Le logiciel inventorié est-il une mise à jour du système ? Article de la base Microsoft
     * (« KB5031356 », « Mise à jour de sécurité (KB5031356) »), catégorie de mise à jour, ou logiciel
     * marqué « mise à jour » dans GLPI.
     */
    public static function isUpdateSoftware(string $name, string $category = '', bool $isUpdate = false): bool
    {
        if ($isUpdate) {
            return true;
        }
        if (preg_match('/(^|[^a-z0-9])kb\d{6,8}([^0-9]|$)/i', trim($name)) === 1) {
            return true;
        }
        $category = mb_strtolower(trim($category));
        if ($category === '') {
            return false;
        }
        // Catégorie arborescente de GLPI : « Système > Mises à jour ».
        $parts = array_map('trim', explode('>', $category));

        return in_array((string) end($parts), self::UPDATE_CATEGORIES, true);
    }

    /**
     * Famille Windows d'après le nom du système inventorié (mises à jour publiées comme articles KB).
     */
    public static function isWindows(string $osName): bool
    {
        return str_contains(mb_strtolower($osName), 'windows');
    }

    /**
     * Date du dernier correctif connu et son origine : la mise à jour la plus récente ; sinon, hors
     * Windows, l'installation de paquet la plus récente (le gestionnaire de paquets livre les
     * correctifs) ; sinon inconnue. Windows sans mise à jour inventoriée reste inconnu : une
     * application installée n'est pas un correctif du système.
     *
     * @return array{date: string, source: string}
     */
    public static function lastPatch(string $lastUpdate, string $lastPackage, string $osName): array
    {
        $lastUpdate = self::normalizeDate($lastUpdate);
        if ($lastUpdate !== '') {
            return ['date' => $lastUpdate, 'source' => self::SOURCE_UPDATE];
        }
        $lastPackage = self::normalizeDate($lastPackage);
        if ($lastPackage !== '' && trim($osName) !== '' && !self::isWindows($osName)) {
            return ['date' => $lastPackage, 'source' => self::SOURCE_PACKAGE];
        }

        return ['date' => '', 'source' => self::SOURCE_NONE];
    }

    /**
     * Date AAAA-MM-JJ valide (partie date d'une date et heure), sinon chaîne vide.
     */
    public static function normalizeDate(mixed $value): string
    {
        $date = is_string($value) ? substr(trim($value), 0, 10) : '';
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return '';
        }

        return $date;
    }

    /**
     * Jours écoulés depuis une date (0 pour une date future), null sans date.
     */
    public static function ageDays(string $date, string $today): ?int
    {
        $date = self::normalizeDate($date);
        $today = self::normalizeDate($today);
        if ($date === '' || $today === '') {
            return null;
        }
        $days = (int) floor((strtotime($today . ' 00:00:00 UTC') - strtotime($date . ' 00:00:00 UTC')) / 86400);

        return max(0, $days);
    }

    /**
     * Criticité d'un actif : le niveau le plus élevé de sa classification C/I/D (élevé : critique,
     * moyen : important, sinon standard). Un actif publié sur Internet est critique.
     */
    public static function tier(string $confidentiality, string $integrity, string $availability, bool $exposed): string
    {
        if ($exposed) {
            return self::TIER_CRITICAL;
        }
        $levels = [$confidentiality, $integrity, $availability];
        if (in_array('high', $levels, true)) {
            return self::TIER_CRITICAL;
        }

        return in_array('medium', $levels, true) ? self::TIER_IMPORTANT : self::TIER_STANDARD;
    }

    /**
     * Délais cibles relus d'une valeur stockée ou soumise : entiers bornés, défaut pour une valeur
     * absente ou invalide.
     *
     * @return array<string, int>
     */
    public static function normalizeTargets(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $targets = [];
        foreach (self::DEFAULT_TARGETS as $tier => $default) {
            $value = $raw[$tier] ?? null;
            $days = is_numeric($value) ? (int) $value : $default;
            $targets[$tier] = ($days >= self::MIN_TARGET && $days <= self::MAX_TARGET) ? $days : $default;
        }

        return $targets;
    }

    /**
     * État d'un actif : inconnu sans correctif daté, en retard au-delà du délai cible, sinon à jour.
     */
    public static function status(?int $ageDays, int $targetDays): string
    {
        if ($ageDays === null) {
            return self::STATUS_UNKNOWN;
        }

        return $ageDays > $targetDays ? self::STATUS_LATE : self::STATUS_UP_TO_DATE;
    }

    /**
     * Serveur d'après le type d'ordinateur dans GLPI ou le nom du système (« Windows Server »).
     */
    public static function isServer(string $typeName, string $osName): bool
    {
        $type = mb_strtolower(trim($typeName));
        $serverType = in_array($type, self::SERVER_TYPES, true)
            || str_contains($type, 'server')
            || str_contains($type, 'serveur');
        if ($serverType) {
            return true;
        }

        return preg_match('/\bserver\b/i', $osName) === 1;
    }

    /**
     * Inventaire ancien : jamais inventorié ou dernier inventaire au-delà de STALE_INVENTORY_DAYS.
     */
    public static function isStaleInventory(string $lastInventory, string $today): bool
    {
        $age = self::ageDays($lastInventory, $today);

        return $age === null || $age > self::STALE_INVENTORY_DAYS;
    }

    /**
     * Ordre de priorité : en retard d'abord, puis inconnus ; parmi eux les actifs exposés sur
     * Internet, puis les critiques, puis le retard le plus long, puis le nom.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sort(array $rows): array
    {
        $statusRank = array_flip(self::STATUSES);
        $tierRank = array_flip(self::TIERS);
        $key = static fn (array $row): array => [
            $statusRank[$row['status']] ?? 9,
            empty($row['exposed']) ? 1 : 0,
            $tierRank[$row['tier']] ?? 9,
            -((int) ($row['overdue'] ?? 0)),
            mb_strtolower((string) ($row['name'] ?? '')),
        ];
        usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $rows;
    }

    /**
     * Compteurs par état, taux d'actifs à jour (parmi les états connus) et serveurs critiques en
     * retard.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{late: int, unknown: int, up_to_date: int, total: int, rate: ?int,
     *     late_critical_servers: int, late_exposed: int}
     */
    public static function summary(array $rows): array
    {
        $summary = [
            self::STATUS_LATE => 0, self::STATUS_UNKNOWN => 0, self::STATUS_UP_TO_DATE => 0,
            'total' => count($rows), 'rate' => null, 'late_critical_servers' => 0, 'late_exposed' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            if ($status !== self::STATUS_LATE) {
                continue;
            }
            if (!empty($row['is_server']) && $row['tier'] === self::TIER_CRITICAL) {
                $summary['late_critical_servers']++;
            }
            if (!empty($row['exposed'])) {
                $summary['late_exposed']++;
            }
        }
        $known = $summary[self::STATUS_LATE] + $summary[self::STATUS_UP_TO_DATE];
        $summary['rate'] = $known === 0 ? null : (int) floor(100 * $summary[self::STATUS_UP_TO_DATE] / $known);

        return $summary;
    }

    /**
     * Synthèse par criticité, dans l'ordre critique, important, standard.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{late: int, unknown: int, up_to_date: int, total: int, rate: ?int,
     *     late_critical_servers: int, late_exposed: int}>
     */
    public static function byTier(array $rows): array
    {
        $groups = [];
        foreach (self::TIERS as $tier) {
            $groups[$tier] = self::summary(array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['tier'] === $tier
            )));
        }

        return $groups;
    }
}
