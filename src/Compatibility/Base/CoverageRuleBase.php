<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use GlpiPlugin\Grcmanager\CoverageRule;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class CoverageRuleBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\CoverageRule::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class CoverageRuleBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\CoverageRule::RIGHTNAME;
        public $dohistory = true;
    }
}
