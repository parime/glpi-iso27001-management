<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use GlpiPlugin\Grcmanager\CausalLink;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class CausalLinkBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\CausalLink::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class CausalLinkBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\CausalLink::RIGHTNAME;
        public $dohistory = true;
    }
}
