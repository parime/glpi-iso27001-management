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
 * Issue #537 : réimport d'une nouvelle version d'un référentiel personnalisé, sans dépendance à GLPI.
 *
 * Rapprochement par code (sans tenir compte de la casse) : un code nouveau est créé, un code présent
 * est mis à jour (thème, intitulé, description, correspondance, rang), un code absent du fichier est
 * marqué « retiré », jamais supprimé, et un code retiré qui revient est rétabli. Le statut et le
 * commentaire saisis sur chaque exigence ne font pas partie du fichier : ils ne sont jamais touchés.
 */
final class CustomFrameworkSync
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const RESTORE = 'restore';
    public const UNCHANGED = 'unchanged';
    public const REMOVE = 'remove';

    /** Champs que le fichier fournit (les autres appartiennent à l'utilisateur). */
    public const FILE_FIELDS = ['code', 'theme', 'title', 'description', 'annex_a', 'rank'];

    /**
     * @param array<int, array{code: string, theme: string, title: string, description: string,
     *     annex_a: string, rank: int, is_removed: int}> $existing id => exigence enregistrée
     * @param list<array{line: int, code: string, theme: string, title: string, description: string,
     *     annex_a: list<string>}> $rows lignes valides du fichier
     * @return list<array{action: string, id: int, code: string, title: string, line: int,
     *     fields: array<string, string|int>, changed: list<string>}>
     */
    public static function plan(array $existing, array $rows): array
    {
        $byCode = [];
        foreach ($existing as $id => $requirement) {
            $byCode[mb_strtolower($requirement['code'])] = $id;
        }
        $plan = [];
        $matched = [];
        foreach (array_values($rows) as $rank => $row) {
            $fields = [
                'code'        => $row['code'],
                'theme'       => $row['theme'],
                'title'       => $row['title'],
                'description' => $row['description'],
                'annex_a'     => self::encodeAnnexA($row['annex_a']),
                'rank'        => $rank + 1,
            ];
            $id = $byCode[mb_strtolower($row['code'])] ?? null;
            if ($id === null) {
                $plan[] = self::step(self::CREATE, 0, $row, $fields, []);
                continue;
            }
            $matched[$id] = true;
            $current = $existing[$id];
            $changed = [];
            foreach (self::FILE_FIELDS as $field) {
                if ((string) $current[$field] !== (string) $fields[$field]) {
                    $changed[] = $field;
                }
            }
            if ((int) $current['is_removed'] === 1) {
                $action = self::RESTORE;
            } else {
                // Seul le rang a bougé (une exigence insérée plus haut) : rien de visible n'a changé.
                $action = array_diff($changed, ['rank']) === [] ? self::UNCHANGED : self::UPDATE;
            }
            $plan[] = self::step($action, $id, $row, $fields, array_values(array_diff($changed, ['rank'])));
        }
        foreach ($existing as $id => $requirement) {
            if (isset($matched[$id]) || (int) $requirement['is_removed'] === 1) {
                continue;
            }
            $plan[] = [
                'action'  => self::REMOVE,
                'id'      => $id,
                'code'    => $requirement['code'],
                'title'   => $requirement['title'],
                'line'    => 0,
                'fields'  => [],
                'changed' => [],
            ];
        }

        return $plan;
    }

    /**
     * Nombre d'étapes par action.
     *
     * @param list<array{action: string}> $plan
     * @return array<string, int>
     */
    public static function summary(array $plan): array
    {
        $summary = array_fill_keys([self::CREATE, self::UPDATE, self::RESTORE, self::UNCHANGED, self::REMOVE], 0);
        foreach ($plan as $step) {
            $summary[$step['action']]++;
        }

        return $summary;
    }

    /**
     * @param list<string> $codes
     */
    public static function encodeAnnexA(array $codes): string
    {
        return implode(',', $codes);
    }

    /**
     * @return list<string>
     */
    public static function decodeAnnexA(string $stored): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $stored)), static fn ($c) => $c !== ''));
    }

    /**
     * @param array{line: int, code: string, title: string} $row
     * @param array<string, string|int> $fields
     * @param list<string> $changed
     * @return array{action: string, id: int, code: string, title: string, line: int,
     *     fields: array<string, string|int>, changed: list<string>}
     */
    private static function step(string $action, int $id, array $row, array $fields, array $changed): array
    {
        return [
            'action'  => $action,
            'id'      => $id,
            'code'    => $row['code'],
            'title'   => $row['title'],
            'line'    => $row['line'],
            'fields'  => $fields,
            'changed' => $changed,
        ];
    }
}
