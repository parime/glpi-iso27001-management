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

use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\EdgeSource;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;

/**
 * Issue #1082, lot 3 : couche du réseau logique, lue dans les objets natifs de GLPI, sans rien ajouter.
 *
 * - VLAN (glpi_networkports_vlans) : les ports d'un élément qui sont dans le VLAN ;
 * - réseaux IP (glpi_ipaddresses_ipnetworks) : les adresses IP d'un élément (mainitemtype,
 *   mainitems_id) rattachées au réseau par GLPI ;
 * - réseaux GLPI (champ « Réseau » des ordinateurs, matériels réseau, imprimantes ; relation « Réseau »
 *   d'un élément d'une application, glpi_appliances_items_relations).
 *
 * Sens : un matériel réseau (couche du dessous) porte le VLAN ou le réseau ; un serveur, un poste, une
 * imprimante ou une application (couches du dessus) en dépend. La panne d'un switch touche donc ses
 * VLAN, puis les machines de ces VLAN. Les listes sont bornées comme le parcours.
 */
final class LogicalNetworkSource implements EdgeSource
{
    /** Types d'éléments qui portent le champ « Réseau » (networks_id) dans GLPI. */
    private const NETWORK_FIELD_ITEMTYPES = ['Computer', 'NetworkEquipment', 'Printer', 'Unmanaged'];

    public static function supporters(string $itemtype, int $itemsId): array
    {
        if (in_array($itemtype, Layers::LOGICAL_ITEMTYPES, true)) {
            return self::members($itemtype, $itemsId, -1);
        }
        if (Layers::rankOf($itemtype) > Layers::rank(Layers::LOGICAL_NETWORK)) {
            return self::logicalOf($itemtype, $itemsId);
        }

        return [];
    }

    public static function dependents(string $itemtype, int $itemsId): array
    {
        if (in_array($itemtype, Layers::LOGICAL_ITEMTYPES, true)) {
            return self::members($itemtype, $itemsId, 1);
        }
        if (Layers::rankOf($itemtype) < Layers::rank(Layers::LOGICAL_NETWORK)) {
            return self::logicalOf($itemtype, $itemsId);
        }

        return [];
    }

    /**
     * VLAN, réseaux IP et réseaux GLPI d'un élément.
     *
     * @return list<string>
     */
    public static function logicalOf(string $itemtype, int $itemsId): array
    {
        $keys = array_merge(
            self::vlansOf($itemtype, $itemsId),
            self::ipNetworksOf($itemtype, $itemsId),
            self::networksOf($itemtype, $itemsId)
        );
        if ($itemtype === 'Appliance') {
            $keys = array_merge($keys, self::applianceNetworks($itemsId));
        }

        return array_values(array_unique($keys));
    }

    /**
     * Éléments d'un objet du réseau logique, du côté voulu.
     *
     * @param int $side -1 : ceux qui le portent (matériels réseau) ; 1 : ceux qui en dépendent
     * @return list<string>
     */
    public static function members(string $itemtype, int $itemsId, int $side): array
    {
        $keys = match ($itemtype) {
            'Vlan'      => self::vlanMembers($itemsId),
            'IPNetwork' => self::ipNetworkMembers($itemsId),
            'Network'   => self::networkMembers($itemsId),
            default     => [],
        };
        $logical = Layers::rank(Layers::LOGICAL_NETWORK);
        $out = [];
        foreach ($keys as $key) {
            [$type] = ImpactGraph::split($key);
            $rank = Layers::rankOf($type);
            if (($side < 0 && $rank < $logical) || ($side > 0 && $rank > $logical)) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function vlansOf(string $itemtype, int $itemsId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['v.vlans_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_networkports_vlans AS v',
                'INNER JOIN' => [
                    'glpi_networkports AS p' => ['ON' => ['v' => 'networkports_id', 'p' => 'id']],
                ],
                'WHERE'      => ['p.itemtype' => $itemtype, 'p.items_id' => $itemsId, 'p.is_deleted' => 0],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Vlan', (int) $row['vlans_id']);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function vlanMembers(int $vlanId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['p.itemtype', 'p.items_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_networkports_vlans AS v',
                'INNER JOIN' => [
                    'glpi_networkports AS p' => ['ON' => ['v' => 'networkports_id', 'p' => 'id']],
                ],
                'WHERE'      => ['v.vlans_id' => $vlanId, 'p.is_deleted' => 0],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            if ((string) $row['itemtype'] !== '' && (int) $row['items_id'] > 0) {
                $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function ipNetworksOf(string $itemtype, int $itemsId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['n.ipnetworks_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_ipaddresses_ipnetworks AS n',
                'INNER JOIN' => [
                    'glpi_ipaddresses AS a' => ['ON' => ['n' => 'ipaddresses_id', 'a' => 'id']],
                ],
                'WHERE'      => ['a.mainitemtype' => $itemtype, 'a.mainitems_id' => $itemsId, 'a.is_deleted' => 0],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('IPNetwork', (int) $row['ipnetworks_id']);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function ipNetworkMembers(int $networkId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['a.mainitemtype', 'a.mainitems_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_ipaddresses_ipnetworks AS n',
                'INNER JOIN' => [
                    'glpi_ipaddresses AS a' => ['ON' => ['n' => 'ipaddresses_id', 'a' => 'id']],
                ],
                'WHERE'      => ['n.ipnetworks_id' => $networkId, 'a.is_deleted' => 0, 'a.mainitems_id' => ['>', 0]],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            if ((string) $row['mainitemtype'] !== '') {
                $keys[] = ImpactGraph::key((string) $row['mainitemtype'], (int) $row['mainitems_id']);
            }
        }

        return $keys;
    }

    /**
     * Table d'un type qui porte le champ « Réseau », ou null.
     */
    private static function networkTable(string $itemtype): ?string
    {
        global $DB;

        if (!in_array($itemtype, self::NETWORK_FIELD_ITEMTYPES, true)) {
            return null;
        }
        $table = getTableForItemType($itemtype);

        return $DB->tableExists($table) && $DB->fieldExists($table, 'networks_id') ? $table : null;
    }

    /**
     * @return list<string>
     */
    private static function networksOf(string $itemtype, int $itemsId): array
    {
        global $DB;

        $table = self::networkTable($itemtype);
        if ($table === null) {
            return [];
        }
        $row = $DB->request(['SELECT' => ['networks_id'], 'FROM' => $table, 'WHERE' => ['id' => $itemsId]])->current();
        $networkId = (int) ($row['networks_id'] ?? 0);

        return $networkId > 0 ? [ImpactGraph::key('Network', $networkId)] : [];
    }

    /**
     * @return list<string>
     */
    private static function networkMembers(int $networkId): array
    {
        global $DB;

        $keys = [];
        foreach (self::NETWORK_FIELD_ITEMTYPES as $itemtype) {
            $table = self::networkTable($itemtype);
            if ($table === null) {
                continue;
            }
            $where = ['networks_id' => $networkId];
            foreach (['is_deleted', 'is_template'] as $flag) {
                if ($DB->fieldExists($table, $flag)) {
                    $where[$flag] = 0;
                }
            }
            foreach (
                $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $table,
                    'WHERE'  => $where,
                    'LIMIT'  => DependencyWalker::MAX_NODES,
                ]) as $row
            ) {
                $keys[] = ImpactGraph::key($itemtype, (int) $row['id']);
            }
        }

        // Applications dont un élément est déclaré sur ce réseau (relation « Réseau » de l'application).
        foreach (
            $DB->request([
                'SELECT'     => ['i.appliances_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_appliances_items_relations AS r',
                'INNER JOIN' => [
                    'glpi_appliances_items AS i' => ['ON' => ['r' => 'appliances_items_id', 'i' => 'id']],
                ],
                'WHERE'      => ['r.itemtype' => 'Network', 'r.items_id' => $networkId],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Appliance', (int) $row['appliances_id']);
        }

        return $keys;
    }

    /**
     * Réseaux GLPI déclarés sur les éléments d'une application (onglet « Éléments » de l'application).
     *
     * @return list<string>
     */
    private static function applianceNetworks(int $applianceId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['r.items_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_appliances_items_relations AS r',
                'INNER JOIN' => [
                    'glpi_appliances_items AS i' => ['ON' => ['r' => 'appliances_items_id', 'i' => 'id']],
                ],
                'WHERE'      => ['i.appliances_id' => $applianceId, 'r.itemtype' => 'Network'],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Network', (int) $row['items_id']);
        }

        return $keys;
    }
}
