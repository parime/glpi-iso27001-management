<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que NetworkFlowBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class BreachExposureBase extends CommonDBTM
    {
        public static string $rightname = BreachExposure::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class BreachExposureBase extends CommonDBTM
    {
        public static $rightname = BreachExposure::RIGHTNAME;
        public $dohistory = true;
    }
}
