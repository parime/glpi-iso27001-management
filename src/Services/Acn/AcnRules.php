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

namespace GlpiPlugin\Grcmanager\Services\Acn;

use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #703 : règles du registre NIS2 Italia - ACN, sans dépendance à GLPI (testées seules).
 *
 * Mêmes valeurs que la SoA (Control) pour l'applicabilité et l'état de mise en œuvre : un point non
 * pleinement applicable exige une justification, et le taux se calcule comme pour l'Annexe A
 * (MaturityScore::implementationRate()).
 */
final class AcnRules
{
    public const APPLICABILITIES = ['yes', 'partial', 'no'];
    public const STATUSES = ['not_started', 'in_progress', 'implemented', 'verified'];
    public const DONE_STATUSES = ['implemented', 'verified'];

    public const ERROR_JUSTIFICATION = 'justification';

    public static function normalizeCategory(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, AcnCatalogDefaults::CATEGORIES_ENTITY, true) ? $value : '';
    }

    public static function normalizeApplicability(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::APPLICABILITIES, true) ? $value : 'yes';
    }

    public static function normalizeStatus(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::STATUSES, true) ? $value : 'not_started';
    }

    /**
     * Erreurs de saisie d'un point (champs fusionnés : valeur saisie, sinon valeur enregistrée).
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function errors(array $fields): array
    {
        $applicability = self::normalizeApplicability($fields['applicability'] ?? 'yes');
        if ($applicability !== 'yes' && trim((string) ($fields['justification'] ?? '')) === '') {
            return [self::ERROR_JUSTIFICATION];
        }

        return [];
    }

    /** @param array<string, mixed> $row */
    public static function isApplicable(array $row): bool
    {
        return self::normalizeApplicability($row['applicability'] ?? 'yes') !== 'no';
    }

    /** @param array<string, mixed> $row */
    public static function isDone(array $row): bool
    {
        return in_array((string) ($row['implementation_status'] ?? ''), self::DONE_STATUSES, true);
    }

    /**
     * Échéance dépassée d'un point applicable et pas encore mis en œuvre.
     *
     * @param array<string, mixed> $row
     */
    public static function isOverdue(array $row, string $today): bool
    {
        $due = (string) ($row['due_date'] ?? '');

        return $due !== '' && $due < $today && self::isApplicable($row) && !self::isDone($row);
    }

    /**
     * Approbation des organes de direction exigée par le point et pas encore enregistrée.
     *
     * @param array<string, mixed> $row
     */
    public static function isApprovalMissing(array $row): bool
    {
        return AcnCatalogDefaults::requiresApproval((string) ($row['code'] ?? ''), (int) ($row['point'] ?? 0))
            && self::isApplicable($row)
            && (string) ($row['approval_date'] ?? '') === '';
    }

    /**
     * Synthèse d'un ensemble de points du catalogue de la catégorie : total, applicables, nombre par
     * état, taux, échéances dépassées, approbations manquantes, points sans responsable.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, applicable: int, not_applicable: int, by_status: array<string, int>,
     *     rate: float|null, done: int, overdue: int, approvals_required: int, approvals_missing: int,
     *     unassigned: int}
     */
    public static function summary(array $rows, string $today): array
    {
        $summary = [
            'total' => 0, 'applicable' => 0, 'not_applicable' => 0,
            'by_status' => array_fill_keys(self::STATUSES, 0),
            'rate' => null, 'done' => 0, 'overdue' => 0, 'approvals_required' => 0, 'approvals_missing' => 0,
            'unassigned' => 0,
        ];
        foreach ($rows as $row) {
            $summary['total']++;
            if (!self::isApplicable($row)) {
                $summary['not_applicable']++;
                continue;
            }
            $summary['applicable']++;
            $summary['by_status'][self::normalizeStatus($row['implementation_status'] ?? '')]++;
            if (self::isDone($row)) {
                $summary['done']++;
            }
            if (self::isOverdue($row, $today)) {
                $summary['overdue']++;
            }
            if (AcnCatalogDefaults::requiresApproval((string) ($row['code'] ?? ''), (int) ($row['point'] ?? 0))) {
                $summary['approvals_required']++;
                if (self::isApprovalMissing($row)) {
                    $summary['approvals_missing']++;
                }
            }
            if ((int) ($row['users_id'] ?? 0) <= 0) {
                $summary['unassigned']++;
            }
        }
        $summary['rate'] = MaturityScore::implementationRate($summary['by_status']);

        return $summary;
    }

    /**
     * Points du catalogue de la catégorie seulement, triés dans l'ordre des allegati.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function inCatalog(array $rows, string $category): array
    {
        $order = [];
        foreach (AcnCatalogDefaults::allPoints() as $index => [$code, $point]) {
            $order[$code . '#' . $point] = $index;
        }
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => AcnCatalogDefaults::inScope(
                (string) ($row['code'] ?? ''),
                (int) ($row['point'] ?? 0),
                $category
            )
        ));
        usort(
            $rows,
            static fn (array $a, array $b): int => ($order[$a['code'] . '#' . $a['point']] ?? 0)
                <=> ($order[$b['code'] . '#' . $b['point']] ?? 0)
        );

        return $rows;
    }

    /**
     * Points à créer pour qu'une entité ait tout le catalogue (les deux allegati : changer de
     * catégorie ne perd ni ne recrée rien).
     *
     * @param list<array{0: string, 1: int}> $existing [code, point] déjà enregistrés
     * @return list<array{0: string, 1: int}>
     */
    public static function missingPoints(array $existing): array
    {
        $known = [];
        foreach ($existing as [$code, $point]) {
            $known[$code . '#' . $point] = true;
        }

        return array_values(array_filter(
            AcnCatalogDefaults::allPoints(),
            static fn (array $entry): bool => !isset($known[$entry[0] . '#' . $entry[1]])
        ));
    }

    /**
     * Entité dont le registre ACN s'applique : la plus proche de la chaîne (elle, puis ses parents)
     * qui a une catégorie.
     *
     * @param list<int> $chain entité puis ses parents jusqu'à la racine
     * @param array<int, string> $categories entité => catégorie réglée
     * @return int|null null sans registre applicable
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
