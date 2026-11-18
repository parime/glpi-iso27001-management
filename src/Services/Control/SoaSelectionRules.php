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
 * Issue #609 : motifs de sélection des mesures de la Déclaration d'Applicabilité et détail des
 * exclusions (ISO/IEC 27001:2022, 6.1.3 d : pour chaque mesure, si elle est incluse ou non, pourquoi,
 * et si elle est mise en œuvre).
 *
 * La justification libre (colonne `justification`) reste la raison écrite, obligatoire pour une
 * exclusion ; ces champs la complètent par des valeurs que l'on filtre et que l'on compte :
 *
 * - motifs de sélection (plusieurs possibles), stockés dans `selection_reasons` séparés par des
 *   virgules ; NULL tant que personne ne les a renseignés (le formulaire propose alors les motifs
 *   déduits des risques et des obligations liés) ;
 * - pour une mesure exclue ou partiellement applicable : catégorie d'exclusion, mesures
 *   compensatoires (texte libre et liens vers d'autres mesures de la même SoA) et risque résiduel.
 *
 * PHP pur, sans dépendance à GLPI : les libellés traduits sont dans \GlpiPlugin\Grcmanager\Control.
 */
final class SoaSelectionRules
{
    public const REASON_RISK_ASSESSMENT = 'risk_assessment';
    public const REASON_LEGAL = 'legal';
    public const REASON_CONTRACTUAL = 'contractual';
    public const REASON_STAKEHOLDER = 'stakeholder';
    public const REASON_BEST_PRACTICE = 'best_practice';

    /**
     * Motifs de sélection, dans l'ordre d'affichage. Aucun n'est contenu dans un autre : le filtre
     * de la recherche cherche le motif dans la liste stockée (vérifié par le test unitaire).
     */
    public const REASONS = [
        self::REASON_RISK_ASSESSMENT,
        self::REASON_LEGAL,
        self::REASON_CONTRACTUAL,
        self::REASON_STAKEHOLDER,
        self::REASON_BEST_PRACTICE,
    ];

    public const EXCLUSION_CATEGORIES = ['activity_absent', 'certified_provider', 'out_of_scope'];

    /** Mêmes niveaux que le registre de risques (RiskAppetiteRules::LEVELS). */
    public const RESIDUAL_LEVELS = ['low', 'medium', 'high', 'critical'];

    /** Champs propres à une exclusion, vidés quand la mesure redevient pleinement applicable. */
    public const EXCLUSION_FIELDS = ['exclusion_category', 'compensating_measures', 'residual_risk'];

    /**
     * Type d'une obligation du registre (ComplianceObligation) => motif qu'elle justifie.
     */
    private const OBLIGATION_REASONS = [
        'legal'       => self::REASON_LEGAL,
        'regulatory'  => self::REASON_LEGAL,
        'contractual' => self::REASON_CONTRACTUAL,
    ];

    /**
     * Une mesure exclue ou partiellement applicable : la justification est obligatoire et le
     * détail de l'exclusion a un sens.
     */
    public static function isExclusion(string $applicability): bool
    {
        return in_array($applicability, ['no', 'partial'], true);
    }

    /**
     * Liste stockée => motifs connus, sans doublon, dans l'ordre de REASONS.
     *
     * @return list<string>
     */
    public static function decodeReasons(?string $stored): array
    {
        $chosen = array_map('trim', explode(',', (string) $stored));

        return array_values(array_intersect(self::REASONS, $chosen));
    }

    /**
     * Motifs saisis (liste ou chaîne) => liste stockée, motifs inconnus écartés.
     *
     * @param list<string>|string|null $reasons
     */
    public static function encodeReasons(array|string|null $reasons): string
    {
        if (!is_array($reasons)) {
            $reasons = explode(',', (string) $reasons);
        }

        return implode(',', self::decodeReasons(implode(',', array_map('strval', $reasons))));
    }

    /**
     * Ajoute un motif à la liste stockée (NULL compris), sans toucher aux autres.
     */
    public static function addReason(?string $stored, string $reason): string
    {
        return self::encodeReasons(array_merge(self::decodeReasons($stored), [$reason]));
    }

    /**
     * Motifs déduits des liens de la mesure : « appréciation des risques » dès qu'un risque est lié,
     * « obligation légale ou réglementaire » ou « exigence contractuelle » quand une obligation du
     * registre, rattachée à l'un de ces risques, est de ce type.
     *
     * @param list<string> $obligationTypes types des obligations liées (legal, regulatory, contractual)
     * @return list<string>
     */
    public static function suggestedReasons(bool $hasLinkedRisk, array $obligationTypes): array
    {
        $reasons = $hasLinkedRisk ? [self::REASON_RISK_ASSESSMENT] : [];
        foreach ($obligationTypes as $type) {
            if (isset(self::OBLIGATION_REASONS[$type])) {
                $reasons[] = self::OBLIGATION_REASONS[$type];
            }
        }

        return self::decodeReasons(implode(',', $reasons));
    }

    /**
     * Motifs à cocher dans le formulaire : ceux enregistrés, ou, tant que personne ne les a
     * renseignés (NULL), ceux déduits des liens. Une case décochée puis enregistrée le reste.
     *
     * @param list<string> $suggested
     * @return list<string>
     */
    public static function formReasons(?string $stored, array $suggested): array
    {
        return $stored === null ? self::decodeReasons(implode(',', $suggested)) : self::decodeReasons($stored);
    }

    /**
     * Valeur d'une liste fermée (catégorie d'exclusion, risque résiduel) : '' si inconnue.
     *
     * @param list<string> $allowed
     */
    public static function normalizeChoice(mixed $value, array $allowed): string
    {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Synthèse de la SoA par thème : mesures, applicables (pleinement ou partiellement), exclues
     * et, parmi les applicables, mises en œuvre (« mise en œuvre » ou « vérifiée »), en cours et
     * non démarrées (prévues).
     *
     * @param iterable<array<string, mixed>> $controls lignes de la SoA (theme, applicability,
     *     implementation_status)
     * @param list<string> $themes thèmes dans l'ordre d'affichage
     * @return list<array{theme: string, total: int, applicable: int, partial: int, excluded: int,
     *     implemented: int, in_progress: int, not_started: int}>
     */
    public static function themeSummary(iterable $controls, array $themes): array
    {
        $empty = [
            'total' => 0, 'applicable' => 0, 'partial' => 0, 'excluded' => 0,
            'implemented' => 0, 'in_progress' => 0, 'not_started' => 0,
        ];
        $byTheme = array_fill_keys($themes, $empty);

        foreach ($controls as $row) {
            $theme = (string) ($row['theme'] ?? '');
            if (!isset($byTheme[$theme])) {
                continue;
            }
            $byTheme[$theme]['total']++;
            $applicability = (string) ($row['applicability'] ?? 'yes');
            if ($applicability === 'no') {
                $byTheme[$theme]['excluded']++;
                continue;
            }
            $byTheme[$theme]['applicable']++;
            if ($applicability === 'partial') {
                $byTheme[$theme]['partial']++;
            }
            $status = (string) ($row['implementation_status'] ?? 'not_started');
            $bucket = match ($status) {
                'implemented', 'verified' => 'implemented',
                'in_progress'             => 'in_progress',
                default                   => 'not_started',
            };
            $byTheme[$theme][$bucket]++;
        }

        $lines = [];
        foreach ($byTheme as $theme => $counts) {
            $lines[] = ['theme' => (string) $theme] + $counts;
        }

        return $lines;
    }

    /**
     * Ligne de total de la synthèse par thème.
     *
     * @param list<array{theme: string, total: int, applicable: int, partial: int, excluded: int,
     *     implemented: int, in_progress: int, not_started: int}> $lines
     * @return array{total: int, applicable: int, partial: int, excluded: int, implemented: int,
     *     in_progress: int, not_started: int}
     */
    public static function themeTotals(array $lines): array
    {
        $totals = [
            'total' => 0, 'applicable' => 0, 'partial' => 0, 'excluded' => 0,
            'implemented' => 0, 'in_progress' => 0, 'not_started' => 0,
        ];
        foreach ($lines as $line) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $line[$key];
            }
        }

        return $totals;
    }
}
