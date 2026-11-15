<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat;

/**
 * Pure version-comparison logic used to gate plugin activation. Kept free of any GLPI runtime
 * dependency so it can be unit-tested without a running GLPI instance.
 */
final class RequirementChecker
{
    public function isPhpVersionSupported(string $currentPhpVersion, string $minPhpVersion): bool
    {
        return version_compare($currentPhpVersion, $minPhpVersion, '>=');
    }

    public function isGlpiVersionSupported(
        string $currentGlpiVersion,
        string $minGlpiVersion,
        string $maxGlpiVersion
    ): bool {
        return version_compare($currentGlpiVersion, $minGlpiVersion, '>=')
            && version_compare($currentGlpiVersion, $maxGlpiVersion, '<=');
    }
}
