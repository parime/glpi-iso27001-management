<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use PluginGrcmanagerWaiver;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class WaiverBase extends CommonDBTM
    {
        public static string $rightname = PluginGrcmanagerWaiver::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class WaiverBase extends CommonDBTM
    {
        public static $rightname = PluginGrcmanagerWaiver::RIGHTNAME;
        public $dohistory = true;
    }
}
