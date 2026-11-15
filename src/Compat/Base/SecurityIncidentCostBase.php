<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonITILCost;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use PluginGrcmanagerSecurityIncidentCost;

/*
 * Parent of \PluginGrcmanagerSecurityIncidentCost.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SecurityIncidentCostBase extends CommonITILCost
    {
        public static string $itemtype = PluginGrcmanagerSecurityIncidentCost::ITEMTYPE;

        public static string $items_id = PluginGrcmanagerSecurityIncidentCost::ITEMS_ID;
    }
} else {
    abstract class SecurityIncidentCostBase extends CommonITILCost
    {
        public static $itemtype = PluginGrcmanagerSecurityIncidentCost::ITEMTYPE;

        public static $items_id = PluginGrcmanagerSecurityIncidentCost::ITEMS_ID;
    }
}
