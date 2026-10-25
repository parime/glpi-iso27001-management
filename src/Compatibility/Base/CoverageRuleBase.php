<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use PluginGrcmanagerCoverageRule;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class CoverageRuleBase extends CommonDBTM
    {
        public static string $rightname = PluginGrcmanagerCoverageRule::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class CoverageRuleBase extends CommonDBTM
    {
        public static $rightname = PluginGrcmanagerCoverageRule::RIGHTNAME;
        public $dohistory = true;
    }
}
