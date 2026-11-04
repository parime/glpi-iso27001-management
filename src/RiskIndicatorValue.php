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

namespace GlpiPlugin\Grcmanager;

use GlpiPlugin\Grcmanager\Compatibility\Base\RiskIndicatorValueBase;

/**
 * Issue #378 : un relevé de l'historique d'un indicateur de risque (date, valeur, statut au moment
 * du relevé, commentaire, saisi à la main ou relevé automatiquement). Ni menu ni fiche : ajouté et
 * supprimé depuis la fiche du KRI (front/riskindicatorvalue.form.php), avec les droits de ce KRI.
 */
class RiskIndicatorValue extends RiskIndicatorValueBase
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_riskindicatorvalues';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk indicator value', 'Risk indicator values', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-timeline';
    }

    /**
     * Relevés d'un KRI, du plus ancien au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    public static function getValues(int $kriId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_riskindicators_id' => $kriId],
            'ORDER' => ['value_date ASC', 'id ASC'],
        ]), false));
    }
}
