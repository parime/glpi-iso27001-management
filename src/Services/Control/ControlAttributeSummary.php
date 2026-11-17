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

use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #610 : synthèse de la SoA par valeur d'attribut (mesures applicables, mises en œuvre et
 * taux de mise en œuvre). Même règle que le score de maturité et la conformité par référentiel :
 * une mesure non applicable est écartée, « mise en œuvre » et « vérifiée » comptent pour 1,
 * « en cours » pour moitié (MaturityScore::implementationRate()).
 *
 * PHP pur : les lignes viennent de la SoA suivie par l'entité active (EntityPerimeter).
 */
final class ControlAttributeSummary
{
    /**
     * @param iterable<array<string, mixed>> $controls lignes de la SoA (applicability,
     *     implementation_status et la colonne de l'attribut)
     * @return list<array{value: string, total: int, applicable: int, implemented: int,
     *     in_progress: int, rate: ?float}> une ligne par valeur de l'attribut, dans l'ordre du catalogue
     */
    public static function byValue(iterable $controls, string $attribute): array
    {
        $values = ControlAttributeCatalog::VALUES[$attribute] ?? [];
        $byValue = [];
        foreach ($values as $value) {
            $byValue[$value] = ['total' => 0, 'by_status' => []];
        }

        foreach ($controls as $row) {
            $applicable = (string) ($row['applicability'] ?? 'yes') !== 'no';
            $status = (string) ($row['implementation_status'] ?? 'not_started');
            foreach (ControlAttributeCatalog::decode($attribute, (string) ($row[$attribute] ?? '')) as $value) {
                $byValue[$value]['total']++;
                if ($applicable) {
                    $byValue[$value]['by_status'][$status] = ($byValue[$value]['by_status'][$status] ?? 0) + 1;
                }
            }
        }

        $lines = [];
        foreach ($byValue as $value => $counts) {
            $byStatus = $counts['by_status'];
            $lines[] = [
                'value'       => (string) $value,
                'total'       => $counts['total'],
                'applicable'  => array_sum($byStatus),
                'implemented' => ($byStatus['implemented'] ?? 0) + ($byStatus['verified'] ?? 0),
                'in_progress' => $byStatus['in_progress'] ?? 0,
                'rate'        => MaturityScore::implementationRate($byStatus),
            ];
        }

        return $lines;
    }
}
