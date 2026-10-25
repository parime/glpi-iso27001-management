<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use PluginGrcmanagerCausalLink;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class CausalLinkBase extends CommonDBTM
    {
        public static string $rightname = PluginGrcmanagerCausalLink::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class CausalLinkBase extends CommonDBTM
    {
        public static $rightname = PluginGrcmanagerCausalLink::RIGHTNAME;
        public $dohistory = true;
    }
}
