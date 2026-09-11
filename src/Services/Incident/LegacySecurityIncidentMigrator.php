<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Pure field-mapping logic for the legacy->fused security-incident migration (see
 * `Installer::migrateLegacySecurityIncidents()`, ROADMAP.md "Version 2.0"): maps a row from the
 * former lightweight `PluginGrcmanagerSecurityIncident` register (CommonDBTM, issue #29) onto the
 * column shape of the absorbed ITIL object's own table. Kept GLPI-independent (no $DB, no
 * CommonDBTM) so the field mapping — including the trickiest part, the status vocabulary
 * conversion — is unit-tested directly, the same split as `SecurityIncidentRules` and every other
 * Rules class in this plugin.
 *
 * Status values are the raw ints CommonITILObject itself defines (INCOMING=1, ASSIGNED=2,
 * PLANNED=3, WAITING=4, SOLVED=5, CLOSED=6) rather than referencing that class's constants
 * directly: this class must stay loadable with no GLPI core present (see phpstan.neon.dist), and
 * referencing `\CommonITILObject::INCOMING` would require the class to exist. These values are
 * GLPI's own long-stable ITIL status numbering, not guessed.
 */
final class LegacySecurityIncidentMigrator
{
    private const STATUS_INCOMING = 1;
    private const STATUS_ASSIGNED = 2;
    private const STATUS_WAITING  = 4;
    private const STATUS_CLOSED   = 6;

    /**
     * The old 4-value ISO status vocabulary doesn't line up 1:1 with CommonITILObject's own
     * lifecycle — this is a judgment call, not a native equivalence: "investigating" (someone is
     * actively working it) maps to ASSIGNED, "contained" (mitigated but not yet fully resolved)
     * maps to WAITING, "open"/"closed" map directly to their obvious counterparts.
     *
     * @var array<string, int>
     */
    private const STATUS_MAP = [
        'open'          => self::STATUS_INCOMING,
        'investigating' => self::STATUS_ASSIGNED,
        'contained'     => self::STATUS_WAITING,
        'closed'        => self::STATUS_CLOSED,
    ];

    /**
     * @param array<string, mixed> $legacyRow A row from the old table, as returned by `$DB->request()`.
     * @return array<string, mixed> Fields to insert into the new merged table (no `id`: the new
     *         table gets its own auto-increment id, the caller is responsible for any follow-up
     *         actor/item-link rows keyed off the returned id).
     */
    public static function mapRow(array $legacyRow): array
    {
        return [
            'name'                       => (string) ($legacyRow['title'] ?? ''),
            'content'                    => (string) ($legacyRow['description'] ?? ''),
            'date'                       => empty($legacyRow['incident_date']) ? null : $legacyRow['incident_date'],
            'status'                     => self::mapStatus($legacyRow['status'] ?? null),
            'category'                  => (string) ($legacyRow['category'] ?? 'other'),
            'severity'                   => (string) ($legacyRow['severity'] ?? 'minor'),
            'cia_impact'                 => (string) ($legacyRow['cia_impact'] ?? ''),
            'root_cause'                 => (string) ($legacyRow['root_cause'] ?? ''),
            'lessons_learned'            => (string) ($legacyRow['lessons_learned'] ?? ''),
            'plugin_grcmanager_risks_id' => (int) ($legacyRow['plugin_grcmanager_risks_id'] ?? 0),
            'entities_id'                => 0,
            'date_creation'              => $legacyRow['date_creation'] ?? null,
        ];
    }

    public static function mapStatus(?string $legacyStatus): int
    {
        return self::STATUS_MAP[$legacyStatus] ?? self::STATUS_INCOMING;
    }

    /**
     * @param array<string, mixed> $legacyRow
     */
    public static function hasLinkedItem(array $legacyRow): bool
    {
        return ($legacyRow['linked_itemtype'] ?? '') !== '' && (int) ($legacyRow['linked_items_id'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $legacyRow
     */
    public static function hasResponsibleUser(array $legacyRow): bool
    {
        return (int) ($legacyRow['users_id'] ?? 0) > 0;
    }
}
