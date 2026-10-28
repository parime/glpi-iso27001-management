<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
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
