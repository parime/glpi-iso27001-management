<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use GlpiPlugin\Grcmanager\NetworkFlow;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class NetworkFlowBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\NetworkFlow::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class NetworkFlowBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\NetworkFlow::RIGHTNAME;
        public $dohistory = true;
    }
}
