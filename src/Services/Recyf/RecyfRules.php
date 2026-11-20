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

namespace GlpiPlugin\Grcmanager\Services\Recyf;

use GlpiPlugin\Grcmanager\Services\Acn\AcnRules;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #599 : règles du registre ReCyF (NIS2 France), sans dépendance à GLPI (testées seules).
 *
 * Mêmes valeurs que la SoA et que le registre NIS2 Italia (AcnRules) pour l'applicabilité et l'état
 * de mise en œuvre : une mesure non pleinement applicable exige une justification, et le taux se
 * calcule comme pour l'Annexe A (MaturityScore::implementationRate()).
 */
final class RecyfRules
{
    public static function normalizeCategory(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, RecyfCatalogDefaults::CATEGORIES_ENTITY, true) ? $value : '';
    }

    /**
     * Synthèse d'un ensemble de mesures du catalogue de la catégorie : total, applicables, nombre
     * par état, taux, échéances dépassées, mesures sans responsable.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, applicable: int, not_applicable: int, by_status: array<string, int>,
     *     rate: float|null, done: int, overdue: int, unassigned: int}
     */
    public static function summary(array $rows, string $today): array
    {
        $summary = [
            'total' => 0, 'applicable' => 0, 'not_applicable' => 0,
            'by_status' => array_fill_keys(AcnRules::STATUSES, 0),
            'rate' => null, 'done' => 0, 'overdue' => 0, 'unassigned' => 0,
        ];
        foreach ($rows as $row) {
            $summary['total']++;
            if (!AcnRules::isApplicable($row)) {
                $summary['not_applicable']++;
                continue;
            }
            $summary['applicable']++;
            $summary['by_status'][AcnRules::normalizeStatus($row['implementation_status'] ?? '')]++;
            if (AcnRules::isDone($row)) {
                $summary['done']++;
            }
            if (AcnRules::isOverdue($row, $today)) {
                $summary['overdue']++;
            }
            if ((int) ($row['users_id'] ?? 0) <= 0) {
                $summary['unassigned']++;
            }
        }
        $summary['rate'] = MaturityScore::implementationRate($summary['by_status']);

        return $summary;
    }

    /**
     * Mesures du catalogue de la catégorie seulement, triées dans l'ordre du référentiel.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function inCatalog(array $rows, string $category): array
    {
        $order = array_flip(RecyfCatalogDefaults::codes());
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => RecyfCatalogDefaults::inScope((string) ($row['code'] ?? ''), $category)
        ));
        usort(
            $rows,
            static fn (array $a, array $b): int => ($order[(string) $a['code']] ?? 0)
                <=> ($order[(string) $b['code']] ?? 0)
        );

        return $rows;
    }

    /**
     * Mesures à créer pour qu'une entité ait tout le catalogue (entités importantes et essentielles :
     * changer de catégorie ne perd ni ne recrée rien).
     *
     * @param list<string> $existing numéros déjà enregistrés
     * @return list<string>
     */
    public static function missingCodes(array $existing): array
    {
        return array_values(array_diff(RecyfCatalogDefaults::codes(), $existing));
    }

    /**
     * Entité dont le registre ReCyF s'applique : la plus proche de la chaîne (elle, puis ses parents)
     * qui a une catégorie.
     *
     * @param list<int> $chain entité puis ses parents jusqu'à la racine
     * @param array<int, string> $categories entité => catégorie réglée
     */
    public static function registerEntity(array $chain, array $categories): ?int
    {
        foreach ($chain as $entityId) {
            if (self::normalizeCategory($categories[$entityId] ?? '') !== '') {
                return $entityId;
            }
        }

        return null;
    }
}
