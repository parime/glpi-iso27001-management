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
 * Same minimal shape as GLPI core's own `ChangeTemplatePredefinedField`.
 */
class PluginGrcmanagerSecurityIncidentTemplatePredefinedField extends ITILTemplatePredefinedField
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_predefinedfields';
    }

    public static $itemtype = PluginGrcmanagerSecurityIncidentTemplate::class;

    public static $items_id = 'securityincidenttemplates_id';

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;

    /**
     * Same "Unknown ITIL type" hardcoded-switch surprise as `PluginGrcmanagerSecurityIncident
     * ::getItemsTable()` (see its own docblock) — this time the switch lives directly inside
     * `ITILTemplatePredefinedField::getMultiplePredefinedValues()` itself (not delegated to
     * `$itiltype::getItemsTable()`), so overriding the ITIL object's own method isn't enough; this
     * one has to be overridden here too. Identical to core's own implementation except the
     * `$itemstable` switch, replaced with the generic `$itil_class::getItemsTable()` call this
     * plugin's own ITIL class now supports.
     */
    public static function getMultiplePredefinedValues(): array
    {
        $itil_class = static::$itiltype;
        $itil_object = getItemForItemtype(static::$itiltype);
        $itemstable = $itil_class::getItemsTable();

        return [
           $itil_object->getSearchOptionIDByField('field', 'name', 'glpi_documents'),
           $itil_object->getSearchOptionIDByField('field', 'items_id', $itemstable),
           $itil_object->getSearchOptionIDByField('field', 'name', 'glpi_tasktemplates'),
        ];
    }
}
