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
 * Issue #376, atelier 4 : scénario opérationnel, le mode opératoire qui réalise un scénario stratégique
 * sur un bien support, avec sa vraisemblance (V1 à V4).
 */
class EbiosOperationalScenario extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosoperationalscenarios';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Operational scenario', 'Operational scenarios', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return [
            'name', 'attack_sequence', 'likelihood',
            EbiosStrategicScenario::getForeignKeyField(),
            EbiosSupport::getForeignKeyField(),
        ];
    }

    protected function normalize(array $input)
    {
        if (!$this->hasStudyLink($input, EbiosStrategicScenario::class)) {
            return false;
        }
        if (isset($input['likelihood'])) {
            $input['likelihood'] = EbiosRules::level($input['likelihood']);
        }
        // Le bien support est facultatif.
        $supportKey = EbiosSupport::getForeignKeyField();
        if ((int) ($input[$supportKey] ?? 0) <= 0) {
            unset($input[$supportKey]);
            if ($this->isNewItem()) {
                $input[$supportKey] = 0;
            }
        } elseif (!$this->hasStudyLink($input, EbiosSupport::class)) {
            return false;
        }

        return $input;
    }
}
