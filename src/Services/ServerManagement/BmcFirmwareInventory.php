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

namespace GlpiPlugin\Grcmanager\Services\ServerManagement;

use Computer;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables as Tables;
use GlpiPlugin\Grcmanager\ServerManagement;

/**
 * Issue #843, lot 2 : firmwares des contrôleurs de gestion des serveurs (firmware du contrôleur,
 * BIOS du serveur), source supplémentaire des vulnérabilités du parc (ParkVulnerabilitySync, #780)
 * et des fins de support (EndOfLifeInventory, #782), au même format que FirmwareInventory : ces
 * services restent les seuls à interroger la NVD et endoflife.date.
 *
 * Le serveur porteur est celui choisi sur le contrôleur, sinon l'ordinateur au même numéro de
 * série (unique, ni supprimé ni gabarit), comme sur la page des contrôleurs.
 *
 * NOTE : dépend du $DB de GLPI ; la logique (désignation, version) est dans BmcFirmwareRules.
 */
final class BmcFirmwareInventory
{
    /**
     * Firmwares de chaque contrôleur retenu.
     *
     * @param array<mixed> $where restriction sur la table des contrôleurs
     * @return list<array{controller_id: int, controller_name: string, entities_id: int, serial: string,
     *     computers_id: int, part: string, name: string, version: string}>
     */
    public static function links(array $where = []): array
    {
        global $DB;

        $links = [];
        foreach ($DB->request(['FROM' => Tables::CONTROLLERS, 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            foreach (BmcFirmwareRules::items($row) as $item) {
                $links[] = [
                    'controller_id'   => (int) $row['id'],
                    'controller_name' => (string) $row['name'],
                    'entities_id'     => (int) $row['entities_id'],
                    'serial'          => (string) $row['serial'],
                    'computers_id'    => (int) $row['computers_id'],
                ] + $item;
            }
        }

        return $links;
    }

    /**
     * Nombre de contrôleurs par firmware et par version, comme FirmwareInventory::counts().
     *
     * @param list<string> $names Désignations retenues ; vide : toutes.
     * @param array<mixed> $entityCriteria Restriction sur la table des contrôleurs.
     * @return list<array{software: string, version: string, installs: int, source: string}>
     */
    public static function counts(array $names = [], array $entityCriteria = []): array
    {
        $counts = [];
        foreach (self::links($entityCriteria) as $link) {
            if ($names !== [] && !in_array($link['name'], $names, true)) {
                continue;
            }
            $key = $link['name'] . "\0" . $link['version'];
            $counts[$key] ??= [
                'software' => $link['name'],
                'version'  => $link['version'],
                'installs' => 0,
                'source'   => 'firmware',
            ];
            $counts[$key]['installs']++;
        }

        return array_values($counts);
    }

    /**
     * Serveurs (ordinateurs) dont le contrôleur porte l'un de ces firmwares dans l'une de ces
     * versions ; un contrôleur sans serveur connu n'est pas un actif à lier à un ticket.
     *
     * @param list<string> $names
     * @param list<string> $versions
     * @param array<mixed> $entityCriteria
     * @return list<array{itemtype: string, items_id: int, entities_id: int}>
     */
    public static function machines(array $names, array $versions, array $entityCriteria = []): array
    {
        if ($names === [] || $versions === []) {
            return [];
        }
        $links = array_values(array_filter(
            self::links($entityCriteria),
            static fn (array $l): bool => in_array($l['name'], $names, true)
                && in_array($l['version'], $versions, true)
        ));
        $servers = self::servers($links);
        $machines = [];
        foreach ($links as $link) {
            $server = $servers[$link['controller_id']] ?? null;
            if ($server !== null) {
                $machines[$server['id']] = [
                    'itemtype'    => Computer::class,
                    'items_id'    => $server['id'],
                    'entities_id' => $server['entities_id'],
                ];
            }
        }

        return array_values($machines);
    }

    /**
     * Firmwares des contrôleurs des entités de la session (fins de support), rattachés au serveur
     * quand il est connu, sinon au contrôleur lui-même.
     *
     * @return list<array{itemtype: string, items_id: int, item_name: string, firmware_name: string,
     *     version: string}>
     */
    public static function visibleToCurrentUser(): array
    {
        if (!ServerManagement::canView()) {
            return [];
        }
        $links = self::links(getEntitiesRestrictCriteria(Tables::CONTROLLERS, '', '', true));
        $servers = self::servers($links);
        $items = [];
        foreach ($links as $link) {
            $server = $servers[$link['controller_id']] ?? null;
            $items[] = [
                'itemtype'      => $server !== null ? Computer::class : ServerManagement::class,
                'items_id'      => $server !== null ? $server['id'] : $link['controller_id'],
                'item_name'     => $server !== null ? $server['name'] : $link['controller_name'],
                'firmware_name' => $link['name'],
                'version'       => $link['version'],
            ];
        }

        return $items;
    }

    /**
     * Serveur de chaque contrôleur : choisi à la main, sinon numéro de série unique.
     *
     * @param list<array{controller_id: int, serial: string, computers_id: int}> $links
     * @return array<int, array{id: int, name: string, entities_id: int}>
     */
    public static function servers(array $links): array
    {
        global $DB;

        $ids = [];
        $serials = [];
        foreach ($links as $link) {
            if ($link['computers_id'] > 0) {
                $ids[$link['computers_id']] = true;
                continue;
            }
            $key = ServerManagement::serialKey($link['serial']);
            if ($key !== null) {
                $serials[trim($link['serial'])] = true;
            }
        }
        if ($ids === [] && $serials === []) {
            return [];
        }
        $or = [];
        if ($ids !== []) {
            $or[] = ['id' => array_keys($ids)];
        }
        if ($serials !== []) {
            $or[] = ['serial' => array_map('strval', array_keys($serials))];
        }
        $byId = [];
        $bySerial = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'serial', 'entities_id'],
                'FROM'   => Computer::getTable(),
                'WHERE'  => ['is_deleted' => 0, 'is_template' => 0, 'OR' => $or],
            ]) as $row
        ) {
            $computer = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'] !== '' ? (string) $row['name'] : '#' . (int) $row['id'],
                'entities_id' => (int) $row['entities_id'],
            ];
            $byId[$computer['id']] = $computer;
            $key = ServerManagement::serialKey((string) $row['serial']);
            if ($key !== null) {
                // Numéro de série porté par deux ordinateurs : aucun rapprochement.
                $bySerial[$key] = array_key_exists($key, $bySerial) ? null : $computer;
            }
        }
        $servers = [];
        foreach ($links as $link) {
            if ($link['computers_id'] > 0) {
                $server = $byId[$link['computers_id']] ?? null;
            } else {
                $server = $bySerial[ServerManagement::serialKey($link['serial']) ?? ''] ?? null;
            }
            if ($server !== null) {
                $servers[$link['controller_id']] = $server;
            }
        }

        return $servers;
    }
}
