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

namespace GlpiPlugin\Grcmanager\Compat;

/**
 * Issue #951 : les gabarits d'incident de sécurité utilisent des noms de table raccourcis (le nom
 * dérivé de la classe dépasse les 64 caractères de MySQL, voir
 * PluginGrcmanagerSecurityIncidentTemplate::getTable()). Le cœur ne sait pas retrouver la classe
 * depuis ces noms (`getItemTypeForTable()` renvoie null) : la liste des gabarits plantait
 * (« Class name must be a valid object or a string » dans le lien du nom) dès qu'un gabarit
 * existait, ce que l'utilisateur voyait comme « An unexpected error occurred ». On déclare donc
 * la correspondance table / classe dans le cache du cœur, dans les deux sens.
 */
final class ShortTableItemtypes
{
    /** @var array<string, string> table => classe */
    public const MAP = [
        'glpi_plugin_grcmanager_secincidenttemplates'
            => 'PluginGrcmanagerSecurityIncidentTemplate',
        'glpi_plugin_grcmanager_secincidenttemplates_hiddenfields'
            => 'PluginGrcmanagerSecurityIncidentTemplateHiddenField',
        'glpi_plugin_grcmanager_secincidenttemplates_mandatoryfields'
            => 'PluginGrcmanagerSecurityIncidentTemplateMandatoryField',
        'glpi_plugin_grcmanager_secincidenttemplates_predefinedfields'
            => 'PluginGrcmanagerSecurityIncidentTemplatePredefinedField',
        'glpi_plugin_grcmanager_secincidenttemplates_readonlyfields'
            => 'PluginGrcmanagerSecurityIncidentTemplateReadonlyField',
    ];

    public static function register(): void
    {
        global $CFG_GLPI;

        foreach (self::MAP as $table => $class) {
            $CFG_GLPI['glpiitemtypetables'][$table] = $class;
            $CFG_GLPI['glpitablesitemtype'][$class] = $table;
        }
    }
}
