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

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Issue #775 : procédures d'exploitation documentées (ISO 27001, A.5.37). Logique pure, sans GLPI :
 * une procédure est une politique de la bibliothèque (Policy) de catégorie « procédure
 * d'exploitation », rattachée à un domaine (sauvegarde, restauration...). La liste des domaines
 * attendus est réglable ; la couverture dit, pour chacun, s'il a une procédure approuvée et revue
 * à temps.
 */
final class OperatingProcedureRules
{
    public const CATEGORY_POLICY = 'policy';
    public const CATEGORY_PROCEDURE = 'operating_procedure';

    /** @var list<string> */
    public const CATEGORIES = [self::CATEGORY_POLICY, self::CATEGORY_PROCEDURE];

    /** Domaines proposés par défaut, dans l'ordre d'affichage. */
    public const DEFAULT_DOMAINS = [
        'backup', 'restore', 'monitoring', 'logging', 'updates', 'deployment',
        'access_management', 'incident_management', 'change_management', 'maintenance',
    ];

    /** Longueur de la colonne `procedure_domain`. */
    public const MAX_DOMAIN_LENGTH = 64;
    public const MAX_DOMAINS = 50;

    /** État d'une procédure, puis d'un domaine (du meilleur au moins bon). */
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_REVIEW_DUE = 'review_due';
    public const STATUS_NOT_APPROVED = 'not_approved';
    public const STATUS_MISSING = 'missing';

    private const RANK = [
        self::STATUS_UP_TO_DATE   => 3,
        self::STATUS_REVIEW_DUE   => 2,
        self::STATUS_NOT_APPROVED => 1,
        self::STATUS_MISSING      => 0,
    ];

    public static function sanitizeCategory(mixed $value): string
    {
        return in_array($value, self::CATEGORIES, true) ? (string) $value : self::CATEGORY_POLICY;
    }

    /**
     * Domaine saisi ou choisi : texte court sans balise ni retour à la ligne. Vide pour une
     * politique (seules les procédures ont un domaine).
     */
    public static function sanitizeDomain(mixed $value, string $category): string
    {
        if ($category !== self::CATEGORY_PROCEDURE || !is_scalar($value)) {
            return '';
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return mb_substr($clean, 0, self::MAX_DOMAIN_LENGTH);
    }

    /**
     * Liste des domaines attendus : sans doublon (casse ignorée), sans entrée vide, au plus
     * MAX_DOMAINS.
     *
     * @param array<mixed> $domains
     * @return list<string>
     */
    public static function sanitizeDomains(array $domains): array
    {
        $clean = [];
        foreach ($domains as $domain) {
            $value = self::sanitizeDomain($domain, self::CATEGORY_PROCEDURE);
            if ($value === '') {
                continue;
            }
            $clean[mb_strtolower($value)] ??= $value;
            if (count($clean) >= self::MAX_DOMAINS) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * Domaines d'un formulaire : domaines par défaut cochés (dans leur ordre), puis domaines
     * supplémentaires, un par ligne.
     *
     * @param array<mixed> $checked
     * @return list<string>
     */
    public static function fromForm(array $checked, string $others): array
    {
        $defaults = array_values(array_filter(
            self::DEFAULT_DOMAINS,
            static fn (string $key): bool => in_array($key, $checked, true)
        ));

        return self::sanitizeDomains(array_merge($defaults, preg_split('/\R/u', $others) ?: []));
    }

    /**
     * Domaines supplémentaires (hors liste par défaut) d'une liste réglée.
     *
     * @param list<string> $domains
     * @return list<string>
     */
    public static function customDomains(array $domains): array
    {
        return array_values(array_filter(
            $domains,
            static fn (string $domain): bool => !in_array($domain, self::DEFAULT_DOMAINS, true)
        ));
    }

    /**
     * État d'une procédure : à jour (approuvée, prochaine revue renseignée et pas dépassée), revue à
     * faire (approuvée mais revue dépassée ou jamais planifiée), non approuvée (brouillon). Null pour
     * une procédure archivée, qui ne couvre plus rien.
     */
    public static function procedureStatus(string $status, ?string $nextReview, string $today): ?string
    {
        if ($status === PolicyLifecycle::STATUS_ARCHIVED) {
            return null;
        }
        if ($status !== PolicyLifecycle::STATUS_APPROVED) {
            return self::STATUS_NOT_APPROVED;
        }
        $next = trim((string) $nextReview);

        return $next !== '' && $next >= $today ? self::STATUS_UP_TO_DATE : self::STATUS_REVIEW_DUE;
    }

    /**
     * Issue #775, lot 2 : prochaine revue après une revue tracée. La date saisie sur la fiche est
     * gardée si elle est postérieure à aujourd'hui et a été changée ; sinon, un an plus tard.
     */
    public static function nextReviewAfterReview(string $today, mixed $posted, mixed $current): string
    {
        $today = substr($today, 0, 10);
        $posted = is_scalar($posted) ? trim((string) $posted) : '';
        $current = is_scalar($current) ? trim((string) $current) : '';
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted) === 1
            && $posted > $today
            && $posted !== substr($current, 0, 10)
        ) {
            return $posted;
        }

        return (new \DateTimeImmutable($today))->modify('+1 year')->format('Y-m-d');
    }

    /** Une revue ne se trace que sur une procédure d'exploitation approuvée. */
    public static function canRecordReview(string $category, string $status): bool
    {
        return $category === self::CATEGORY_PROCEDURE && $status === PolicyLifecycle::STATUS_APPROVED;
    }

    /**
     * Domaines attendus sans procédure approuvée (aucune, ou seulement des brouillons), pour le
     * contrôle de cohérence. Les revues en retard relèvent déjà de la règle des politiques.
     *
     * @param array{domains: list<array{domain: string, status: string, count: int}>} $coverage
     * @return list<array{domain: string, status: string, count: int}>
     */
    public static function uncoveredDomains(array $coverage): array
    {
        return array_values(array_filter(
            $coverage['domains'],
            static fn (array $row): bool => in_array(
                $row['status'],
                [self::STATUS_MISSING, self::STATUS_NOT_APPROVED],
                true
            )
        ));
    }

    /**
     * Couverture des domaines attendus : pour chacun, le meilleur état de ses procédures et leur
     * nombre. Une procédure d'un domaine qui n'est plus attendu n'est pas comptée.
     *
     * @param list<string> $domains
     * @param list<array{procedure_domain: string, status: string, next_review_date: ?string}> $procedures
     * @return array{
     *     domains: list<array{domain: string, status: string, count: int}>,
     *     summary: array{total: int, up_to_date: int, review_due: int, not_approved: int, missing: int, rate: int}
     * }
     */
    public static function coverage(array $domains, array $procedures, string $today): array
    {
        $byDomain = [];
        foreach ($domains as $domain) {
            $byDomain[mb_strtolower($domain)] = ['domain' => $domain, 'status' => self::STATUS_MISSING, 'count' => 0];
        }
        foreach ($procedures as $procedure) {
            $key = mb_strtolower((string) $procedure['procedure_domain']);
            $status = self::procedureStatus(
                (string) $procedure['status'],
                $procedure['next_review_date'] ?? null,
                $today
            );
            if (!isset($byDomain[$key]) || $status === null) {
                continue;
            }
            $byDomain[$key]['count']++;
            if (self::RANK[$status] > self::RANK[$byDomain[$key]['status']]) {
                $byDomain[$key]['status'] = $status;
            }
        }
        $summary = [
            'total'               => count($byDomain),
            self::STATUS_UP_TO_DATE   => 0,
            self::STATUS_REVIEW_DUE   => 0,
            self::STATUS_NOT_APPROVED => 0,
            self::STATUS_MISSING      => 0,
            'rate'                => 0,
        ];
        foreach ($byDomain as $row) {
            $summary[$row['status']]++;
        }
        if ($summary['total'] > 0) {
            $summary['rate'] = (int) floor($summary[self::STATUS_UP_TO_DATE] * 100 / $summary['total']);
        }

        return ['domains' => array_values($byDomain), 'summary' => $summary];
    }
}
