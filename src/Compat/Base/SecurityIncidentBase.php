<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonITILObject;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use PluginGrcmanagerSecurityIncident;

/*
 * Parent of \PluginGrcmanagerSecurityIncident.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SecurityIncidentBase extends CommonITILObject
    {
        public bool $dohistory = true;

        public string $userlinkclass = PluginGrcmanagerSecurityIncident::USERLINKCLASS;

        public string $grouplinkclass = PluginGrcmanagerSecurityIncident::GROUPLINKCLASS;

        public string $supplierlinkclass = PluginGrcmanagerSecurityIncident::SUPPLIERLINKCLASS;

        protected bool $usenotepad = true;

        public static string $rightname = PluginGrcmanagerSecurityIncident::RIGHTNAME;
    }
} else {
    abstract class SecurityIncidentBase extends CommonITILObject
    {
        public $dohistory = true;

        public $userlinkclass = PluginGrcmanagerSecurityIncident::USERLINKCLASS;

        public $grouplinkclass = PluginGrcmanagerSecurityIncident::GROUPLINKCLASS;

        public $supplierlinkclass = PluginGrcmanagerSecurityIncident::SUPPLIERLINKCLASS;

        protected $usenotepad = true;

        public static $rightname = PluginGrcmanagerSecurityIncident::RIGHTNAME;
    }
}
