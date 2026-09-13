<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Loads/saves the NVD-enrichment settings from `glpi_plugin_grcmanager_nvdconfig` (a single row,
 * id=1, seeded at install with NvdConfigDefaults, see src/Install/Installer.php). Used by both
 * front/config.php (the admin screen) and PluginGrcmanagerSecurityIncidentCve (to decide whether to
 * fetch, and what score counts as urgent) — same single-source-of-truth reasoning as
 * RiskMatrixConfig for the probability x impact matrix.
 *
 * NOTE: depends on GLPI's runtime global $DB, not unit-tested in isolation, same exclusion
 * rationale as RiskMatrixConfig.php, see phpstan.neon.dist.
 */
final class NvdConfig
{
    private const TABLE = 'glpi_plugin_grcmanager_nvdconfig';

    /**
     * @return array{enable_nvd_enrichment: bool, cvss_alert_threshold: float}
     */
    public static function load(): array
    {
        global $DB;

        $config = [
            'enable_nvd_enrichment' => NvdConfigDefaults::ENABLE_NVD_ENRICHMENT,
            'cvss_alert_threshold'  => NvdConfigDefaults::CVSS_ALERT_THRESHOLD,
        ];

        foreach ($DB->request(self::TABLE) as $row) {
            $config['enable_nvd_enrichment'] = (bool) $row['enable_nvd_enrichment'];
            $config['cvss_alert_threshold']  = (float) $row['cvss_alert_threshold'];
            break;
        }

        return $config;
    }

    /**
     * @param array{enable_nvd_enrichment: bool, cvss_alert_threshold: float} $config
     */
    public static function save(array $config): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'enable_nvd_enrichment' => (int) $config['enable_nvd_enrichment'],
            'cvss_alert_threshold'  => $config['cvss_alert_threshold'],
            'date_mod'              => date('Y-m-d H:i:s'),
        ], ['id' => 1]);
    }
}
