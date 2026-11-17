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
use GlpiPlugin\Grcmanager\Training;

/*
 * Parent of \\GlpiPlugin\Grcmanager\Training.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class TrainingBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\Training::RIGHTNAME;
    }
} else {
    abstract class TrainingBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\Training::RIGHTNAME;
    }
}
