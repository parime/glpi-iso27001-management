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
 * Issue #376, atelier 3 : scénario stratégique, le chemin d'une source de risque vers un événement
 * redouté, directement ou par une partie prenante. Sa gravité est celle de l'événement redouté.
 */
class EbiosStrategicScenario extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosstrategicscenarios';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Strategic scenario', 'Strategic scenarios', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return [
            'name', 'description', 'measures',
            EbiosRiskSource::getForeignKeyField(),
            EbiosFearedEvent::getForeignKeyField(),
            EbiosStakeholder::getForeignKeyField(),
        ];
    }

    protected function normalize(array $input)
    {
        if (
            !$this->hasStudyLink($input, EbiosRiskSource::class)
            || !$this->hasStudyLink($input, EbiosFearedEvent::class)
        ) {
            return false;
        }
        // Sans partie prenante : attaque directe.
        $stakeholderKey = EbiosStakeholder::getForeignKeyField();
        if ((int) ($input[$stakeholderKey] ?? 0) <= 0) {
            unset($input[$stakeholderKey]);
            if ($this->isNewItem()) {
                $input[$stakeholderKey] = 0;
            }
        } elseif (!$this->hasStudyLink($input, EbiosStakeholder::class)) {
            return false;
        }

        return $input;
    }

    /**
     * Retirer un scénario stratégique retire ses scénarios opérationnels.
     */
    public function cleanDBonPurge()
    {
        (new EbiosOperationalScenario())->deleteByCriteria([self::getForeignKeyField() => $this->getID()]);
    }
}
