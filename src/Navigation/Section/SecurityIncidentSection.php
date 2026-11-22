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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Playbook;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use PluginGrcmanagerSecurityIncident;

/**
 * Incidents de sécurité (absorption de glpi-security-incidents) : dans le secteur Assistance, aux côtés
 * des tickets, problèmes et changements, et non dans le menu GRC. Un administrateur qui désactive le
 * module ne voit plus l'entrée, mais les données, droits et tables restent intacts.
 *
 * Issue #1041 : juste après « Changements » (GLPI place les entrées des plugins à la fin du secteur,
 * après « Changements récurrents »). Les anciens liens d'en-tête de l'entrée deviennent des onglets
 * de rubrique : playbooks, import des alertes EDR (dont la revue des alertes SentinelOne) et journal
 * d'audit. Les modèles de messages de crise sont dans « Crise et continuité ». Les notifications aux
 * autorités restent un onglet de la fiche de l'incident.
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

    public static function after(): string
    {
        return \Change::class;
    }

    public static function pages(): array
    {
        return [
            Playbook::class,
            // Page sans liste propre : réservée, comme avant, à qui peut créer un incident.
            SecurityIncidentEdrImport::class => [
                'title'     => __('EDR alerts import', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/securityincidentedrimport.php',
                'icon'      => SecurityIncidentEdrImport::getIcon(),
                'rights_of' => PluginGrcmanagerSecurityIncident::class,
                'right'     => [PluginGrcmanagerSecurityIncident::RIGHTNAME, CREATE],
            ],
            AuditLog::class,
        ];
    }

    public static function enabled(): bool
    {
        return (bool) SecurityIncidentModuleConfig::load()['securityincident_enabled'];
    }
}
