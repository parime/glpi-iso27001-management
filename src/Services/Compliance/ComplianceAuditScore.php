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
 * Issue #162 : audit de conformité technique. Chaque contrôle porte sur une population d'éléments
 * GLPI (postes, changements, comptes) et compte les écarts ; le score d'un référentiel est la
 * moyenne des taux de conformité des contrôles qui s'y rattachent. Aucune dépendance à GLPI.
 */
final class ComplianceAuditScore
{
    public const ISO27001 = 'iso27001';
    public const NIS2 = 'nis2';
    public const GDPR = 'gdpr';

    /**
     * Références de chaque contrôle dans les référentiels (absent : ne s'y rattache pas).
     *
     * @var array<string, array<string, string>>
     */
    public const CHECKS = [
        'antivirus'         => [self::ISO27001 => 'A.8.7', self::NIS2 => 'art. 21.2 g)', self::GDPR => 'art. 32'],
        'encryption'        => [self::ISO27001 => 'A.8.24', self::NIS2 => 'art. 21.2 h)', self::GDPR => 'art. 32'],
        'inventory'         => [self::ISO27001 => 'A.5.9', self::NIS2 => 'art. 21.2 i)'],
        'change_validation' => [self::ISO27001 => 'A.8.32', self::NIS2 => 'art. 21.2 e)'],
        'accounts'          => [self::ISO27001 => 'A.5.18', self::NIS2 => 'art. 21.2 i)', self::GDPR => 'art. 32'],
    ];

    /**
     * Taux de conformité en %, null si la population est vide (contrôle sans objet).
     */
    public static function rate(int $gaps, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        return (int) round(100 * (1 - min($gaps, $total) / $total));
    }

    /**
     * Score par référentiel : moyenne des taux des contrôles évalués qui s'y rattachent.
     *
     * @param array<string, array{gaps: int, total: int, evaluated: bool}> $results
     * @return array<string, array{score: ?int, checks: int}>
     */
    public static function frameworkScores(array $results): array
    {
        $scores = [];
        foreach ([self::ISO27001, self::NIS2, self::GDPR] as $framework) {
            $rates = [];
            foreach (self::CHECKS as $check => $references) {
                if (!isset($references[$framework], $results[$check]) || !$results[$check]['evaluated']) {
                    continue;
                }
                $rate = self::rate($results[$check]['gaps'], $results[$check]['total']);
                if ($rate !== null) {
                    $rates[] = $rate;
                }
            }
            $scores[$framework] = [
                'score'  => $rates === [] ? null : (int) round(array_sum($rates) / count($rates)),
                'checks' => count($rates),
            ];
        }

        return $scores;
    }

    /**
     * Antivirus conforme : actif, à jour et, si une date d'expiration est connue, non expiré.
     *
     * @param array{is_active: mixed, is_uptodate: mixed, date_expiration?: ?string} $antivirus
     */
    public static function isAntivirusCompliant(array $antivirus, string $today): bool
    {
        $expiration = (string) ($antivirus['date_expiration'] ?? '');

        return !empty($antivirus['is_active'])
            && !empty($antivirus['is_uptodate'])
            && ($expiration === '' || substr($expiration, 0, 10) >= $today);
    }
}
