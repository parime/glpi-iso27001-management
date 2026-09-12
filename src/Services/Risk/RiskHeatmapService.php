<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

use Glpi\DBAL\QueryExpression;

/**
 * ROADMAP.md "Version 2.2" (cartographie des risques) : counts every row of the generic risk
 * register (`glpi_plugin_grcmanager_risks`) per probability x impact cell, then overlays those
 * counts onto the administrable matrix via the pure RiskHeatmapGrid::build() — one query, reused
 * by front/riskheatmap.php.
 *
 * All risks are counted, not only the "open" subset `DashboardCardService::openRisksCount()` uses:
 * a heatmap is a map of the organization's risk landscape as assessed (probability x impact is set
 * the moment a risk is created, regardless of its later treatment decision), the same reasoning
 * `DashboardCardService::risksByLevel()` already applies to its own by-level breakdown.
 *
 * NOTE: depends on GLPI's runtime global $DB (both directly and via RiskMatrixConfig::load()), not
 * unit-tested in isolation, same exclusion rationale as RiskMatrixConfig.php itself (see
 * phpstan.neon.dist). The pure grid-merging logic (RiskHeatmapGrid) IS unit-tested independently.
 */
final class RiskHeatmapService
{
    /**
     * @return array<string, array<string, array{level: string, count: int}>>
     */
    public static function buildGrid(): array
    {
        global $DB;

        $counts = [];
        $rows = $DB->request([
            'SELECT'  => ['probability', 'impact', new QueryExpression('COUNT(*) AS c')],
            'FROM'    => 'glpi_plugin_grcmanager_risks',
            'GROUPBY' => ['probability', 'impact'],
        ]);

        foreach ($rows as $row) {
            $counts[$row['probability']][$row['impact']] = (int) $row['c'];
        }

        return RiskHeatmapGrid::build(RiskMatrixConfig::load(), $counts);
    }
}
