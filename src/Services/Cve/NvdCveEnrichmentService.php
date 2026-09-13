<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Fetches real data (CVSS score/vector, severity, description, patch links) for one CVE reference
 * from the NVD single-CVE lookup endpoint, and persists it into
 * `glpi_plugin_grcmanager_cveenrichments`. The GLPI/network-bound half of the split described in
 * NvdCveParser's own docblock — that class does all the actual parsing, this one only makes the
 * HTTP call and stores the result.
 *
 * No dedicated CommonDBTM itemtype for this table on purpose: like RiskMatrixConfig for the risk
 * matrix, this is a pure internal cache a user never lists/searches/edits directly as a GLPI item
 * (only ever read to decorate the CVE tab, see cve.html.twig) — plain `$DB` access keyed by
 * `cve_id` (unique), same reasoning, just generalized from RiskMatrixConfig's single row to one
 * row per CVE.
 *
 * Uses `\Toolbox::getURLContent()` rather than a raw HTTP client — same choice, and same
 * reasoning, as the sibling GithubVersionChecker::getLatestGithubVersion(): reuses GLPI core's own
 * proxy/timeout/error handling instead of reinventing it.
 *
 * Every outcome (`ok`/`not_found`/`error`) is persisted explicitly rather than left as a silent
 * blank — a failed or not-yet-attempted fetch must never be indistinguishable from "NVD confirms
 * this CVE has no data", matching this plugin's own "never invent an absent value" rule (see
 * EnvironmentalData in the sibling plugin assetsign-glpi for the same principle applied there).
 *
 * NOTE: depends on GLPI's runtime global $DB and core \Toolbox, not unit-tested in isolation, same
 * exclusion rationale as RiskMatrixConfig.php, see phpstan.neon.dist. The pure parsing logic
 * (NvdCveParser) IS unit-tested independently.
 */
final class NvdCveEnrichmentService
{
    private const TABLE = 'glpi_plugin_grcmanager_cveenrichments';

    private const NVD_BASE_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    /**
     * NVD's unauthenticated limit is 5 requests / rolling 30s (enforced by Cloudflare in front of
     * NVD, see the sibling plugin glpi-vulnerability-manager's own NvdConnector docblock for a
     * confirmed-live HTTP 429). Only relevant here when the Cron refreshes several CVEs in one
     * pass — a single on-add fetch never needs to pace itself.
     */
    private const MIN_DELAY_BETWEEN_REQUESTS_MICROSECONDS = 7_500_000;

    /**
     * @return array{
     *     cve_id: string, cvss_score: ?float, cvss_vector: ?string, severity: ?string,
     *     description: ?string, patch_links: list<array{url: string, tag: string}>,
     *     published_at: ?string, fetch_status: string, fetched_at: ?string
     * }|null Null if this CVE has never been enriched at all (row doesn't exist yet).
     */
    public static function getForCve(string $cveId): ?array
    {
        global $DB;

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['cve_id' => $cveId]]) as $row) {
            $decodedLinks = json_decode((string) ($row['patch_links'] ?? ''), true);

            return [
                'cve_id'       => $row['cve_id'],
                'cvss_score'   => $row['cvss_score'] !== null ? (float) $row['cvss_score'] : null,
                'cvss_vector'  => $row['cvss_vector'],
                'severity'     => $row['severity'],
                'description'  => $row['description'],
                'patch_links'  => is_array($decodedLinks) ? $decodedLinks : [],
                'published_at' => $row['published_at'],
                'fetch_status' => $row['fetch_status'],
                'fetched_at'   => $row['fetched_at'],
            ];
        }

        return null;
    }

    /**
     * Fetches and persists enrichment data for one CVE, creating the row if it doesn't already
     * exist. Never throws: a network failure or malformed response is recorded as `fetch_status =
     * 'error'`, never allowed to interrupt whatever the caller (e.g. adding a CVE reference to an
     * incident) was doing.
     */
    public static function fetchForCve(string $cveId): void
    {
        $error = '';
        $json  = \Toolbox::getURLContent(
            self::NVD_BASE_URL . '?' . http_build_query(['cveId' => $cveId]),
            $error
        );

        if (empty($json)) {
            self::upsert($cveId, ['fetch_status' => 'error']);

            return;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['vulnerabilities']) || !is_array($decoded['vulnerabilities'])) {
            self::upsert($cveId, ['fetch_status' => 'error']);

            return;
        }

        $entry = $decoded['vulnerabilities'][0]['cve'] ?? null;

        if (!is_array($entry)) {
            // A well-formed response with zero results (`totalResults: 0`) — the CVE identifier
            // is not (yet, or never) a real NVD entry, confirmed live for a made-up ID: distinct
            // from a transient network/parse failure, so it gets its own status rather than
            // sharing 'error' and being retried forever by the Cron for no reason.
            self::upsert($cveId, ['fetch_status' => 'not_found']);

            return;
        }

        $parsed = NvdCveParser::parse($entry);

        self::upsert($cveId, [
            'cvss_score'   => $parsed['cvss_score'],
            'cvss_vector'  => $parsed['cvss_vector'],
            'severity'     => $parsed['severity'],
            'description'  => $parsed['description'],
            'patch_links'  => json_encode($parsed['patch_links']),
            'published_at' => $parsed['published_at'],
            'fetch_status' => 'ok',
        ]);
    }

    /**
     * Refreshes every CVE in $trackedCveIds that either has no enrichment row yet (covers a CVE
     * added while enrichment was disabled, then enabled later — nothing to do with "staleness"),
     * has never successfully been fetched (`pending`/`error`/`not_found` — NVD's own catalog does
     * grow over time, a CVE reported as `not_found` today may exist tomorrow), or whose last
     * successful fetch is older than $staleAfterDays (a CVE can sit in NVD's "awaiting analysis"
     * queue and only receive its CVSS score later — see the sibling plugin's own NvdConnector
     * docblock for the same transition). $trackedCveIds is supplied by the caller (the Cron entry
     * point on PluginGrcmanagerSecurityIncidentCve, which owns the "which CVEs actually exist in
     * this instance" list) rather than looked up here, keeping this service decoupled from that
     * itemtype. Paces itself between requests since this can process many rows in one pass, unlike
     * fetchForCve() called alone for a single freshly-added reference.
     *
     * @param list<string> $trackedCveIds
     * @return int Number of rows refreshed.
     */
    public static function refreshDue(array $trackedCveIds, int $staleAfterDays = 7): int
    {
        global $DB;

        $staleCutoff = date('Y-m-d H:i:s', strtotime("-{$staleAfterDays} days"));

        $existingByCveId = [];
        foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
            $existingByCveId[$row['cve_id']] = $row;
        }

        $due = [];
        foreach (array_unique($trackedCveIds) as $cveId) {
            $row = $existingByCveId[$cveId] ?? null;

            $isDue = $row === null
                || in_array($row['fetch_status'], ['pending', 'error', 'not_found'], true)
                || ($row['fetch_status'] === 'ok' && ($row['fetched_at'] ?? '') < $staleCutoff);

            if ($isDue) {
                $due[] = $cveId;
            }
        }

        $refreshed = 0;

        foreach ($due as $cveId) {
            if ($refreshed > 0) {
                usleep(self::MIN_DELAY_BETWEEN_REQUESTS_MICROSECONDS);
            }

            self::fetchForCve($cveId);
            $refreshed++;
        }

        return $refreshed;
    }

    /**
     * Ensures a row exists for $cveId (created as 'pending' on first reference, same convention as
     * a freshly-added-but-not-yet-enriched CVE), then merges $fields into it.
     *
     * @param array<string, mixed> $fields
     */
    private static function upsert(string $cveId, array $fields): void
    {
        global $DB;

        $exists = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['cve_id' => $cveId]])->count() > 0;

        if (!$exists) {
            $DB->insert(self::TABLE, ['cve_id' => $cveId, 'fetch_status' => 'pending']);
        }

        $DB->update(
            self::TABLE,
            $fields + ['fetched_at' => date('Y-m-d H:i:s')],
            ['cve_id' => $cveId]
        );
    }
}
