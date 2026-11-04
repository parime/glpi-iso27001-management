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
 * Issue #376, atelier 2 : couple source de risque / objectif visé, avec sa motivation, ses ressources
 * et son activité. La pertinence calculée est indicative : l'étude retient ou écarte le couple, et
 * justifie son choix.
 */
class EbiosRiskSource extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosrisksources';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk source', 'Risk sources', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return ['name', 'objective', 'motivation', 'resources', 'activity', 'is_retained', 'justification'];
    }

    protected function normalize(array $input)
    {
        foreach (['motivation', 'resources', 'activity'] as $scale) {
            if (isset($input[$scale])) {
                $input[$scale] = EbiosRules::level($input[$scale]);
            }
        }
        if (array_key_exists('is_retained', $input)) {
            $input['is_retained'] = empty($input['is_retained']) ? 0 : 1;
        }

        return $input;
    }

    /**
     * Retirer une source de risque retire les scénarios stratégiques construits sur elle.
     */
    public function cleanDBonPurge()
    {
        (new EbiosStrategicScenario())->deleteByCriteria([self::getForeignKeyField() => $this->getID()]);
    }
}
