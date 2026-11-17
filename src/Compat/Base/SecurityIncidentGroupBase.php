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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use CommonITILActor;
use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use PluginGrcmanagerSecurityIncident_Group;

/*
 * Parent of \PluginGrcmanagerSecurityIncident_Group.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SecurityIncidentGroupBase extends CommonITILActor
    {
        public static ?string $itemtype_1 = PluginGrcmanagerSecurityIncident_Group::ITEMTYPE_1;

        public static ?string $items_id_1 = PluginGrcmanagerSecurityIncident_Group::ITEMS_ID_1;

        public static ?string $itemtype_2 = PluginGrcmanagerSecurityIncident_Group::ITEMTYPE_2;

        public static ?string $items_id_2 = PluginGrcmanagerSecurityIncident_Group::ITEMS_ID_2;
    }
} else {
    abstract class SecurityIncidentGroupBase extends CommonITILActor
    {
        public static $itemtype_1 = PluginGrcmanagerSecurityIncident_Group::ITEMTYPE_1;

        public static $items_id_1 = PluginGrcmanagerSecurityIncident_Group::ITEMS_ID_1;

        public static $itemtype_2 = PluginGrcmanagerSecurityIncident_Group::ITEMTYPE_2;

        public static $items_id_2 = PluginGrcmanagerSecurityIncident_Group::ITEMS_ID_2;
    }
}
