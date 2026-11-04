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

use GlpiPlugin\Grcmanager\Services\Risk\EbiosRules;

/**
 * Issue #376, atelier 1 : événement redouté sur une valeur métier, ses impacts et sa gravité (G1 à G4).
 */
class EbiosFearedEvent extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosfearedevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Feared event', 'Feared events', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return ['name', 'impacts', 'severity', 'plugin_grcmanager_ebiosvalues_id'];
    }

    protected function normalize(array $input)
    {
        if (isset($input['severity'])) {
            $input['severity'] = EbiosRules::level($input['severity']);
        }

        return $input;
    }
}
