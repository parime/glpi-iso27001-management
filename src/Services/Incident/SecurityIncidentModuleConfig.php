<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Loads/saves the on/off switches for the security-incident module being absorbed from the
 * sibling plugin glpi-security-incidents (full ITIL object: actors, workflow, tasks,
 * notifications, CVE tracking, incident templates, dashboard cards) into
 * `glpi_plugin_grcmanager_securityincidentconfig` — a single row (id=1), seeded at install with
 * every flag enabled, same singleton-row shape as RiskMatrixConfig
 * (src/Services/Risk/RiskMatrixConfig.php) so front/config.php doesn't need a second loading
 * convention.
 *
 * All flags default to enabled: on an existing install, this toggle only ever turns OFF something
 * that already exists — it never opts a fresh install IN to something new on its own, since the
 * module's actual behaviour (menu entry, tabs, dashboard cards) only starts reading these flags
 * once that code lands. See ROADMAP.md "Version 2.0".
 *
 * NOTE: depends on GLPI's runtime global $DB, not unit-tested in isolation, same exclusion
 * rationale as RiskMatrixConfig — see phpstan.neon.dist.
 */
final class SecurityIncidentModuleConfig
{
    private const TABLE = 'glpi_plugin_grcmanager_securityincidentconfig';

    /**
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        'securityincident_enabled'           => true,
        'securityincident_cve_enabled'       => true,
        'securityincident_templates_enabled' => true,
        'securityincident_dashboard_enabled' => true,
    ];

    /**
     * @return array<string, bool>
     */
    public static function load(): array
    {
        global $DB;

        $flags = self::DEFAULTS;

        foreach ($DB->request(self::TABLE) as $row) {
            foreach (array_keys(self::DEFAULTS) as $key) {
                $flags[$key] = !empty($row[$key]);
            }
            break;
        }

        return $flags;
    }

    /**
     * @param array<string, bool> $flags
     */
    public static function save(array $flags): void
    {
        global $DB;

        $update = ['date_mod' => date('Y-m-d H:i:s')];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $update[$key] = !empty($flags[$key]) ? 1 : 0;
        }

        $DB->update(self::TABLE, $update, ['id' => 1]);
    }
}
