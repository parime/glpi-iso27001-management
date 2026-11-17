<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

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
