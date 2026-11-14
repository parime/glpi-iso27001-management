<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;

/*
 * Propriétés typées sur GLPI 12, non typées sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class DataBreachBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\DataBreach::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class DataBreachBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\DataBreach::RIGHTNAME;
        public $dohistory = true;
    }
}
