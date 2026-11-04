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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;

/**
 * Issue #376, atelier 1 : bien support d'une valeur métier, élément de GLPI (ordinateur, application,
 * fournisseur...) ou simple libellé quand il n'existe pas dans le parc (local, personnel...).
 */
class EbiosSupport extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiossupports';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Supporting asset', 'Supporting assets', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return ['name', 'itemtype', 'items_id', 'plugin_grcmanager_ebiosvalues_id'];
    }

    protected function normalize(array $input)
    {
        $itemtype = (string) ($input['itemtype'] ?? '');
        $itemsId = (int) ($input['items_id'] ?? 0);
        // Seuls les types proposés par le registre des processus, et un élément réellement lisible.
        if (!in_array($itemtype, BusinessProcessItem::getItemtypes(), true) || $itemsId <= 0) {
            $input['itemtype'] = '';
            $input['items_id'] = 0;

            return $input;
        }
        $item = is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null; // nosemgrep
        if ($item === null || !$item->can($itemsId, READ)) {
            return false;
        }
        $input['items_id'] = $itemsId;
        if (trim((string) ($input['name'] ?? '')) === '') {
            $input['name'] = (string) $item->getName();
        }

        return $input;
    }
}
