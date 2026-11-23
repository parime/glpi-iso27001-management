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
 * Issue #1082 : liens connus de l'inventaire GLPI, sans rien ajouter.
 *
 * - connexions réseau entre ports (glpi_networkports_networkports) : l'élément de la couche la plus
 *   basse porte l'autre (le switch porte le serveur) ; deux switchs reliés ne propagent pas l'impact ;
 * - machines virtuelles (glpi_itemvirtualmachines, glpi_computervirtualmachines sous un ancien
 *   schéma) : l'hôte porte l'ordinateur qui a le même UUID ;
 * - logiciels installés (glpi_items_softwareversions), applications (glpi_appliances_items) et
 *   instances de bases de données (glpi_databaseinstances) : la machine les porte.
 */
final class InventorySource implements EdgeSource
{
    public static function supporters(string $itemtype, int $itemsId): array
    {
        $keys = self::networkPeers($itemtype, $itemsId, 1);
        if ($itemtype === 'Computer') {
            $keys = array_merge($keys, self::virtualMachineHosts($itemsId));
        }
        if ($itemtype === 'Software') {
            $keys = array_merge($keys, self::softwareHosts($itemsId));
        }
        if ($itemtype === 'Appliance') {
            $keys = array_merge($keys, self::rows('glpi_appliances_items', ['appliances_id' => $itemsId]));
        }
        if ($itemtype === 'DatabaseInstance') {
            $keys = array_merge($keys, self::rows('glpi_databaseinstances', ['id' => $itemsId]));
        }

        return $keys;
    }

    public static function dependents(string $itemtype, int $itemsId): array
    {
        $keys = self::networkPeers($itemtype, $itemsId, -1);
        if ($itemtype === 'Computer') {
            $keys = array_merge($keys, self::virtualMachines($itemsId));
        }
        if (Layers::of($itemtype) < Layers::APPLICATION) {
            $keys = array_merge(
                $keys,
                self::installedSoftware($itemtype, $itemsId),
                self::linked('glpi_appliances_items', 'appliances_id', 'Appliance', $itemtype, $itemsId),
                self::databaseInstances($itemtype, $itemsId)
            );
        }

        return $keys;
    }

    /**
     * Éléments reliés par un câble, dans le sens voulu.
     *
     * @param int $wanted 1 : ceux qui portent l'élément ; -1 : ceux qu'il porte
     * @return list<string>
     */
    private static function networkPeers(string $itemtype, int $itemsId, int $wanted): array
    {
        global $DB;

        $own = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_networkports',
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemsId, 'is_deleted' => 0],
            ]) as $row
        ) {
            $own[] = (int) $row['id'];
        }
        if ($own === []) {
            return [];
        }
        $peers = [];
        foreach (
            $DB->request([
                'SELECT' => ['networkports_id_1', 'networkports_id_2'],
                'FROM'   => 'glpi_networkports_networkports',
                'WHERE'  => ['OR' => ['networkports_id_1' => $own, 'networkports_id_2' => $own]],
            ]) as $row
        ) {
            $first = (int) $row['networkports_id_1'];
            $peers[] = in_array($first, $own, true) ? (int) $row['networkports_id_2'] : $first;
        }
        if ($peers === []) {
            return [];
        }
        $keys = [];
        foreach (
            $DB->request([
                'SELECT' => ['itemtype', 'items_id'],
                'FROM'   => 'glpi_networkports',
                'WHERE'  => ['id' => array_values(array_unique($peers)), 'is_deleted' => 0],
            ]) as $row
        ) {
            // -1 : le voisin porte l'élément ; 1 : l'élément porte le voisin.
            if (Layers::direction((string) $row['itemtype'], $itemtype) === -$wanted) {
                $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
            }
        }

        return $keys;
    }

    /**
     * @return array{table: string, itemtype: string, id: string}|null
     */
    private static function virtualMachineTable(): ?array
    {
        global $DB;

        if ($DB->tableExists('glpi_itemvirtualmachines')) {
            return ['table' => 'glpi_itemvirtualmachines', 'itemtype' => 'itemtype', 'id' => 'items_id'];
        }
        if ($DB->tableExists('glpi_computervirtualmachines')) {
            return ['table' => 'glpi_computervirtualmachines', 'itemtype' => '', 'id' => 'computers_id'];
        }

        return null;
    }

    /**
     * Ordinateurs dont l'UUID est celui d'une machine virtuelle de l'hôte.
     *
     * @return list<string>
     */
    private static function virtualMachines(int $hostId): array
    {
        global $DB;

        $vm = self::virtualMachineTable();
        if ($vm === null) {
            return [];
        }
        $where = [$vm['id'] => $hostId, 'is_deleted' => 0, 'NOT' => ['uuid' => '']];
        if ($vm['itemtype'] !== '') {
            $where[$vm['itemtype']] = 'Computer';
        }
        $uuids = [];
        foreach ($DB->request(['SELECT' => ['uuid'], 'FROM' => $vm['table'], 'WHERE' => $where]) as $row) {
            $uuids[] = (string) $row['uuid'];
        }
        if ($uuids === []) {
            return [];
        }
        $keys = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['uuid' => $uuids, 'is_deleted' => 0, 'NOT' => ['id' => $hostId]],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Computer', (int) $row['id']);
        }

        return $keys;
    }

    /**
     * Hôtes de la machine virtuelle qu'est cet ordinateur (même UUID).
     *
     * @return list<string>
     */
    private static function virtualMachineHosts(int $computerId): array
    {
        global $DB;

        $vm = self::virtualMachineTable();
        $computer = $DB->request([
            'SELECT' => ['uuid'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => ['id' => $computerId],
        ])->current();
        $uuid = (string) ($computer['uuid'] ?? '');
        if ($vm === null || $uuid === '') {
            return [];
        }
        $keys = [];
        foreach ($DB->request(['FROM' => $vm['table'], 'WHERE' => ['uuid' => $uuid, 'is_deleted' => 0]]) as $row) {
            $hostType = $vm['itemtype'] === '' ? 'Computer' : (string) $row[$vm['itemtype']];
            $hostId = (int) $row[$vm['id']];
            if ($hostId > 0 && !($hostType === 'Computer' && $hostId === $computerId)) {
                $keys[] = ImpactGraph::key($hostType, $hostId);
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function installedSoftware(string $itemtype, int $itemsId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['v.softwares_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_items_softwareversions AS i',
                'INNER JOIN' => [
                    'glpi_softwareversions AS v' => ['ON' => ['i' => 'softwareversions_id', 'v' => 'id']],
                ],
                'WHERE'      => ['i.itemtype' => $itemtype, 'i.items_id' => $itemsId, 'i.is_deleted' => 0],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('Software', (int) $row['softwares_id']);
        }

        return $keys;
    }

    /**
     * Machines où le logiciel est installé (bornées : un logiciel courant est sur tout le parc).
     *
     * @return list<string>
     */
    private static function softwareHosts(int $softwareId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT'     => ['i.itemtype', 'i.items_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_items_softwareversions AS i',
                'INNER JOIN' => [
                    'glpi_softwareversions AS v' => ['ON' => ['i' => 'softwareversions_id', 'v' => 'id']],
                ],
                'WHERE'      => ['v.softwares_id' => $softwareId, 'i.is_deleted' => 0],
                'LIMIT'      => DependencyWalker::MAX_NODES,
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function databaseInstances(string $itemtype, int $itemsId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_databaseinstances',
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemsId, 'is_deleted' => 0],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key('DatabaseInstance', (int) $row['id']);
        }

        return $keys;
    }

    /**
     * Éléments (itemtype, items_id) des lignes d'une table de liaison.
     *
     * @param array<string, mixed> $where
     * @return list<string>
     */
    private static function rows(string $table, array $where): array
    {
        global $DB;

        $keys = [];
        foreach ($DB->request(['SELECT' => ['itemtype', 'items_id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
            if ((string) $row['itemtype'] !== '' && (int) $row['items_id'] > 0) {
                $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function linked(string $table, string $field, string $type, string $itemtype, int $itemsId): array
    {
        global $DB;

        $keys = [];
        foreach (
            $DB->request([
                'SELECT' => [$field],
                'FROM'   => $table,
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemsId],
            ]) as $row
        ) {
            $keys[] = ImpactGraph::key($type, (int) $row[$field]);
        }

        return $keys;
    }
}
