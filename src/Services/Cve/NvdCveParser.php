<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Pure transformation of one raw NVD API 2.0 `cve` object (from
 * `GET https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=CVE-...`, the single-CVE lookup
 * endpoint — this plugin only ever enriches CVE references the user already typed in, never a bulk
 * date-range sync) into the normalized shape this plugin persists. No GLPI dependency, no HTTP call
 * here: see NvdCveEnrichmentService for the network-bound half (same pure/GLPI-bound split as
 * RiskScoringService vs RiskMatrixConfig elsewhere in this plugin).
 *
 * CVSS selection and mb-safe truncation follow the same approach as the sibling plugin
 * glpi-vulnerability-manager's own NvdConnector::bestCvss()/truncate() (confirmed against real NVD
 * responses: CVSS v4.0/v3.1/v3.0/v2 metrics can coexist on one CVE, "Primary" source preferred over
 * "Secondary"), without that connector's date-range pagination/rate-limit machinery, irrelevant for
 * a single-identifier lookup.
 *
 * Patch/remediation link extraction (`patchLinks()` below) has no equivalent in
 * glpi-vulnerability-manager's own NvdConnector, which only ever persists the static NVD detail-page
 * URL, never the CVE's own `references[]` array — confirmed by reading that connector's
 * `normalize()`. Real NVD payload (CVE-2021-44228, "Log4Shell") does carry genuinely actionable
 * entries here, e.g. a Microsoft/Oracle advisory tagged `["Patch", ...]`.
 */
final class NvdCveParser
{
    /**
     * @var array<string, string>
     */
    private const CVSS_METRIC_KEYS = [
        'cvssMetricV40' => '4.0',
        'cvssMetricV31' => '3.1',
        'cvssMetricV30' => '3.0',
        'cvssMetricV2'  => '2.0',
    ];

    /**
     * Reference tags NVD uses that point to an actual remediation, not just background reading —
     * confirmed against the real Log4Shell payload (which also carries plain "Release Notes"/
     * "Third Party Advisory"/"Exploit"/"VDB Entry" tags, deliberately excluded here: never surface
     * an exploit writeup as if it were a fix).
     */
    private const PATCH_TAGS = ['Patch', 'Vendor Advisory'];

    /**
     * @param array<string, mixed> $cve Raw `vulnerabilities[0].cve` object from the NVD API response.
     * @return array{
     *     cve_id: string,
     *     cvss_score: ?float,
     *     cvss_vector: ?string,
     *     severity: ?string,
     *     description: string,
     *     published_at: ?string,
     *     patch_links: list<array{url: string, tag: string}>
     * }
     */
    public static function parse(array $cve): array
    {
        $cveId = (string) ($cve['id'] ?? '');
        [$cvssScore, $cvssVector, $severity] = self::bestCvss($cve);

        return [
            'cve_id'       => $cveId,
            'cvss_score'   => $cvssScore,
            'cvss_vector'  => $cvssVector,
            'severity'     => $severity ?? self::severityFromScore($cvssScore),
            'description'  => self::truncate(self::englishDescription($cve), 2000),
            'published_at' => self::normalizeDate($cve['published'] ?? null),
            'patch_links'  => self::patchLinks($cve),
        ];
    }

    /**
     * @param array<string, mixed> $cve
     */
    private static function englishDescription(array $cve): string
    {
        $descriptions = is_array($cve['descriptions'] ?? null) ? $cve['descriptions'] : [];

        foreach ($descriptions as $entry) {
            if (is_array($entry) && ($entry['lang'] ?? null) === 'en') {
                return (string) ($entry['value'] ?? '');
            }
        }

        $first = $descriptions[0] ?? null;

        return is_array($first) ? (string) ($first['value'] ?? '') : '';
    }

    /**
     * Picks the most recent CVSS version available, "Primary" source preferred over "Secondary" —
     * same rule as glpi-vulnerability-manager's NvdConnector::bestCvss(). Unlike that connector,
     * also returns `baseSeverity` straight from NVD's own analysis (present on every v3.x/v4.0
     * metric block) rather than deriving one — a v2-only CVE has no `baseSeverity` field at all, the
     * only case severityFromScore() below is used, and only with the well-established CVSS v2
     * qualitative scale NIST itself published, never an invented threshold.
     *
     * @param array<string, mixed> $cve
     * @return array{0: ?float, 1: ?string, 2: ?string}
     */
    private static function bestCvss(array $cve): array
    {
        $metrics = is_array($cve['metrics'] ?? null) ? $cve['metrics'] : [];

        foreach (self::CVSS_METRIC_KEYS as $key => $version) {
            $entries = is_array($metrics[$key] ?? null) ? $metrics[$key] : [];

            if (count($entries) === 0) {
                continue;
            }

            $chosen = null;

            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['type'] ?? null) === 'Primary') {
                    $chosen = $entry;

                    break;
                }
            }

            $chosen ??= $entries[0];
            $data = is_array($chosen['cvssData'] ?? null) ? $chosen['cvssData'] : [];

            if (isset($data['baseScore'])) {
                $vector = isset($data['vectorString']) ? (string) $data['vectorString'] : null;
                $severity = isset($data['baseSeverity']) ? strtoupper((string) $data['baseSeverity']) : null;

                return [(float) $data['baseScore'], $vector, $severity];
            }
        }

        return [null, null, null];
    }

    /**
     * CVSS v2 has no `baseSeverity` field in the NVD payload (that concept was introduced in v3) —
     * this reproduces NIST's own published v2 qualitative scale
     * (https://nvd.nist.gov/vuln-metrics/cvss, "Qualitative Severity Rating Scale") rather than
     * inventing one, and is only ever reached when bestCvss() found a v2-only metric.
     */
    private static function severityFromScore(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 7.0 => 'HIGH',
            $score >= 4.0 => 'MEDIUM',
            default        => 'LOW',
        };
    }

    /**
     * Flattens `references[]`, keeping only entries tagged as an actual remediation (see
     * PATCH_TAGS) — deduplicated by URL, since the same advisory sometimes appears twice with
     * slightly different tag sets in real NVD data (confirmed on CVE-2021-44228).
     *
     * @param array<string, mixed> $cve
     * @return list<array{url: string, tag: string}>
     */
    private static function patchLinks(array $cve): array
    {
        $references = is_array($cve['references'] ?? null) ? $cve['references'] : [];
        $seenUrls   = [];
        $links      = [];

        foreach ($references as $reference) {
            if (!is_array($reference) || !isset($reference['url']) || !is_string($reference['url'])) {
                continue;
            }

            $tags = is_array($reference['tags'] ?? null) ? $reference['tags'] : [];
            $matchedTag = null;

            foreach (self::PATCH_TAGS as $patchTag) {
                if (in_array($patchTag, $tags, true)) {
                    $matchedTag = $patchTag;

                    break;
                }
            }

            if ($matchedTag === null || isset($seenUrls[$reference['url']])) {
                continue;
            }

            $seenUrls[$reference['url']] = true;
            $links[] = ['url' => $reference['url'], 'tag' => $matchedTag];
        }

        return $links;
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // NVD's `published` is `Y-m-d\TH:i:s.v` (confirmed live) — reformatted to plain
        // `Y-m-d H:i:s` for storage in a native MySQL `timestamp` column.
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);

        return $date !== false ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * mb_* required: byte-based substr() can split a multi-byte UTF-8 character in half, producing
     * an invalid sequence MySQL's strict utf8mb4 mode rejects outright — the exact bug
     * glpi-vulnerability-manager's own NvdConnector::truncate() docblock documents hitting in
     * production against real NVD data.
     */
    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
    }
}
