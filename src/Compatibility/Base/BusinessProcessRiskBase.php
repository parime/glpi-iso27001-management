<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\BusinessProcessRisk;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;

/*
 * Propriétés typées sur GLPI 12, non typées sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class BusinessProcessRiskBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\BusinessProcessRisk::RIGHTNAME;
    }
} else {
    abstract class BusinessProcessRiskBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\BusinessProcessRisk::RIGHTNAME;
    }
}
