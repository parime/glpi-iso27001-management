<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Correlates a CVE's `affected_cpes` (see NvdCveParser::affectedCpes()) against software actually
 * installed on this GLPI instance's own inventory, using the admin-declared catalog
 * (PluginGrcmanagerCanonicalProduct/CpeReference/ProductAlias) — never an automatic name/version
 * guess, same "no invented correlation" principle as the rest of this plugin family.
 *
 * Computed on demand (called only when a human views a CVE's tab on a security incident), not by a
 * Cron against a whole vulnerability catalog: unlike the sibling plugin glpi-vulnerability-manager
 * (archived), this plugin only ever needs to answer "does THIS one CVE, already being reviewed on
 * an incident, concern my inventory?" — a bounded, low-frequency question, not a continuous
 * fleet-wide scan. No persisted "matches" table, no Cron, no review workflow as a result: simpler
 * by design, not a missing feature (see ROADMAP.md for the full rationale).
 *
 * Query shape (`glpi_items_softwareversions` → `glpi_softwareversions` → `glpi_softwares` → LEFT
 * JOIN `glpi_manufacturers`) confirmed against a real GLPI 11 instance and against the same join
 * the sibling plugin's own `AssetProductResolver` hand-writes for the same reason (GLPI core's own
 * `Item_SoftwareVersion::getFromItem()` doesn't return the manufacturer). Iterates every itemtype
 * in `$CFG_GLPI['software_types']` (Computer, Monitor, NetworkEquipment, Peripheral, Phone,
 * Printer in stock GLPI 11) rather than hardcoding Computer only — a CVE can just as well affect a
 * NetworkEquipment's firmware as a Computer's software.
 *
 * NOTE: depends on GLPI's runtime global $DB/$CFG_GLPI and the legacy PluginGrcmanager* classes,
 * not unit-tested in isolation, same exclusion rationale as RiskMatrixConfig.php, see
 * phpstan.neon.dist. The pure matching logic (CpeIdentity, CpeVersionMatch) IS unit-tested
 * independently.
 */
final class InventoryCveMatcher
{
    /**
     * @param list<array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * }> $affectedCpes
     * @return list<array{
     *     itemtype: string, items_id: int, item_name: string, software_name: string,
     *     version: string, state: string
     * }> Sorted MATCHED first, then UNKNOWN. EXCLUDED candidates are never returned.
     */
    public static function findMatchingAssets(array $affectedCpes): array
    {
        if (count($affectedCpes) === 0) {
            return [];
        }

        $canonicalProductIdByPrefix = self::canonicalProductIdByCpePrefix();

        if (count($canonicalProductIdByPrefix) === 0) {
            return [];
        }

        // Group the CVE's own affected CPEs by canonical product id (via the same prefix
        // reduction), so a resolved installed software only needs one lookup to find every
        // version range that could apply to it.
        $affectedCpesByCanonicalProductId = [];
        foreach ($affectedCpes as $affectedCpe) {
            $prefix = CpeIdentity::prefixOf($affectedCpe['cpe23_uri']);
            $canonicalProductId = $canonicalProductIdByPrefix[$prefix] ?? null;

            if ($canonicalProductId !== null) {
                $affectedCpesByCanonicalProductId[$canonicalProductId][] = $affectedCpe;
            }
        }

        if (count($affectedCpesByCanonicalProductId) === 0) {
            return [];
        }

        $matched = [];
        $unknown = [];

        foreach (self::installedSoftware() as $installed) {
            $canonicalProductId = \PluginGrcmanagerProductAlias::resolveCanonicalProductId($installed['software_name']);

            if ($canonicalProductId === null || !isset($affectedCpesByCanonicalProductId[$canonicalProductId])) {
                continue;
            }

            $bestState = null;
            foreach ($affectedCpesByCanonicalProductId[$canonicalProductId] as $affectedCpe) {
                $state = CpeVersionMatch::evaluate($affectedCpe, $installed['version']);

                if ($state === CpeVersionMatch::MATCHED) {
                    $bestState = CpeVersionMatch::MATCHED;
                    break;
                }

                if ($state === CpeVersionMatch::UNKNOWN) {
                    $bestState = CpeVersionMatch::UNKNOWN;
                }
                // EXCLUDED never overrides a previous UNKNOWN from a different affected-CPE entry.
            }

            if ($bestState === CpeVersionMatch::MATCHED) {
                $matched[] = self::toResultRow($installed, CpeVersionMatch::MATCHED);
            } elseif ($bestState === CpeVersionMatch::UNKNOWN) {
                $unknown[] = self::toResultRow($installed, CpeVersionMatch::UNKNOWN);
            }
        }

        return [...$matched, ...$unknown];
    }

    /**
     * @return array<string, int> CPE identity prefix => canonical product id.
     */
    private static function canonicalProductIdByCpePrefix(): array
    {
        global $DB;

        $map = [];
        foreach (
            $DB->request([
                'SELECT' => ['cpe', 'plugin_grcmanager_canonicalproducts_id'],
                'FROM'   => \PluginGrcmanagerCpeReference::getTable(),
            ]) as $row
        ) {
            $map[CpeIdentity::prefixOf((string) $row['cpe'])] = (int) $row['plugin_grcmanager_canonicalproducts_id'];
        }

        return $map;
    }

    /**
     * @return list<array{itemtype: string, items_id: int, item_name: string, software_name: string, version: string}>
     */
    private static function installedSoftware(): array
    {
        global $DB, $CFG_GLPI;

        $installed = [];

        foreach ($CFG_GLPI['software_types'] ?? [] as $itemtype) {
            if (!is_string($itemtype) || !class_exists($itemtype)) {
                continue;
            }

            $itemTable = $itemtype::getTable();

            $rows = $DB->request([
                'SELECT'     => [
                    'glpi_items_softwareversions.items_id',
                    'glpi_softwares.name AS software_name',
                    'glpi_softwareversions.name AS version',
                    "{$itemTable}.name AS item_name",
                ],
                'FROM'       => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => ['FKEY' => [
                        'glpi_items_softwareversions' => 'softwareversions_id',
                        'glpi_softwareversions'       => 'id',
                    ]],
                    'glpi_softwares' => ['FKEY' => [
                        'glpi_softwareversions' => 'softwares_id',
                        'glpi_softwares'        => 'id',
                    ]],
                    $itemTable => ['FKEY' => [
                        'glpi_items_softwareversions' => 'items_id',
                        $itemTable                    => 'id',
                    ]],
                ],
                'WHERE'      => [
                    'glpi_items_softwareversions.itemtype'   => $itemtype,
                    'glpi_items_softwareversions.is_deleted' => 0,
                ],
            ]);

            foreach ($rows as $row) {
                $installed[] = [
                    'itemtype'      => $itemtype,
                    'items_id'      => (int) $row['items_id'],
                    'item_name'     => (string) ($row['item_name'] ?? ''),
                    'software_name' => (string) $row['software_name'],
                    'version'       => (string) ($row['version'] ?? ''),
                ];
            }
        }

        return $installed;
    }

    /**
     * @param array{
     *     itemtype: string, items_id: int, item_name: string, software_name: string, version: string
     * } $installed
     * @return array{
     *     itemtype: string, items_id: int, item_name: string, software_name: string,
     *     version: string, state: string
     * }
     */
    private static function toResultRow(array $installed, string $state): array
    {
        return $installed + ['state' => $state];
    }
}
