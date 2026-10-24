<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use PluginGrcmanagerPeriodicReview;

/*
 * Propriété statique typée sur GLPI 12, non typée sur GLPI 11 (même schéma que EvidenceBase).
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class PeriodicReviewBase extends CommonDBTM
    {
        public static string $rightname = PluginGrcmanagerPeriodicReview::RIGHTNAME;
    }
} else {
    abstract class PeriodicReviewBase extends CommonDBTM
    {
        public static $rightname = PluginGrcmanagerPeriodicReview::RIGHTNAME;
    }
}
