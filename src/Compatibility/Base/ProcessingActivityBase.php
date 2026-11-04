<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use GlpiPlugin\Grcmanager\ProcessingActivity;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que NetworkFlowBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class ProcessingActivityBase extends CommonDBTM
    {
        public static string $rightname = ProcessingActivity::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class ProcessingActivityBase extends CommonDBTM
    {
        public static $rightname = ProcessingActivity::RIGHTNAME;
        public $dohistory = true;
    }
}
