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

use GlpiPlugin\Grcmanager\Compat\Base\SecurityIncidentTemplatePredefinedFieldBase;

/**
 * Same minimal shape as GLPI core's own `ChangeTemplatePredefinedField`.
 */
class PluginGrcmanagerSecurityIncidentTemplatePredefinedField extends SecurityIncidentTemplatePredefinedFieldBase
{
    public const ITEMTYPE = PluginGrcmanagerSecurityIncidentTemplate::class;
    public const ITEMS_ID = 'securityincidenttemplates_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_predefinedfields';
    }

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;

    /**
     * Same "Unknown ITIL type" hardcoded-switch surprise as `PluginGrcmanagerSecurityIncident
     * ::getItemsTable()` (see its own docblock): this time the switch lives directly inside
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

    /**
     * Issue #796 : le cœur de GLPI (11 et 12) appelle ici `self::getMultiplePredefinedValues()`,
     * et non `static::`. La surcharge ci-dessus est donc ignorée, et l'implémentation du cœur,
     * qui ne connaît que Ticket, Change et Problem, lève « Unknown ITIL type » : aperçu du gabarit
     * en erreur, champs prédéfinis (statut, priorité, impact...) impossibles à régler. Copie à
     * l'identique du cœur, sauf l'appel `static::`.
     *
     * @param int  $ID
     * @param bool $withtypeandcategory
     * @return array<string, mixed>
     */
    public function getPredefinedFields($ID, $withtypeandcategory = false)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => $this->getTable(),
            'WHERE' => [static::$items_id => $ID],
            'ORDER' => 'id',
        ]);

        $tt             = getItemForItemtype(static::$itemtype);
        $allowed_fields = $tt->getAllowedFields($withtypeandcategory, true);
        $fields         = [];
        $multiple       = static::getMultiplePredefinedValues();
        foreach ($iterator as $rule) {
            if (!isset($allowed_fields[$rule['num']])) {
                continue;
            }
            $field = $allowed_fields[$rule['num']];
            if (!in_array($rule['num'], $multiple)) {
                $fields[$field] = $rule['value'];
                continue;
            }
            if ($field == 'items_id') {
                $item_itemtype = explode('_', $rule['value']);
                if (count($item_itemtype) != 2) {
                    // Valeur invalide : ignorée, comme dans le cœur.
                    continue;
                }
                $fields[$field][$item_itemtype[0]][$item_itemtype[1]] = $item_itemtype[1];
            } else {
                $fields[$field][] = $rule['value'];
            }
        }
        return $fields;
    }
}
