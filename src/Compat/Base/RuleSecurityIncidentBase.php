<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use RuleCommonITILObject;
use RulePluginGrcmanagerSecurityIncident;

/*
 * Parent of \RulePluginGrcmanagerSecurityIncident.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class RuleSecurityIncidentBase extends RuleCommonITILObject
    {
        public static string $rightname = RulePluginGrcmanagerSecurityIncident::RIGHTNAME;
    }
} else {
    abstract class RuleSecurityIncidentBase extends RuleCommonITILObject
    {
        public static $rightname = RulePluginGrcmanagerSecurityIncident::RIGHTNAME;
    }
}
