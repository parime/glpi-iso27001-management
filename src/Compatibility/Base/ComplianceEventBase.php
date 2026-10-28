<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use GlpiPlugin\Grcmanager\ComplianceEvent;

/*
 * Propriétés typées sur GLPI 12, non typées sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class ComplianceEventBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\ComplianceEvent::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class ComplianceEventBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\ComplianceEvent::RIGHTNAME;
        public $dohistory = true;
    }
}
