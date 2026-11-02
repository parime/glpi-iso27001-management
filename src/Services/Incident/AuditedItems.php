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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #371 : objets dont les changements entrent dans le journal d'audit des incidents, et
 * incident auquel chacun se rattache.
 */
final class AuditedItems
{
    public const ITEMTYPES = [
        'PluginGrcmanagerSecurityIncident',
        'PluginGrcmanagerSecurityIncidentTask',
        'PluginGrcmanagerSecurityIncident_Item',
        'PluginGrcmanagerSecurityIncident_User',
        'PluginGrcmanagerSecurityIncident_Group',
        'PluginGrcmanagerSecurityIncidentCost',
        'GlpiPlugin\\Grcmanager\\SecurityIncidentCve',
        'GlpiPlugin\\Grcmanager\\SecurityIncidentIoc',
        'GlpiPlugin\\Grcmanager\\SecurityIncidentTechnique',
        'GlpiPlugin\\Grcmanager\\SecurityIncidentAction',
        'GlpiPlugin\\Grcmanager\\SecurityIncidentPlaybookTask',
        'Document_Item',
        'ITILFollowup',
    ];

    private const INCIDENT = 'PluginGrcmanagerSecurityIncident';

    private const FOREIGN_KEY = 'plugin_grcmanager_securityincidents_id';

    /**
     * Identifiant de l'incident concerné, ou null si l'objet ne concerne pas un incident (un
     * suivi de ticket, un document joint à un autre objet...).
     *
     * @param object $item Objet GLPI (CommonDBTM).
     */
    public static function incidentOf(object $item): ?int
    {
        $fields = property_exists($item, 'fields') && is_array($item->fields) ? $item->fields : [];
        if ($item::class === self::INCIDENT || is_a($item, self::INCIDENT)) {
            return (int) ($fields['id'] ?? 0) ?: null;
        }
        if (isset($fields[self::FOREIGN_KEY])) {
            return (int) $fields[self::FOREIGN_KEY] ?: null;
        }
        if (($fields['itemtype'] ?? '') === self::INCIDENT && isset($fields['items_id'])) {
            return (int) $fields['items_id'] ?: null;
        }

        return null;
    }
}
