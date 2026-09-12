<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Pure grid-merging logic for the risk heatmap (ROADMAP.md "Version 2.2", cartographie des
 * risques), kept free of any GLPI runtime dependency so it can be unit-tested without a running
 * GLPI instance — same split as RiskScoringService (pure) vs RiskMatrixConfig (GLPI runtime `$DB`),
 * see RiskHeatmapService for the `$DB`-touching half that actually counts risks per cell.
 */
final class RiskHeatmapGrid
{
    /**
     * Overlays a probability x impact risk count onto the administrable risk-level matrix, so each
     * cell of the heatmap carries both how many risks fall there AND what color/level that cell is
     * (from RiskMatrixConfig, never recomputed here — one source of truth for the mapping, same
     * reasoning as RiskScoringService's own docblock).
     *
     * @param array<string, array<string, string>> $matrix probability => impact => risk_level
     * @param array<string, array<string, int>>    $counts probability => impact => count
     * @return array<string, array<string, array{level: string, count: int}>>
     */
    public static function build(array $matrix, array $counts): array
    {
        $grid = [];

        foreach ($matrix as $probability => $impacts) {
            foreach ($impacts as $impact => $level) {
                $grid[$probability][$impact] = [
                    'level' => $level,
                    'count' => $counts[$probability][$impact] ?? 0,
                ];
            }
        }

        return $grid;
    }
}
