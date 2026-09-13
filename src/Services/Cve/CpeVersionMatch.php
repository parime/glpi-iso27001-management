<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Evaluates whether an installed software version falls inside the version range one of a CVE's
 * `affected_cpes` entries (see NvdCveParser::affectedCpes()) actually covers — a 3-state result,
 * not a boolean, same design as the sibling plugin glpi-vulnerability-manager's own
 * `Services\Matching\CpeVersionEvaluator`/`VersionRangeMatcher` (ADR 0012):
 *
 * - MATCHED: the installed version is confirmed inside the vulnerable range.
 * - EXCLUDED: the installed version is confirmed OUTSIDE the vulnerable range (e.g. already
 *   patched) — must remove the candidate entirely from a match list, never just deprioritize it.
 * - UNKNOWN: neither explicit version bounds nor a concrete version in the CPE URI itself are
 *   available to compare against — genuinely unknown, never guessed at or silently dropped.
 *
 * Unlike that sibling plugin, there is no separate weighted "confidence score" alongside this
 * state: here, product identity is already resolved to a binary yes/no via the canonical-product
 * catalog (CpeIdentity + admin-declared CpeReference/ProductAlias) before this class is ever
 * consulted, so the only remaining question is the version — one 3-state result is the complete
 * answer, not one input into a composite score.
 */
final class CpeVersionMatch
{
    public const MATCHED = 'matched';

    public const EXCLUDED = 'excluded';

    public const UNKNOWN = 'unknown';

    /**
     * @param array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * } $affectedCpe
     */
    public static function evaluate(array $affectedCpe, string $installedVersion): string
    {
        $hasExplicitBound = $affectedCpe['version_start_including'] !== null
            || $affectedCpe['version_start_excluding'] !== null
            || $affectedCpe['version_end_including'] !== null
            || $affectedCpe['version_end_excluding'] !== null;

        if ($hasExplicitBound) {
            return self::evaluateAgainstBounds($affectedCpe, $installedVersion);
        }

        $cpeVersion = self::versionFieldOf($affectedCpe['cpe23_uri']);

        if ($cpeVersion === null) {
            return self::UNKNOWN;
        }

        return self::versionsEqual($installedVersion, $cpeVersion) ? self::MATCHED : self::EXCLUDED;
    }

    /**
     * @param array{
     *     version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * } $affectedCpe
     */
    private static function evaluateAgainstBounds(array $affectedCpe, string $installedVersion): string
    {
        $checks = [
            [$affectedCpe['version_start_including'], '>='],
            [$affectedCpe['version_start_excluding'], '>'],
            [$affectedCpe['version_end_including'], '<='],
            [$affectedCpe['version_end_excluding'], '<'],
        ];

        foreach ($checks as [$bound, $operator]) {
            if ($bound === null) {
                continue;
            }

            if (!self::compare($installedVersion, $bound, $operator)) {
                return self::EXCLUDED;
            }
        }

        return self::MATCHED;
    }

    /**
     * The CPE 2.3 URI's own 6th colon-separated field (index 5) is itself a "version" component —
     * used only when the CVE entry carries no explicit start/end bounds at all. A wildcard (`*`) or
     * "not applicable" (`-`) marker means no concrete version to compare against, same as absent.
     */
    private static function versionFieldOf(string $cpe23Uri): ?string
    {
        $fields = explode(':', $cpe23Uri);
        $version = $fields[5] ?? null;

        if ($version === null || $version === '*' || $version === '-' || $version === '') {
            return null;
        }

        return $version;
    }

    private static function versionsEqual(string $a, string $b): bool
    {
        return self::compare($a, $b, '==');
    }

    /**
     * version_compare() rather than a string comparison — chosen specifically to avoid the classic
     * `"9.10" < "9.9"` string-ordering bug, same reasoning as the sibling plugin's own
     * VersionRangeMatcher. Malformed/non-numeric version strings (real installed software version
     * strings are not always clean semver) still degrade gracefully: version_compare() falls back
     * to its own lexical rules rather than throwing, so this never fatals on messy real-world data.
     */
    private static function compare(string $a, string $b, string $operator): bool
    {
        return version_compare($a, $b, $operator);
    }
}
