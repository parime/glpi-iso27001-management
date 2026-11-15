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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #602 : ce que l'import d'un registre des risques changera, calculé sans rien écrire (aperçu à
 * blanc), puis recalculé sur l'état présent de la base au moment de la confirmation.
 *
 * Rapprochement par la référence externe (sans tenir compte de la casse) ; à défaut, une référence
 * « GLPI-RISK-<id> » (celle que l'export donne à un risque saisi sans référence) désigne ce risque.
 * Seules les valeurs présentes dans le fichier sont comparées et écrites : une colonne absente ou une
 * cellule vide laisse la valeur enregistrée. Le statut ne change que si le fichier le donne.
 */
final class RiskRegisterSync
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const UNCHANGED = 'unchanged';

    public const ERROR_AMBIGUOUS_REFERENCE = 'ambiguous_reference';
    public const ERROR_CLOSE_WITHOUT_PLAN = 'close_without_plan';

    /** Valeurs d'un nouveau risque quand le fichier ne les donne pas (mêmes que le formulaire). */
    public const DEFAULTS = [
        'category'    => 'process',
        'probability' => 'possible',
        'impact'      => 'medium',
        'status'      => 'identified',
    ];

    /** Champs simples, dans l'ordre d'affichage de l'aperçu. */
    public const FIELDS = [
        'title', 'description', 'category', 'probability', 'impact', 'residual_probability', 'residual_impact',
        'treatment', 'justification', 'users_id', 'review_date', 'status',
    ];

    /** Listes liées : mesures de l'Annexe A et actifs GLPI. */
    public const LISTS = ['annex_a', 'assets'];

    /**
     * @param array<int, array<string, mixed>> $existing id => risque enregistré (external_ref, champs de
     *     FIELDS, annex_a et assets en listes triées, actions : nombre d'actions de traitement)
     * @param list<array{line: int, reference: string, title: string, values: array<string, mixed>}> $rows
     * @return array{steps: list<array{action: string, id: int, line: int, reference: string, title: string,
     *     fields: array<string, mixed>, lists: array<string, list<string>>,
     *     changes: array<string, array{old: mixed, new: mixed}>}>,
     *     errors: list<array{line: int, reference: string, reason: string, detail: string}>}
     */
    public static function plan(array $existing, array $rows): array
    {
        $byReference = [];
        foreach ($existing as $id => $risk) {
            $reference = mb_strtolower(trim((string) ($risk['external_ref'] ?? '')));
            if ($reference !== '') {
                $byReference[$reference][] = (int) $id;
            }
        }
        $steps = [];
        $errors = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['reference']);
            $ids = $byReference[$key] ?? [];
            $internal = '/^' . RiskRegisterCsv::INTERNAL_PREFIX . '(\d+)$/i';
            if ($ids === [] && preg_match($internal, $row['reference'], $m) === 1) {
                $candidate = (int) $m[1];
                $candidateRef = trim((string) ($existing[$candidate]['external_ref'] ?? ''));
                if (isset($existing[$candidate]) && $candidateRef === '') {
                    $ids = [$candidate];
                }
            }
            if (count($ids) > 1) {
                $errors[] = self::error($row, self::ERROR_AMBIGUOUS_REFERENCE, (string) count($ids));
                continue;
            }
            $step = $ids === []
                ? self::createStep($row)
                : self::updateStep($ids[0], $existing[$ids[0]], $row);
            $current = $ids === [] ? [] : $existing[$ids[0]];
            if ($step['action'] !== self::UNCHANGED && self::closesWithoutPlan($step['fields'], $current)) {
                $errors[] = self::error($row, self::ERROR_CLOSE_WITHOUT_PLAN);
                continue;
            }
            $steps[] = $step;
        }

        return ['steps' => $steps, 'errors' => $errors];
    }

    /**
     * @param list<array{action: string}> $steps
     * @return array<string, int>
     */
    public static function summary(array $steps): array
    {
        $summary = [self::CREATE => 0, self::UPDATE => 0, self::UNCHANGED => 0];
        foreach ($steps as $step) {
            $summary[$step['action']]++;
        }

        return $summary;
    }

    /**
     * @param array{line: int, reference: string, title: string, values: array<string, mixed>} $row
     * @return array{action: string, id: int, line: int, reference: string, title: string,
     *     fields: array<string, mixed>, lists: array<string, list<string>>,
     *     changes: array<string, array{old: mixed, new: mixed}>}
     */
    private static function createStep(array $row): array
    {
        $fields = ['external_ref' => $row['reference']];
        $changes = [];
        foreach (self::FIELDS as $field) {
            $value = $row['values'][$field] ?? self::DEFAULTS[$field] ?? null;
            if ($value === null) {
                continue;
            }
            $fields[$field] = $value;
            $changes[$field] = ['old' => null, 'new' => $value];
        }
        $lists = [];
        foreach (self::LISTS as $list) {
            if (isset($row['values'][$list])) {
                $lists[$list] = $row['values'][$list];
                $changes[$list] = ['old' => null, 'new' => $row['values'][$list]];
            }
        }

        return self::step(self::CREATE, 0, $row, $fields, $lists, $changes);
    }

    /**
     * @param array<string, mixed> $current
     * @param array{line: int, reference: string, title: string, values: array<string, mixed>} $row
     * @return array{action: string, id: int, line: int, reference: string, title: string,
     *     fields: array<string, mixed>, lists: array<string, list<string>>,
     *     changes: array<string, array{old: mixed, new: mixed}>}
     */
    private static function updateStep(int $id, array $current, array $row): array
    {
        $fields = [];
        $changes = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row['values'])) {
                continue;
            }
            $new = $row['values'][$field];
            $old = $current[$field] ?? null;
            if (self::normalize($old) !== self::normalize($new)) {
                $fields[$field] = $new;
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }
        $lists = [];
        foreach (self::LISTS as $list) {
            if (!isset($row['values'][$list])) {
                continue;
            }
            $new = self::sortedList($row['values'][$list]);
            $old = self::sortedList($current[$list] ?? []);
            if ($new !== $old) {
                $lists[$list] = $new;
                $changes[$list] = ['old' => $old, 'new' => $new];
            }
        }
        $action = $changes === [] ? self::UNCHANGED : self::UPDATE;

        return self::step($action, $id, $row, $fields, $lists, $changes);
    }

    /**
     * Un risque clos alors que sa décision (réduire, transférer) exige un plan de traitement, et qu'il
     * n'a aucune action : le registre le refuserait (Risk::prepareInputForUpdate()).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $current
     */
    private static function closesWithoutPlan(array $fields, array $current): bool
    {
        $status = (string) ($fields['status'] ?? $current['status'] ?? '');
        $treatment = (string) ($fields['treatment'] ?? $current['treatment'] ?? '');

        return $status === 'closed'
            && TreatmentPlanRules::isTreatmentPlanRelevant($treatment)
            && (int) ($current['actions'] ?? 0) === 0;
    }

    /**
     * @param mixed $value
     */
    private static function normalize($value): string
    {
        if ($value === null) {
            return '';
        }
        $value = str_replace("\r\n", "\n", trim((string) $value));

        return $value === '0' ? '' : $value;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function sortedList($list): array
    {
        $values = array_values(array_unique(array_map('strval', (array) $list)));
        sort($values);

        return $values;
    }

    /**
     * @param array{line: int, reference: string, title: string, values: array<string, mixed>} $row
     * @param array<string, mixed> $fields
     * @param array<string, list<string>> $lists
     * @param array<string, array{old: mixed, new: mixed}> $changes
     * @return array{action: string, id: int, line: int, reference: string, title: string,
     *     fields: array<string, mixed>, lists: array<string, list<string>>,
     *     changes: array<string, array{old: mixed, new: mixed}>}
     */
    private static function step(
        string $action,
        int $id,
        array $row,
        array $fields,
        array $lists,
        array $changes
    ): array {
        return [
            'action'    => $action,
            'id'        => $id,
            'line'      => $row['line'],
            'reference' => $row['reference'],
            'title'     => $row['title'],
            'fields'    => $fields,
            'lists'     => $lists,
            'changes'   => $changes,
        ];
    }

    /**
     * @param array{line: int, reference: string} $row
     * @return array{line: int, reference: string, reason: string, detail: string}
     */
    private static function error(array $row, string $reason, string $detail = ''): array
    {
        return ['line' => $row['line'], 'reference' => $row['reference'], 'reason' => $reason, 'detail' => $detail];
    }
}
