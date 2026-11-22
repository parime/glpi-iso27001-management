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

namespace GlpiPlugin\Grcmanager\Services\ApprovedApplication;

/**
 * Issue #622 : règles du registre des applications et services en ligne approuvés (mesure A.5.23).
 *
 * - Statut d'usage : en évaluation, approuvée, approuvée avec restrictions, refusée.
 * - Hébergement : en ligne (service en nuage, SaaS) ou sur site.
 * - Niveau maximal de confidentialité des données autorisé, sur l'échelle de la classification C/I/D
 *   du plugin (faible, moyen, élevé).
 * - Revue périodique : tous les N mois depuis la dernière revue (à défaut, le début d'usage) ;
 *   « bientôt » 30 jours avant l'échéance, « en retard » ensuite. Une application refusée ou dont
 *   l'usage a pris fin n'a plus de revue.
 * - Liste publiée aux utilisateurs : applications approuvées (avec ou sans restrictions) dont
 *   l'usage a commencé et n'a pas pris fin.
 * - Rapprochement avec l'inventaire : un logiciel inventorié correspond à une application du
 *   registre par le logiciel lié, sinon par le nom (comparaison sans casse, accents ni ponctuation).
 *
 * PHP pur (aucune dépendance à GLPI), testé unitairement.
 */
final class ApprovedApplicationRules
{
    public const STATUS_EVALUATION = 'evaluation';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_REFUSED = 'refused';
    public const STATUSES = [
        self::STATUS_EVALUATION, self::STATUS_APPROVED, self::STATUS_RESTRICTED, self::STATUS_REFUSED,
    ];

    public const HOSTING_UNKNOWN = '';
    public const HOSTING_ONLINE = 'online';
    public const HOSTING_ONPREMISE = 'onpremise';
    public const HOSTINGS = [self::HOSTING_ONLINE, self::HOSTING_ONPREMISE];

    /** Niveaux de la classification C/I/D du plugin (ClassificationLevels), du plus faible au plus fort. */
    public const LEVELS = ['low', 'medium', 'high'];

    public const DEFAULT_REVIEW_MONTHS = 12;
    public const MAX_REVIEW_MONTHS = 60;

    /** Prévenance de l'échéance de revue (jours). */
    public const SOON_DAYS = 30;

    public const REVIEW_NONE = 'none';
    public const REVIEW_OK = 'ok';
    public const REVIEW_SOON = 'soon';
    public const REVIEW_OVERDUE = 'overdue';

    /** Écarts d'une fiche approuvée. */
    public const GAP_NO_OWNER = 'no_owner';
    public const GAP_NO_USES = 'no_uses';
    public const GAP_NO_LEVEL = 'no_level';
    public const GAP_NO_RESTRICTIONS = 'no_restrictions';

    public static function sanitizeStatus(mixed $value): string
    {
        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : self::STATUS_EVALUATION;
    }

    public static function sanitizeHosting(mixed $value): string
    {
        return is_string($value) && in_array($value, self::HOSTINGS, true) ? $value : self::HOSTING_UNKNOWN;
    }

    public static function sanitizeLevel(mixed $value): string
    {
        return is_string($value) && in_array($value, self::LEVELS, true) ? $value : '';
    }

    /** Cycle de revue en mois : 0 (aucune revue périodique) à 60, 12 par défaut. */
    public static function sanitizeMonths(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)) {
            return self::DEFAULT_REVIEW_MONTHS;
        }

        return max(0, min(self::MAX_REVIEW_MONTHS, (int) $value));
    }

    /** Date AAAA-MM-JJ valide, sinon null. */
    public static function sanitizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = substr(trim($value), 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * Identifiants entiers positifs, sans doublon (liste de dérogations reçue du formulaire).
     *
     * @return list<int>
     */
    public static function sanitizeIds(mixed $value): array
    {
        $ids = [];
        foreach ((array) $value as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /** @return list<int> */
    public static function decodeIds(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::sanitizeIds($decoded) : [];
    }

    /** @param list<int> $ids */
    public static function encodeIds(array $ids): string
    {
        return $ids === [] ? '' : (string) json_encode(array_values($ids));
    }

    /** L'usage a-t-il pris fin (date de fin passée) ? */
    public static function hasEnded(?string $dateEnd, string $today): bool
    {
        return $dateEnd !== null && $dateEnd !== '' && substr($dateEnd, 0, 10) < $today;
    }

    /** Application approuvée (avec ou sans restrictions). */
    public static function isApproved(string $status): bool
    {
        return in_array($status, [self::STATUS_APPROVED, self::STATUS_RESTRICTED], true);
    }

    /**
     * Visible dans la liste publiée aux utilisateurs : approuvée, usage commencé et non terminé.
     */
    public static function isPublished(string $status, ?string $dateStart, ?string $dateEnd, string $today): bool
    {
        if (!self::isApproved($status) || self::hasEnded($dateEnd, $today)) {
            return false;
        }

        return $dateStart === null || $dateStart === '' || substr($dateStart, 0, 10) <= $today;
    }

    /** Ajoute des mois à une date, au dernier jour du mois si le jour n'existe pas (31 janvier + 1). */
    public static function addMonths(string $date, int $months): string
    {
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2) + $months;
        $day = (int) substr($date, 8, 2);
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;
        $last = (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    /**
     * Prochaine revue : dernière revue (à défaut le début d'usage, à défaut la création de la fiche)
     * plus le cycle. Null si aucune revue n'est attendue (cycle 0, refusée, usage terminé).
     *
     * @param array<string, mixed> $sheet
     */
    public static function nextReview(array $sheet, string $today): ?string
    {
        $months = (int) ($sheet['review_months'] ?? 0);
        $status = (string) ($sheet['status'] ?? '');
        $end = isset($sheet['date_end']) ? (string) $sheet['date_end'] : null;
        if ($months <= 0 || $status === self::STATUS_REFUSED || self::hasEnded($end, $today)) {
            return null;
        }
        foreach (['last_review_date', 'date_start', 'date_creation'] as $field) {
            $base = self::sanitizeDate($sheet[$field] ?? null);
            if ($base !== null) {
                return self::addMonths($base, $months);
            }
        }

        return $today;
    }

    public static function reviewState(?string $next, string $today): string
    {
        if ($next === null) {
            return self::REVIEW_NONE;
        }
        if ($next <= $today) {
            return self::REVIEW_OVERDUE;
        }
        $soon = date('Y-m-d', (int) strtotime($today . ' +' . self::SOON_DAYS . ' days'));

        return $next <= $soon ? self::REVIEW_SOON : self::REVIEW_OK;
    }

    /**
     * Classification de confidentialité de l'application plus élevée que le niveau de données
     * autorisé : incohérence à corriger (l'un ou l'autre).
     */
    public static function levelExceeded(string $maxLevel, string $classification): bool
    {
        $max = array_search($maxLevel, self::LEVELS, true);
        $actual = array_search($classification, self::LEVELS, true);

        return $max !== false && $actual !== false && $actual > $max;
    }

    /**
     * Écarts d'une fiche approuvée, à montrer devant l'auditeur : sans responsable, sans cas
     * d'usage, sans niveau de données, approuvée avec restrictions sans les dire.
     *
     * @param array<string, mixed> $sheet
     * @return list<string>
     */
    public static function gaps(array $sheet): array
    {
        $status = (string) ($sheet['status'] ?? '');
        if (!self::isApproved($status)) {
            return [];
        }
        $gaps = [];
        if ((int) ($sheet['users_id_owner'] ?? 0) <= 0) {
            $gaps[] = self::GAP_NO_OWNER;
        }
        if (trim((string) ($sheet['allowed_uses'] ?? '')) === '') {
            $gaps[] = self::GAP_NO_USES;
        }
        if (self::sanitizeLevel($sheet['max_data_level'] ?? '') === '') {
            $gaps[] = self::GAP_NO_LEVEL;
        }
        if ($status === self::STATUS_RESTRICTED && trim((string) ($sheet['restrictions'] ?? '')) === '') {
            $gaps[] = self::GAP_NO_RESTRICTIONS;
        }

        return $gaps;
    }

    /** Nom réduit pour le rapprochement : minuscules, sans accents ni ponctuation. */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    /**
     * Le logiciel inventorié correspond-il à ce nom d'application ? Nom identique, ou nom du
     * logiciel qui commence par le nom de l'application (« Dropbox » et « Dropbox 200.4 »), sur des
     * noms d'au moins 3 caractères pour éviter les rapprochements fortuits.
     */
    public static function nameMatches(string $softwareName, string $applicationName): bool
    {
        $software = self::normalizeName($softwareName);
        $application = self::normalizeName($applicationName);
        if (strlen($application) < 3 || $software === '') {
            return false;
        }

        return $software === $application || str_starts_with($software, $application . ' ');
    }

    /**
     * Indicateurs du registre.
     *
     * @param list<array<string, mixed>> $sheets
     * @return array<string, int>
     */
    public static function indicators(array $sheets, string $today): array
    {
        $counts = [
            'total' => 0, self::STATUS_EVALUATION => 0, self::STATUS_APPROVED => 0,
            self::STATUS_RESTRICTED => 0, self::STATUS_REFUSED => 0, 'online' => 0,
            'review_overdue' => 0, 'review_soon' => 0, 'with_gaps' => 0, 'ended' => 0,
        ];
        foreach ($sheets as $sheet) {
            $counts['total']++;
            $status = self::sanitizeStatus($sheet['status'] ?? '');
            $counts[$status]++;
            if (($sheet['hosting'] ?? '') === self::HOSTING_ONLINE) {
                $counts['online']++;
            }
            if (self::hasEnded(isset($sheet['date_end']) ? (string) $sheet['date_end'] : null, $today)) {
                $counts['ended']++;
            }
            $state = self::reviewState(self::nextReview($sheet, $today), $today);
            if ($state === self::REVIEW_OVERDUE) {
                $counts['review_overdue']++;
            } elseif ($state === self::REVIEW_SOON) {
                $counts['review_soon']++;
            }
            if (self::gaps($sheet) !== []) {
                $counts['with_gaps']++;
            }
        }

        return $counts;
    }
}
