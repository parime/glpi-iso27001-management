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
use GlpiPlugin\Grcmanager\CrisisExercise;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class CrisisExerciseBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\CrisisExercise::RIGHTNAME;
    }
} else {
    abstract class CrisisExerciseBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\CrisisExercise::RIGHTNAME;
    }
}
