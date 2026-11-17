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
}
