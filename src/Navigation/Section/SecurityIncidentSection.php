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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use PluginGrcmanagerSecurityIncident;

/**
 * Incidents de sécurité (absorption de glpi-security-incidents) : dans le secteur Assistance, aux côtés
 * des tickets, problèmes et changements, et non dans le menu GRC. Un administrateur qui désactive le
 * module ne voit plus l'entrée, mais les données, droits et tables restent intacts.
 */
final class SecurityIncidentSection extends AbstractSection
{
    public static function sector(): string
    {
        return 'helpdesk';
    }

    public static function parent(): string
    {
        return PluginGrcmanagerSecurityIncident::class;
    }

    public static function enabled(): bool
    {
        return (bool) SecurityIncidentModuleConfig::load()['securityincident_enabled'];
    }
}
