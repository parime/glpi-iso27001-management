<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonItilObject_Item;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use PluginGrcmanagerSecurityIncident_Item;

/*
 * Parent of \PluginGrcmanagerSecurityIncident_Item.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SecurityIncidentItemBase extends CommonItilObject_Item
    {
        public static ?string $itemtype_1 = PluginGrcmanagerSecurityIncident_Item::ITEMTYPE_1;

        public static ?string $items_id_1 = PluginGrcmanagerSecurityIncident_Item::ITEMS_ID_1;

        public static ?string $itemtype_2 = PluginGrcmanagerSecurityIncident_Item::ITEMTYPE_2;

        public static ?string $items_id_2 = PluginGrcmanagerSecurityIncident_Item::ITEMS_ID_2;

        public static int $checkItem_2_Rights = PluginGrcmanagerSecurityIncident_Item::CHECK_ITEM_2_RIGHTS;
    }
} else {
    abstract class SecurityIncidentItemBase extends CommonItilObject_Item
    {
        public static $itemtype_1 = PluginGrcmanagerSecurityIncident_Item::ITEMTYPE_1;

        public static $items_id_1 = PluginGrcmanagerSecurityIncident_Item::ITEMS_ID_1;

        public static $itemtype_2 = PluginGrcmanagerSecurityIncident_Item::ITEMTYPE_2;

        public static $items_id_2 = PluginGrcmanagerSecurityIncident_Item::ITEMS_ID_2;

        public static $checkItem_2_Rights = PluginGrcmanagerSecurityIncident_Item::CHECK_ITEM_2_RIGHTS;
    }
}
