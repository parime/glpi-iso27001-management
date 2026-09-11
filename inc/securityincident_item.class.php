<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

/**
 * Polymorphic link (any GLPI asset itemtype) between a PluginGrcmanagerSecurityIncident and
 * the assets it affects — same minimal shape as GLPI core's own `Change_Item`.
 */
class PluginGrcmanagerSecurityIncident_Item extends CommonItilObject_Item
{
    public static $itemtype_1 = PluginGrcmanagerSecurityIncident::class;

    public static $items_id_1 = 'plugin_grcmanager_securityincidents_id';

    public static $itemtype_2 = 'itemtype';

    public static $items_id_2 = 'items_id';

    public static $checkItem_2_Rights = self::DONT_CHECK_ITEM_RIGHTS;

    public static function getTypeName($nb = 0)
    {
        return _n('Security incident item', 'Security incident items', $nb, 'grcmanager');
    }
}
