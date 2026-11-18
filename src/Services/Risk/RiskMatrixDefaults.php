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
 * Default probability x impact -> risk_level matrix, seeded once at install
 * (src/Install/Installer.php) and used as the fallback for an install that has never customized
 * it, same pattern as the sibling plugin glpi-vulnerability-manager's own RiskMatrixDefaults.
 *
 * These values are NOT arbitrary: they are the exact grid produced by Sprint 1's hardcoded
 * RiskScoringService formula (probability weight x impact weight, thresholds low/medium/high/
 * critical at 4/8/12), reproduced here field by field so that an existing install sees no change
 * in its risk levels until an admin actually edits the matrix from the config screen (see
 * TECH_DEBT.md "Matrice de risque fixe, non administrable").
 */
final class RiskMatrixDefaults
{
    /**
     * probability => impact => risk_level, both axes rare/possible/probable/certain and
     * low/medium/high/critical.
     *
     * @var array<string, array<string, string>>
     */
    public const MATRIX = [
        'rare'     => ['low' => 'low', 'medium' => 'low', 'high' => 'low', 'critical' => 'medium'],
        'possible' => ['low' => 'low', 'medium' => 'medium', 'high' => 'medium', 'critical' => 'high'],
        'probable' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
        'certain'  => ['low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'],
    ];

    /**
     * Issue #706 : grille par défaut de la matrice 5x5 (very_rare à certain, negligible à
     * critical). Même construction que la grille 4x4 : poids 1 à 5 sur chaque axe, produit des
     * deux, seuils medium/high/critical à 5/10/16 (bandes usuelles d'une matrice 5x5 : 1 à 4,
     * 5 à 9, 10 à 15, 16 à 25).
     *
     * @var array<string, array<string, string>>
     */
    public const MATRIX_5 = [
        'very_rare' => [
            'negligible' => 'low', 'low' => 'low', 'medium' => 'low', 'high' => 'low', 'critical' => 'medium',
        ],
        'rare'      => [
            'negligible' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'medium', 'critical' => 'high',
        ],
        'possible'  => [
            'negligible' => 'low', 'low' => 'medium', 'medium' => 'medium', 'high' => 'high', 'critical' => 'high',
        ],
        'probable'  => [
            'negligible' => 'low', 'low' => 'medium', 'medium' => 'high', 'high' => 'critical',
            'critical' => 'critical',
        ],
        'certain'   => [
            'negligible' => 'medium', 'low' => 'high', 'medium' => 'high', 'high' => 'critical',
            'critical' => 'critical',
        ],
    ];

    /**
     * Grille par défaut d'une taille de matrice (4 pour toute valeur inconnue).
     *
     * @return array<string, array<string, string>>
     */
    public static function forSize(int $size): array
    {
        return $size === 5 ? self::MATRIX_5 : self::MATRIX;
    }
}
