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

/**
 * Issue #376, atelier 1 : valeur métier (ce que l'organisation doit protéger), éventuellement
 * reliée à un processus métier du registre (issue #377).
 */
class EbiosValue extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosvalues';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Business value', 'Business values', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return ['name', 'description', 'plugin_grcmanager_businessprocesses_id'];
    }

    protected function normalize(array $input)
    {
        if (isset($input['plugin_grcmanager_businessprocesses_id'])) {
            $processId = (int) $input['plugin_grcmanager_businessprocesses_id'];
            $input['plugin_grcmanager_businessprocesses_id'] = $processId;
        }

        return $input;
    }

    /**
     * Retirer une valeur métier retire aussi ses événements redoutés et ses biens supports.
     */
    public function cleanDBonPurge()
    {
        $criteria = ['plugin_grcmanager_ebiosvalues_id' => $this->getID()];
        (new EbiosFearedEvent())->deleteByCriteria($criteria);
        (new EbiosSupport())->deleteByCriteria($criteria);
    }
}
