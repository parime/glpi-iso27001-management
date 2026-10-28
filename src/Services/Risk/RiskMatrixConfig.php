<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Loads/saves the administrable probability x impact matrix from
 * `glpi_plugin_grcmanager_riskmatrixconfig` (a single row, id=1, seeded at install with
 * RiskMatrixDefaults::MATRIX, see src/Install/Installer.php). Used by both front/config.php (the
 * admin screen) and \GlpiPlugin\Grcmanager\Risk::computeRiskLevel() (inc/risk.class.php) so the matrix
 * that actually scores a risk is always exactly what the admin screen shows/saves, not a second
 * copy of the loading logic drifting out of sync.
 *
 * NOTE: depends on GLPI's runtime global $DB, not unit-tested in isolation, same exclusion
 * rationale as the sibling plugin glpi-vulnerability-manager, see phpstan.neon.dist. The pure
 * lookup logic (RiskScoringService) IS unit-tested independently.
 */
final class RiskMatrixConfig
{
    private const TABLE = 'glpi_plugin_grcmanager_riskmatrixconfig';

    /**
     * @return array<string, array<string, string>> probability => impact => risk_level
     */
    public static function load(): array
    {
        global $DB;

        $matrix = RiskMatrixDefaults::MATRIX;

        foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
            $decoded = json_decode((string) $row['matrix'], true);
            $matrix  = is_array($decoded) ? $decoded : $matrix;
            break;
        }

        return $matrix;
    }

    /**
     * @param array<string, array<string, string>> $matrix probability => impact => risk_level
     */
    public static function save(array $matrix): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'matrix'   => json_encode($matrix),
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => 1]);
    }
}
