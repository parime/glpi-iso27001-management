<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use PluginGrcmanagerComplianceEvent;

/*
 * Propriétés typées sur GLPI 12, non typées sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class ComplianceEventBase extends CommonDBTM
    {
        public static string $rightname = PluginGrcmanagerComplianceEvent::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class ComplianceEventBase extends CommonDBTM
    {
        public static $rightname = PluginGrcmanagerComplianceEvent::RIGHTNAME;
        public $dohistory = true;
    }
}
