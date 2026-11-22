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
 * Issue #1053 : référentiels rangés par famille (normes ISO, réglementations européennes, guides
 * nationaux...) et regroupés (NIS2 et ses déclinaisons nationales, bloc ANSSI). Logique pure, sans
 * GLPI : les familles sont déclarées par fichier dans src/Config/Block (FrameworkFamilyBlock).
 *
 * Un membre de famille est soit la clé d'un référentiel, soit un regroupement :
 * ['group' => 'nis2', 'parent' => 'nis2', 'children' => ['recyf', 'acn_it']] (déclinaisons actives
 * seulement avec leur parent) ou ['group' => 'anssi', 'children' => ['anssi', 'mss', 'recyf']]
 * (simple présentation commune). Un même référentiel peut figurer dans deux regroupements (ReCyF :
 * déclinaison France de NIS2 et publication de l'ANSSI) : c'est le même réglage, coché aux deux
 * endroits. Les données et les scores de chaque référentiel restent distincts.
 */
final class FrameworkFamilyRules
{
    /**
     * Clés des référentiels d'une famille, sans doublon, dans l'ordre d'affichage.
     *
     * @param list<string|array{group: string, parent?: string, children: list<string>}> $members
     * @return list<string>
     */
    public static function keys(array $members): array
    {
        $keys = [];
        foreach ($members as $member) {
            if (is_string($member)) {
                $keys[] = $member;
                continue;
            }
            if (isset($member['parent'])) {
                $keys[] = $member['parent'];
            }
            foreach ($member['children'] as $child) {
                $keys[] = $child;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Déclinaisons et leur référentiel parent.
     *
     * @param list<string|array{group: string, parent?: string, children: list<string>}> $members
     * @return array<string, string> déclinaison => parent
     */
    public static function parents(array $members): array
    {
        $parents = [];
        foreach ($members as $member) {
            if (is_array($member) && isset($member['parent'])) {
                foreach ($member['children'] as $child) {
                    $parents[$child] = $member['parent'];
                }
            }
        }

        return $parents;
    }

    /**
     * Compteur affiché sur une famille : référentiels réellement actifs sur le total.
     *
     * @param list<string> $keys clés de la famille (keys())
     * @param list<string> $effective référentiels actifs (déclinaisons sans parent exclues)
     * @return array{active: int, total: int}
     */
    public static function count(array $keys, array $effective): array
    {
        return [
            'active' => count(array_intersect($keys, $effective)),
            'total'  => count($keys),
        ];
    }

    /**
     * Évaluations rangées par famille, dans l'ordre des familles puis de leurs membres ; une
     * déclinaison suit son parent, marquée « nested ». Une évaluation d'aucune famille va dans la
     * dernière (jamais perdue) ; une famille sans évaluation n'est pas rendue.
     *
     * @param list<array{key: string, label: string, icon: string, keys: list<string>, parents: array<string, string>}> $families
     * @param array<string, array<string, mixed>> $assessments nom => évaluation (clé « key »)
     * @return list<array{key: string, label: string, icon: string, rows: list<array{label: string, nested: bool, assessment: array<string, mixed>}>}>
     */
    public static function arrange(array $families, array $assessments): array
    {
        $byKey = [];
        foreach ($assessments as $label => $assessment) {
            $byKey[(string) $assessment['key']] = ['label' => (string) $label, 'assessment' => $assessment];
        }
        $placed = [];
        $result = [];
        foreach ($families as $family) {
            $rows = [];
            foreach ($family['keys'] as $key) {
                if (!isset($byKey[$key]) || isset($placed[$key])) {
                    continue;
                }
                $placed[$key] = true;
                $rows[] = $byKey[$key] + ['nested' => isset($family['parents'][$key])];
            }
            $result[] = ['key' => $family['key'], 'label' => $family['label'], 'icon' => $family['icon'], 'rows' => $rows];
        }
        $orphans = [];
        foreach ($byKey as $key => $row) {
            if (!isset($placed[$key])) {
                $orphans[] = $row + ['nested' => false];
            }
        }
        if ($orphans !== [] && $result !== []) {
            $last = count($result) - 1;
            $result[$last]['rows'] = array_merge($result[$last]['rows'], $orphans);
        }

        return array_values(array_filter($result, static fn (array $family): bool => $family['rows'] !== []));
    }
}
