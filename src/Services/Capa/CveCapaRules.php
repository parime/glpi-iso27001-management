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

namespace GlpiPlugin\Grcmanager\Services\Capa;

/**
 * Issue #163 : règles du plan d'action proposé pour une CVE relevée sur un incident de sécurité.
 * La proposition est relue et modifiée par l'utilisateur avant toute création.
 */
final class CveCapaRules
{
    /** Délai proposé par sévérité, en jours. */
    private const DUE_DAYS = ['critical' => 7, 'major' => 30, 'minor' => 90];

    /** Liens détaillés dans l'action corrective, par catégorie. */
    private const MAX_LINKS = 5;

    /**
     * Activement exploitée ou CVSS ≥ 9 : critique ; CVSS ≥ 7 : majeure ; sinon mineure.
     */
    public static function severity(?float $cvss, bool $isKev): string
    {
        return match (true) {
            $isKev || ($cvss !== null && $cvss >= 9.0) => 'critical',
            $cvss !== null && $cvss >= 7.0             => 'major',
            default                                    => 'minor',
        };
    }

    /**
     * Échéance proposée : le délai de la sévérité, ou l'échéance CISA si elle est plus proche
     * et pas encore passée.
     */
    public static function dueDate(string $severity, string $kevDue, string $today): string
    {
        $due = date('Y-m-d', (int) strtotime($today . ' +' . (self::DUE_DAYS[$severity] ?? 90) . ' days'));
        if ($kevDue !== '' && $kevDue >= $today && $kevDue < $due) {
            return $kevDue;
        }

        return $due;
    }

    /**
     * Liens de NVD rangés en correctifs et avis de l'éditeur.
     *
     * @param list<array{url: string, tag: string}> $links
     * @return array{patch: list<string>, advisory: list<string>}
     */
    public static function groupLinks(array $links): array
    {
        $grouped = ['patch' => [], 'advisory' => []];
        foreach ($links as $link) {
            $kind = $link['tag'] === 'Patch' ? 'patch' : 'advisory';
            if (count($grouped[$kind]) < self::MAX_LINKS && !in_array($link['url'], $grouped[$kind], true)) {
                $grouped[$kind][] = (string) $link['url'];
            }
        }

        return $grouped;
    }
}
