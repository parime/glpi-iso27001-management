<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\BusinessImpact;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;

/*
 * Propriétés typées sur GLPI 12, non typées sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class BusinessImpactBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\BusinessImpact::RIGHTNAME;
    }
} else {
    abstract class BusinessImpactBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\BusinessImpact::RIGHTNAME;
    }
}
