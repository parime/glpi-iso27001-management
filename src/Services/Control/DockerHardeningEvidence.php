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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #916 : preuve « configuration à risque » de l'inventaire Docker sur la mesure A.8.9, lue
 * par Evidence::hardening() du plugin Inventaire Docker (0.12.0 et suivantes). Règles pures : la
 * lecture passe par DockerInventoryEvidenceProvider::fetchHardening().
 *
 * Le taux mis en avant est celui des services sans écart ouvert critique ni haut : le taux « sans
 * aucun écart » tombe souvent à 0 % pour des écarts de faible gravité. Les exceptions expirées ou
 * qui finissent dans les EXPIRING_DAYS jours sont comptées sur les services visibles seulement.
 */
final class DockerHardeningEvidence
{
    /** Méthode de l'API du plugin Inventaire Docker. */
    public const API_METHOD = 'hardening';

    /** Une exception en cours qui finit dans ce délai est signalée comme proche de l'expiration. */
    public const EXPIRING_DAYS = 30;

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * Synthèse de la réponse de l'API, ou null si elle est inexploitable.
     *
     * @param mixed $data Réponse de Evidence::hardening().
     * @param array<int, true> $allowed Entités autorisées (actives et leurs parentes).
     * @param string $today Date de référence (« AAAA-MM-JJ », l'heure éventuelle est ignorée).
     * @return array{analysed: int, not_analysed: int, rate: int|null, rate_serious: int|null,
     *     by_severity: array<string, int>, expired: int, expiring: int, next_end: string,
     *     page_url: string}|null
     */
    public static function summary(mixed $data, array $allowed, string $today): ?array
    {
        if (!is_array($data) || !isset($data['analysed'])) {
            return null;
        }
        $bySeverity = [];
        $counts = is_array($data['by_severity'] ?? null) ? $data['by_severity'] : [];
        foreach (self::SEVERITIES as $severity) {
            $bySeverity[$severity] = self::count($counts[$severity] ?? 0);
        }
        $day = substr($today, 0, 10);
        $limit = self::addDays($day, self::EXPIRING_DAYS);
        $expired = 0;
        $expiring = 0;
        $nextEnd = '';
        foreach (DockerInventoryEvidence::visibleRows($data['services'] ?? [], $allowed) as $service) {
            foreach (is_array($service['findings'] ?? null) ? $service['findings'] : [] as $finding) {
                if (!is_array($finding)) {
                    continue;
                }
                $state = self::text($finding['exception'] ?? '');
                $end = substr(self::text($finding['exception_end'] ?? ''), 0, 10);
                if ($state === 'expired') {
                    $expired++;
                } elseif ($state === 'active' && $end !== '' && $end >= $day && $end <= $limit) {
                    $expiring++;
                    $nextEnd = $nextEnd === '' || $end < $nextEnd ? $end : $nextEnd;
                }
            }
        }
        $url = self::text($data['page_url'] ?? '');

        return [
            'analysed'     => self::count($data['analysed']),
            'not_analysed' => self::count($data['not_analysed'] ?? 0),
            'rate'         => self::rate($data['rate'] ?? null),
            'rate_serious' => self::rate($data['rate_serious'] ?? null),
            'by_severity'  => $bySeverity,
            'expired'      => $expired,
            'expiring'     => $expiring,
            'next_end'     => $nextEnd,
            'page_url'     => preg_match('#^(/|https?://)#i', $url) === 1 ? $url : '',
        ];
    }

    private static function addDays(string $day, int $days): string
    {
        $time = strtotime($day);

        return date('Y-m-d', ($time === false ? time() : $time) + $days * 86400);
    }

    private static function rate(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
