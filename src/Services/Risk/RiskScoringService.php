<?php

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
 */
final class RiskScoringService
{
    /**
     * @var array<string, int>
     */
    private const PROBABILITY_WEIGHTS = [
        'rare'     => 1,
        'possible' => 2,
        'probable' => 3,
        'certain'  => 4,
    ];

    /**
     * @var array<string, int>
     */
    private const IMPACT_WEIGHTS = [
        'low'      => 1,
        'medium'   => 2,
        'high'     => 3,
        'critical' => 4,
    ];

    /**
     * @param array<string, array<string, string>> $matrix probability => impact => risk_level,
     *                                                      defaults to Sprint 1's original grid.
     */
    public function __construct(
        private readonly array $matrix = RiskMatrixDefaults::MATRIX
    ) {
    }

    public function score(string $probability, string $impact): float
    {
        $probabilityWeight = self::PROBABILITY_WEIGHTS[$probability] ?? 0;
        $impactWeight       = self::IMPACT_WEIGHTS[$impact] ?? 0;

        return (float) ($probabilityWeight * $impactWeight);
    }

    /**
     * Looks up the configured matrix for this probability/impact pair. Falls back to the
     * score-based thresholds (Sprint 1's original behaviour) only for a combination missing from
     * an incomplete/corrupted matrix (e.g. an unrecognized probability/impact key), so a partial
     * admin edit can never leave a risk without a computed level.
     */
    public function level(string $probability, string $impact): string
    {
        return $this->matrix[$probability][$impact] ?? $this->levelFromScore(
            $this->score($probability, $impact)
        );
    }

    private function levelFromScore(float $score): string
    {
        return match (true) {
            $score >= 12 => 'critical',
            $score >= 8  => 'high',
            $score >= 4  => 'medium',
            default      => 'low',
        };
    }
}
