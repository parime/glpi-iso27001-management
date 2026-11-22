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

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;

/*
 * Parent of \GlpiPlugin\Grcmanager\Delegation (issue #618).
 *
 * Redeclares the GLPI core property that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait (see AccessReviewBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class DelegationBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\Delegation::RIGHTNAME;
    }
} else {
    abstract class DelegationBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\Delegation::RIGHTNAME;
    }
}
