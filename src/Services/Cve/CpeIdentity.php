<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Reduces a full CPE 2.3 URI (`cpe:2.3:part:vendor:product:version:update:edition:language:
 * sw_edition:target_sw:target_hw:other`) to its identity prefix (`cpe:2.3:part:vendor:product`,
 * the first 5 colon-separated fields) — one catalog entry then matches every version/edition
 * variant NVD reports for the same real product, rather than needing one row per exact CPE string.
 *
 * Same technique as the sibling plugin glpi-vulnerability-manager's own
 * `Services\Matching\CpeIdentity::prefixOf()` (ADR 0011/0012), with one deliberate fix rather than
 * a reproduced defect: that connector splits on a naive `explode(':')`, which breaks on a `\:`
 * escaped colon inside a component (the CPE 2.3 spec allows escaping special characters, including
 * `:`, within a single field — e.g. a product name that itself contains a colon). Documented there
 * as an accepted, never-observed-in-practice limitation; fixed properly here instead since the cost
 * is a regex, not a redesign.
 */
final class CpeIdentity
{
    /**
     * Splits on an unescaped colon only (a colon NOT preceded by a backslash) — `\:` inside a
     * component is kept intact rather than treated as a field separator.
     */
    private const UNESCAPED_COLON_PATTERN = '/(?<!\\\\):/';

    /**
     * @return string The `cpe:2.3:part:vendor:product` prefix, or the input unchanged if it has
     *                 fewer than 5 fields (malformed input — never silently truncated to something
     *                 shorter that could accidentally over-match).
     */
    public static function prefixOf(string $cpe23Uri): string
    {
        $fields = preg_split(self::UNESCAPED_COLON_PATTERN, $cpe23Uri) ?: [$cpe23Uri];

        if (count($fields) < 5) {
            return $cpe23Uri;
        }

        return implode(':', array_slice($fields, 0, 5));
    }
}
