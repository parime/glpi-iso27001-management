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
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;

/**
 * Issue #1082, lot 2 : calque des flux inter-applicatifs, lus dans la matrice des flux de GRC
 * (glpi_plugin_grcmanager_networkflows, issue #144). Seuls les flux actifs dont les deux extrémités
 * sont des actifs GLPI comptent : la source du flux dépend de sa destination (le client du serveur).
 * Les flux décrits en clair (réseau, zone, adresse) restent dans la matrice.
 */
final class FlowSource implements EdgeSource
{
    public const TABLE = 'glpi_plugin_grcmanager_networkflows';

    public static function supporters(string $itemtype, int $itemsId): array
    {
        return self::ends(['source_itemtype' => $itemtype, 'source_items_id' => $itemsId], 'destination');
    }

    public static function dependents(string $itemtype, int $itemsId): array
    {
        return self::ends(['destination_itemtype' => $itemtype, 'destination_items_id' => $itemsId], 'source');
    }

    /**
     * Flux actifs entre les éléments donnés, dans le sens « support → dépendant ».
     *
     * @param list<string> $keys
     * @return list<array{supporter: string, dependent: string, protocol: string, ports: string}>
     */
    public static function between(array $keys): array
    {
        global $DB;

        $set = array_flip($keys);
        $flows = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['is_active' => 1, 'source_items_id' => ['>', 0], 'destination_items_id' => ['>', 0]],
            ]) as $row
        ) {
            $source = ImpactGraph::key((string) $row['source_itemtype'], (int) $row['source_items_id']);
            $destination = ImpactGraph::key((string) $row['destination_itemtype'], (int) $row['destination_items_id']);
            if (isset($set[$source], $set[$destination])) {
                $flows[] = [
                    'supporter' => $destination,
                    'dependent' => $source,
                    'protocol'  => (string) $row['protocol'],
                    'ports'     => (string) $row['ports'],
                ];
            }
        }

        return $flows;
    }

    /**
     * @param array<string, mixed> $where
     * @return list<string>
     */
    private static function ends(array $where, string $side): array
    {
        global $DB;

        $keys = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['is_active' => 1] + $where]) as $row) {
            if ((int) $row[$side . '_items_id'] > 0 && (string) $row[$side . '_itemtype'] !== '') {
                $keys[] = ImpactGraph::key((string) $row[$side . '_itemtype'], (int) $row[$side . '_items_id']);
            }
        }

        return $keys;
    }
}
