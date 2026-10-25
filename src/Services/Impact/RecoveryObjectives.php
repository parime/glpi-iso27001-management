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

namespace GlpiPlugin\Grcmanager\Services\Impact;

/**
 * Issue #177, phase 2 : objectifs de reprise d'un actif (RTO : durée maximale d'interruption,
 * RPO : perte de données maximale), en heures, et cohérence avec les dépendances déclarées dans
 * l'analyse d'impact de GLPI.
 */
final class RecoveryObjectives
{
    /** Valeurs proposées, en heures (0 : non défini). */
    public const OPTIONS = [1, 4, 8, 24, 48, 72, 168];

    public static function sanitize(mixed $value): int
    {
        $hours = is_numeric($value) ? (int) $value : 0;

        return in_array($hours, self::OPTIONS, true) ? $hours : 0;
    }

    /**
     * Un élément ne peut pas reprendre plus vite que ce dont il dépend : pour chaque lien
     * source → élément impacté, l'élément impacté qui exige un RTO doit avoir une source dont le
     * RTO est défini et au moins aussi court.
     *
     * @param list<array{0: string, 1: string}> $edges Liens source → impacté.
     * @param array<string, int> $rto RTO en heures par clé d'élément.
     * @return list<array{source: string, impacted: string, required: int, actual: int}>
     */
    public static function inconsistencies(array $edges, array $rto): array
    {
        $issues = [];
        foreach ($edges as [$source, $impacted]) {
            $required = $rto[$impacted] ?? 0;
            if ($required <= 0) {
                continue;
            }
            $actual = $rto[$source] ?? 0;
            if ($actual === 0 || $actual > $required) {
                $issues[] = [
                    'source'   => $source,
                    'impacted' => $impacted,
                    'required' => $required,
                    'actual'   => $actual,
                ];
            }
        }

        return $issues;
    }
}
