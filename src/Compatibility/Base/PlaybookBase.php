<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use GlpiPlugin\Grcmanager\Playbook;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class PlaybookBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\Playbook::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class PlaybookBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\Playbook::RIGHTNAME;
        public $dohistory = true;
    }
}
