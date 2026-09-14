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
 *   available to compare against — genuinely unknown, never guessed at or silently dropped. Also
 *   returned whenever the INSTALLED version itself doesn't look like a version at all (see
 *   looksLikeAVersion()) — real GLPI software inventories do contain free-text version fields
 *   ("unknown", "N/A", "latest", a build hash...), and version_compare() never errors on these,
 *   it just degrades to treating them as very low precedence, which silently produced a confident
 *   EXCLUDED verdict for input the plugin has no real basis to judge — confirmed live against a
 *   real installed-version string ("unknown") on the shared Docker instance before this fix.
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
        if (!self::looksLikeAVersion($installedVersion)) {
            return self::UNKNOWN;
        }

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
     * Guards every comparison against a genuinely non-version installed value (see evaluate()) —
     * deliberately permissive (only requires a leading digit) rather than a strict semver pattern,
     * so real messy-but-real version strings ("2.0:beta9", "2.14.1-patched", a single "3") are
     * still compared normally; only clearly non-numeric free text ("unknown", "N/A", "latest", an
     * empty string) is turned away as UNKNOWN instead of silently miscompared.
     */
    private static function looksLikeAVersion(string $version): bool
    {
        return preg_match('/^\d/', trim($version)) === 1;
    }

    /**
     * version_compare() rather than a string comparison — chosen specifically to avoid the classic
     * `"9.10" < "9.9"` string-ordering bug, same reasoning as the sibling plugin's own
     * VersionRangeMatcher. Only ever reached once looksLikeAVersion() has already screened
     * $installedVersion, so its own fallback-to-lexical-rules behavior on messy input no longer
     * has a chance to silently misjudge a non-version string as excluded.
     */
    private static function compare(string $a, string $b, string $operator): bool
    {
        return version_compare($a, $b, $operator);
    }
}
