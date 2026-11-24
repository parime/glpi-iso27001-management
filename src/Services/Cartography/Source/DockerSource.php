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

namespace GlpiPlugin\Grcmanager\Services\Cartography\Source;

use GlpiPlugin\Grcmanager\Services\Cartography\EdgeSource;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use Plugin;

/**
 * Issue #1082 : services de l'Inventaire Docker (plugin dockerinventory), seulement s'il est actif.
 * Tables du plugin, en lecture seule : un nœud (glpi_plugin_dockerinventory_nodes, rattaché à un
 * ordinateur par computers_id) porte les services qui y tournent (..._services_nodes) ; un service
 * porte l'application GLPI à laquelle le plugin l'a rattaché (appliances_id). Services retirés ignorés.
 */
final class DockerSource implements EdgeSource
{
    private const NODES = 'glpi_plugin_dockerinventory_nodes';
    private const SERVICES = 'glpi_plugin_dockerinventory_services';
    private const SERVICES_NODES = 'glpi_plugin_dockerinventory_services_nodes';

    public static function available(): bool
    {
        global $DB;

        return Plugin::isPluginActive('dockerinventory')
            && $DB->tableExists(self::NODES)
            && $DB->tableExists(self::SERVICES)
            && $DB->tableExists(self::SERVICES_NODES);
    }

    public static function supporters(string $itemtype, int $itemsId): array
    {
        if (!self::available()) {
            return [];
        }
        if ($itemtype === Layers::DOCKER_SERVICE) {
            return self::computersOf(['sn.plugin_dockerinventory_services_id' => $itemsId]);
        }
        if ($itemtype === 'Appliance') {
            return self::services(['s.appliances_id' => $itemsId]);
        }

        return [];
    }

    public static function dependents(string $itemtype, int $itemsId): array
    {
        if (!self::available()) {
            return [];
        }
        if ($itemtype === 'Computer') {
            return self::services(['n.computers_id' => $itemsId]);
        }
        if ($itemtype === Layers::DOCKER_SERVICE) {
            global $DB;

            $row = $DB->request([
                'SELECT' => ['appliances_id'],
                'FROM'   => self::SERVICES,
                'WHERE'  => ['id' => $itemsId],
            ])->current();
            $applianceId = (int) ($row['appliances_id'] ?? 0);

            return $applianceId > 0 ? [ImpactGraph::key('Appliance', $applianceId)] : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $where
     * @return list<string>
     */
    private static function services(array $where): array
    {
        global $DB;

        $keys = [];
        $criteria = [
            'SELECT'   => ['s.id'],
            'DISTINCT' => true,
            'FROM'     => self::SERVICES . ' AS s',
            'WHERE'    => ['s.is_retired' => 0] + $where,
        ];
        if (isset($where['n.computers_id'])) {
            $criteria['INNER JOIN'] = [
                self::SERVICES_NODES . ' AS sn' => [
                    'ON' => ['sn' => 'plugin_dockerinventory_services_id', 's' => 'id'],
                ],
                self::NODES . ' AS n'           => [
                    'ON' => ['sn' => 'plugin_dockerinventory_nodes_id', 'n' => 'id'],
                ],
            ];
        }
        foreach ($DB->request($criteria) as $row) {
            $keys[] = ImpactGraph::key(Layers::DOCKER_SERVICE, (int) $row['id']);
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $where
     * @return list<string>
     */
    private static function computersOf(array $where): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['n.computers_id'],
                'DISTINCT'   => true,
                'FROM'       => self::SERVICES_NODES . ' AS sn',
                'INNER JOIN' => [
                    self::NODES . ' AS n' => ['ON' => ['sn' => 'plugin_dockerinventory_nodes_id', 'n' => 'id']],
                ],
                'WHERE'      => $where + ['n.computers_id' => ['>', 0]],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Computer', (int) $row['computers_id']);
        }

        return $keys;
    }
}
