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
 * Issue #847 : chiffrement des disques du parc d'après l'inventaire natif de GLPI (état, outil et
 * algorithme de chaque volume). Logique pure, sans dépendance à GLPI : état d'un ordinateur à partir
 * de ses volumes, portable ou non d'après son type, règle « doit être chiffré », algorithme faible
 * ou ancien, regroupements du taux par entité, type et lieu.
 *
 * Le plugin ne lit ni ne conserve jamais de clé de récupération : seule une déclaration (sauvegardée
 * ou non, à quelle date) saisie par l'administrateur existe, voir DiskEncryption.
 */
final class DiskEncryptionRules
{
    /** États d'un volume, mêmes valeurs que Item_Disk::ENCRYPTION_STATUS_*. */
    public const DISK_NO = 0;
    public const DISK_YES = 1;
    public const DISK_PARTIALLY = 2;

    /** État d'un ordinateur. */
    public const STATUS_ENCRYPTED = 'encrypted';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_CLEAR = 'clear';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUSES = [self::STATUS_ENCRYPTED, self::STATUS_PARTIAL, self::STATUS_CLEAR, self::STATUS_UNKNOWN];

    /** Ordinateurs qui doivent être chiffrés. */
    public const SCOPE_ALL = 'all';
    public const SCOPE_LAPTOPS = 'laptops';
    public const SCOPE_TYPES = 'types';
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_LAPTOPS, self::SCOPE_TYPES];

    /** Force de l'algorithme déclaré par l'inventaire. */
    public const ALGO_UNKNOWN = 'unknown';
    public const ALGO_OK = 'ok';
    public const ALGO_LEGACY = 'legacy';
    public const ALGO_WEAK = 'weak';

    /** Seuils de confidentialité (classification C/I/D) qui rendent le chiffrement obligatoire. */
    public const CONFIDENTIALITY_THRESHOLDS = ['', 'medium', 'high'];

    /**
     * Types de châssis que l'agent d'inventaire remonte pour un appareil mobile (SMBIOS), et noms
     * usuels saisis à la main, en minuscules.
     */
    private const LAPTOP_TYPES = [
        'laptop', 'notebook', 'portable', 'sub notebook', 'subnotebook', 'netbook', 'convertible',
        'detachable', 'tablet', 'hand held', 'handheld', 'ordinateur portable', 'portátil', 'portatil',
        'portatile', 'laptop computer', 'tragbar', 'mini notebook', '2-in-1', '2 in 1',
    ];

    /**
     * État d'un ordinateur d'après l'état de ses volumes chiffrables : inconnu sans volume inventorié,
     * chiffré si tous le sont, partiel si au moins un l'est (ou l'est partiellement), sinon en clair.
     *
     * @param list<int> $diskStatuses
     */
    public static function computerStatus(array $diskStatuses): string
    {
        if ($diskStatuses === []) {
            return self::STATUS_UNKNOWN;
        }
        $encrypted = count(array_filter($diskStatuses, static fn (int $s): bool => $s === self::DISK_YES));
        if ($encrypted === count($diskStatuses)) {
            return self::STATUS_ENCRYPTED;
        }
        if ($encrypted > 0 || in_array(self::DISK_PARTIALLY, $diskStatuses, true)) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_CLEAR;
    }

    /**
     * Type d'ordinateur mobile (portable, tablette…), d'après le nom de son type dans GLPI.
     */
    public static function isLaptopType(string $typeName): bool
    {
        $name = mb_strtolower(trim($typeName));

        return $name !== '' && in_array($name, self::LAPTOP_TYPES, true);
    }

    /**
     * Force d'un algorithme déclaré : faible (DES, RC4…), ancien (BitLocker avec diffuseur, mode CBC
     * sans XTS), correct, ou inconnu quand l'inventaire ne le remonte pas.
     */
    public static function algorithmStrength(string $algorithm): string
    {
        $value = strtolower(trim($algorithm));
        if ($value === '') {
            return self::ALGO_UNKNOWN;
        }
        $weak = '/(^|[^a-z0-9])(3?des|tripledes|triple[-_ ]des|rc2|rc4|blowfish|cast5?|idea|md5)([^a-z0-9]|$)/';
        if (preg_match($weak, $value) === 1) {
            return self::ALGO_WEAK;
        }
        if (str_contains($value, 'diffuser') || (str_contains($value, 'cbc') && !str_contains($value, 'xts'))) {
            return self::ALGO_LEGACY;
        }

        return self::ALGO_OK;
    }

    /**
     * Force la plus faible parmi plusieurs algorithmes (volumes d'un même ordinateur).
     *
     * @param list<string> $algorithms
     */
    public static function weakestStrength(array $algorithms): string
    {
        $order = [self::ALGO_WEAK => 0, self::ALGO_LEGACY => 1, self::ALGO_OK => 2, self::ALGO_UNKNOWN => 3];
        $weakest = self::ALGO_UNKNOWN;
        foreach ($algorithms as $algorithm) {
            $strength = self::algorithmStrength($algorithm);
            if ($order[$strength] < $order[$weakest]) {
                $weakest = $strength;
            }
        }

        return $weakest;
    }

    /**
     * Règle « doit être chiffré » relue d'une valeur stockée ou soumise : portée connue, types en
     * entiers positifs uniques, seuil de confidentialité connu. Par défaut : tous les ordinateurs
     * (comportement du contrôle technique avant la règle réglable).
     *
     * @return array{scope: string, types: list<int>, confidentiality: string}
     */
    public static function normalizeRule(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $scope = in_array($raw['scope'] ?? null, self::SCOPES, true) ? (string) $raw['scope'] : self::SCOPE_ALL;
        $types = [];
        foreach ((array) ($raw['types'] ?? []) as $type) {
            if (is_scalar($type) && (int) $type > 0) {
                $types[(int) $type] = (int) $type;
            }
        }
        ksort($types);
        $confidentiality = in_array($raw['confidentiality'] ?? '', self::CONFIDENTIALITY_THRESHOLDS, true)
            ? (string) $raw['confidentiality'] : '';

        return ['scope' => $scope, 'types' => array_values($types), 'confidentiality' => $confidentiality];
    }

    /**
     * L'ordinateur doit-il être chiffré ? Selon la portée choisie, ou dès que sa confidentialité
     * atteint le seuil réglé (classification C/I/D du plugin).
     *
     * @param array{scope: string, types: list<int>, confidentiality: string} $rule
     */
    public static function isRequired(array $rule, bool $isLaptop, int $typeId, string $confidentiality = ''): bool
    {
        $byScope = match ($rule['scope']) {
            self::SCOPE_LAPTOPS => $isLaptop,
            self::SCOPE_TYPES   => $typeId > 0 && in_array($typeId, $rule['types'], true),
            default             => true,
        };
        if ($byScope) {
            return true;
        }
        $levels = ['low' => 1, 'medium' => 2, 'high' => 3];

        return $rule['confidentiality'] !== ''
            && ($levels[$confidentiality] ?? 0) >= $levels[$rule['confidentiality']];
    }

    /**
     * Écart : ordinateur concerné dont un volume n'est pas chiffré, ou chiffré avec un algorithme
     * faible. Un état inconnu (aucun volume inventorié) n'est pas évalué, il est compté à part.
     */
    public static function isGap(bool $required, string $status, string $strength): bool
    {
        return $required
            && ($status === self::STATUS_PARTIAL || $status === self::STATUS_CLEAR
                || ($status === self::STATUS_ENCRYPTED && $strength === self::ALGO_WEAK));
    }

    /**
     * Compteurs par état et taux de chiffrement (chiffrés parmi les états connus), par valeur d'une
     * clé de regroupement (entité, type, lieu), triés par libellé.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, encrypted: int, partial: int, clear: int, unknown: int, total: int,
     *     rate: ?int}>
     */
    public static function breakdown(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $label = (string) ($row[$key] ?? '');
            $groups[$label] ??= [
                'label' => $label, self::STATUS_ENCRYPTED => 0, self::STATUS_PARTIAL => 0,
                self::STATUS_CLEAR => 0, self::STATUS_UNKNOWN => 0, 'total' => 0, 'rate' => null,
            ];
            $groups[$label][(string) $row['status']]++;
            $groups[$label]['total']++;
        }
        foreach ($groups as &$group) {
            $group['rate'] = self::rate($group);
        }
        unset($group);
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($groups);
    }

    /**
     * Synthèse de toutes les lignes (mêmes compteurs que breakdown()).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{encrypted: int, partial: int, clear: int, unknown: int, total: int, rate: ?int}
     */
    public static function summary(array $rows): array
    {
        $summary = [
            self::STATUS_ENCRYPTED => 0, self::STATUS_PARTIAL => 0, self::STATUS_CLEAR => 0,
            self::STATUS_UNKNOWN => 0, 'total' => count($rows), 'rate' => null,
        ];
        foreach ($rows as $row) {
            $summary[(string) $row['status']]++;
        }
        $summary['rate'] = self::rate($summary);

        return $summary;
    }

    /**
     * Taux de chiffrement en % parmi les états connus, null si aucun n'est connu.
     *
     * @param array<string, mixed> $counts
     */
    public static function rate(array $counts): ?int
    {
        $known = (int) $counts[self::STATUS_ENCRYPTED] + (int) $counts[self::STATUS_PARTIAL]
            + (int) $counts[self::STATUS_CLEAR];

        return $known === 0 ? null : (int) floor(100 * (int) $counts[self::STATUS_ENCRYPTED] / $known);
    }

    /**
     * Date de sauvegarde déclarée de la clé de récupération : AAAA-MM-JJ valide et pas dans le futur,
     * sinon null.
     */
    public static function normalizeBackupDate(mixed $value, string $today): ?string
    {
        $date = is_string($value) ? trim($value) : '';
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return null;
        }

        return $date <= $today ? $date : null;
    }
}
