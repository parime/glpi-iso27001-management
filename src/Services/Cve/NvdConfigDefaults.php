<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Default NVD-enrichment settings, seeded once at install (src/Install/Installer.php) and used as
 * the fallback for an install that has never customized them — same split as
 * RiskMatrixConfig/RiskMatrixDefaults elsewhere in this plugin.
 */
final class NvdConfigDefaults
{
    /**
     * Opt-in, off by default: enrichment depends on an outbound call to a third-party API
     * (services.nvd.nist.gov) on every new CVE reference, an admin should turn this on
     * consciously rather than have it happen silently on upgrade — same reasoning as
     * `enable_environmental_passport` in the sibling plugin assetsign-glpi.
     */
    public const ENABLE_NVD_ENRICHMENT = false;

    /**
     * CVSS base score at/above which a tracked CVE is visually flagged as urgent (red badge,
     * sorted first) — NVD's own qualitative scale (see NvdCveParser::severityFromScore()) puts
     * 7.0 as the HIGH/CRITICAL boundary's floor, a reasonable, well-known default rather than an
     * arbitrary number.
     */
    public const CVSS_ALERT_THRESHOLD = 7.0;
}
