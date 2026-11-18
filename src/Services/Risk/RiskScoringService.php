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
 * Pure probability x impact risk-scoring logic (ISO 27001 clause 6.1.2/8.2 generic risk
 * assessment), kept free of any GLPI runtime dependency so it can be unit-tested without a
 * running GLPI instance. Reused by \GlpiPlugin\Grcmanager\Risk::prepareInputForAdd()/prepareInputForUpdate()
 * whenever probability/impact change, so `computed_score`/`risk_level` are never entered
 * manually and always stay consistent with the two source fields.
 *
 * Sprint 2: the probability x impact -> risk_level matrix is now administrable from GLPI's admin
 * UI (front/config.php), mirroring the sibling plugin glpi-vulnerability-manager's own
 * configurable matrix (its RiskMatrixDefaults/RiskScorer split). The matrix is injected via the
 * constructor (whatever GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig loaded from
 * glpi_plugin_grcmanager_riskmatrixconfig, or RiskMatrixDefaults::MATRIX for a still-unconfigured
 * install), never read from a global here, so this class stays GLPI-free. The numeric
 * `computed_score` (still shown on the form for context) keeps Sprint 1's fixed ordinal weights:
 * only the probability x impact -> level mapping is administrable, not the underlying score.
 *
 * Issue #706 : the matrix size (4x4 by default, or 5x5) is injected too. Weights are the rank of
 * the value on its axis for that size (see RiskScale), so a 4x4 register scores exactly as before.
 */
final class RiskScoringService
{
    /**
     * @param array<string, array<string, string>> $matrix probability => impact => risk_level,
     *                                                      defaults to Sprint 1's original grid.
     * @param int $size matrix size (4 or 5), see RiskScale.
     */
    public function __construct(
        private readonly array $matrix = RiskMatrixDefaults::MATRIX,
        private readonly int $size = RiskScale::DEFAULT_SIZE
    ) {
    }

    public function score(string $probability, string $impact): float
    {
        return (float) (
            RiskScale::weight('probability', $probability, $this->size)
            * RiskScale::weight('impact', $impact, $this->size)
        );
    }

    /**
     * Looks up the configured matrix for this probability/impact pair. Falls back to the
     * score-based thresholds (Sprint 1's original behaviour) only for a combination missing from
     * an incomplete/corrupted matrix (e.g. an unrecognized probability/impact key), so a partial
     * admin edit can never leave a risk without a computed level.
     */
    public function level(string $probability, string $impact): string
    {
        return $this->matrix[$probability][$impact] ?? RiskScale::levelFromScore(
            $this->score($probability, $impact),
            $this->size
        );
    }
}
