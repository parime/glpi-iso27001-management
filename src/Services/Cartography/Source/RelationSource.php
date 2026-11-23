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

/**
 * Issue #1082 : liens déclarés par les personnes, dans GLPI et dans GRC.
 *
 * - analyse d'impact de GLPI (glpi_impactrelations) : la source porte l'élément impacté ;
 * - dépendances des processus métier du BIA (glpi_plugin_grcmanager_businessprocesses_items) : l'actif
 *   porte le processus ;
 * - liens entre processus (glpi_plugin_grcmanager_businessprocesses_businessprocesses) : le processus
 *   amont porte celui qui en dépend.
 */
final class RelationSource implements EdgeSource
{
    private const PROCESS_ITEMS = 'glpi_plugin_grcmanager_businessprocesses_items';
    private const PROCESS_LINKS = 'glpi_plugin_grcmanager_businessprocesses_businessprocesses';
    private const PROCESS_FIELD = 'plugin_grcmanager_businessprocesses_id';
    private const UPSTREAM_FIELD = 'plugin_grcmanager_businessprocesses_id_upstream';

    public static function supporters(string $itemtype, int $itemsId): array
    {
        $keys = self::impact(
            ['itemtype_impacted', 'items_id_impacted'],
            ['itemtype_source', 'items_id_source'],
            $itemtype,
            $itemsId
        );
        if ($itemtype === Layers::BUSINESS_PROCESS) {
            foreach (self::request(self::PROCESS_ITEMS, [self::PROCESS_FIELD => $itemsId]) as $row) {
                $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
            }
            foreach (self::request(self::PROCESS_LINKS, [self::PROCESS_FIELD => $itemsId]) as $row) {
                $keys[] = ImpactGraph::key(Layers::BUSINESS_PROCESS, (int) $row[self::UPSTREAM_FIELD]);
            }
        }

        return $keys;
    }

    public static function dependents(string $itemtype, int $itemsId): array
    {
        $keys = self::impact(
            ['itemtype_source', 'items_id_source'],
            ['itemtype_impacted', 'items_id_impacted'],
            $itemtype,
            $itemsId
        );
        if ($itemtype === Layers::BUSINESS_PROCESS) {
            foreach (self::request(self::PROCESS_LINKS, [self::UPSTREAM_FIELD => $itemsId]) as $row) {
                $keys[] = ImpactGraph::key(Layers::BUSINESS_PROCESS, (int) $row[self::PROCESS_FIELD]);
            }
        } else {
            foreach (self::request(self::PROCESS_ITEMS, ['itemtype' => $itemtype, 'items_id' => $itemsId]) as $row) {
                $keys[] = ImpactGraph::key(Layers::BUSINESS_PROCESS, (int) $row[self::PROCESS_FIELD]);
            }
        }

        return $keys;
    }

    /**
     * @param array{0: string, 1: string} $from colonnes du type et de l'identifiant de l'élément
     * @param array{0: string, 1: string} $to colonnes de l'autre extrémité
     * @return list<string>
     */
    private static function impact(array $from, array $to, string $itemtype, int $itemsId): array
    {
        $keys = [];
        foreach (self::request('glpi_impactrelations', [$from[0] => $itemtype, $from[1] => $itemsId]) as $row) {
            $keys[] = ImpactGraph::key((string) $row[$to[0]], (int) $row[$to[1]]);
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $where
     * @return iterable<array<string, mixed>>
     */
    private static function request(string $table, array $where): iterable
    {
        global $DB;

        return $DB->request(['FROM' => $table, 'WHERE' => $where]);
    }
}
