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

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Issue #705 : firmwares du parc, seconde source du rapprochement des CVE après les logiciels
 * installés. GLPI range la version d'un commutateur, d'un routeur, d'une imprimante ou le BIOS
 * d'un ordinateur dans un composant `Item_DeviceFirmware` :
 * `glpi_items_devicefirmwares` (itemtype, items_id, entities_id, is_deleted) vers
 * `glpi_devicefirmwares` (designation, version, manufacturers_id), schéma vérifié sur GLPI 11.
 *
 * La désignation du firmware joue le rôle du nom du logiciel : elle n'est reliée à un produit du
 * catalogue que par un alias déclaré (ProductAlias), jamais par ressemblance. La version est
 * ramenée à sa forme publiée par FirmwareVersion::normalize().
 *
 * Le lien `glpi_items_devicefirmwares` n'a pas de copie de la corbeille ni du gabarit de l'actif
 * (contrairement à `glpi_items_softwareversions`) : chaque requête joint la table de l'actif,
 * type par type, pour écarter les actifs supprimés et les gabarits.
 *
 * NOTE : dépend du $DB et du $CFG_GLPI de GLPI, hors de l'analyse en PHP pur comme
 * InventoryCveMatcher.php (voir phpstan.neon.dist) ; la logique de version (FirmwareVersion) est
 * testée seule.
 */
final class FirmwareInventory
{
    public const LINK_TABLE = 'glpi_items_devicefirmwares';

    public const FIRMWARE_TABLE = 'glpi_devicefirmwares';

    /** Types d'actifs qui portent des firmwares dans GLPI 11, si la configuration ne les donne pas. */
    private const DEFAULT_ITEMTYPES = ['Computer', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'];

    /**
     * @return list<class-string<\CommonDBTM>>
     */
    public static function itemtypes(): array
    {
        global $CFG_GLPI;

        $itemtypes = [];
        foreach ($CFG_GLPI['itemdevicefirmware_types'] ?? self::DEFAULT_ITEMTYPES as $itemtype) {
            if (is_string($itemtype) && is_a($itemtype, \CommonDBTM::class, true)) {
                $itemtypes[] = $itemtype;
            }
        }

        return $itemtypes;
    }

    /**
     * Firmwares des actifs que l'utilisateur courant peut voir : types d'actifs qu'il a le droit de
     * lire, dans ses entités actives, hors corbeille et gabarits (même règle que les logiciels de
     * InventoryCveMatcher).
     *
     * @return list<array{itemtype: string, items_id: int, item_name: string, firmware_name: string, version: string}>
     */
    public static function visibleToCurrentUser(): array
    {
        global $DB;

        $firmwares = [];
        foreach (self::itemtypes() as $itemtype) {
            if (!$itemtype::canView()) {
                continue;
            }
            $itemTable = $itemtype::getTable();
            $assetCriteria = self::assetCriteria($itemtype);
            if ($DB->fieldExists($itemTable, 'entities_id')) {
                $recursive = $DB->fieldExists($itemTable, 'is_recursive');
                $assetCriteria += getEntitiesRestrictCriteria($itemTable, '', '', $recursive);
            }
            foreach (self::query($itemtype, ["{$itemTable}.name AS item_name"], $assetCriteria) as $row) {
                $firmwares[] = [
                    'itemtype'      => $itemtype,
                    'items_id'      => (int) $row['items_id'],
                    'item_name'     => (string) ($row['item_name'] ?? ''),
                    'firmware_name' => (string) $row['firmware_name'],
                    'version'       => FirmwareVersion::normalize((string) ($row['version'] ?? '')),
                ];
            }
        }

        return $firmwares;
    }

    /**
     * Firmwares d'un actif donné (onglet « Vulnérabilités » de l'actif, déjà ouvert par
     * l'utilisateur).
     *
     * @return list<array{firmware_name: string, version: string}>
     */
    public static function forItem(\CommonDBTM $item): array
    {
        if (!in_array($item::class, self::itemtypes(), true)) {
            return [];
        }
        $firmwares = [];
        foreach (self::query($item::class, [], [self::LINK_TABLE . '.items_id' => $item->getID()], false) as $row) {
            $firmwares[] = [
                'firmware_name' => (string) $row['firmware_name'],
                'version'       => FirmwareVersion::normalize((string) ($row['version'] ?? '')),
            ];
        }

        return $firmwares;
    }

    /**
     * Nombre d'actifs par firmware et par version (normalisée), comme
     * ParkVulnerabilitySync::installCounts() pour les logiciels.
     *
     * @param list<string> $names Désignations retenues ; vide : toutes.
     * @param array<string, mixed> $entityCriteria Restriction sur glpi_items_devicefirmwares
     *        (vide : tout le parc, pour la tâche planifiée).
     * @return list<array{software: string, version: string, installs: int, source: string}>
     */
    public static function counts(array $names = [], array $entityCriteria = []): array
    {
        $counts = [];
        foreach (self::links($names, $entityCriteria) as $row) {
            $key = $row['name'] . "\0" . $row['version'];
            $counts[$key] ??= [
                'software' => $row['name'],
                'version'  => $row['version'],
                'installs' => 0,
                'source'   => 'firmware',
            ];
            $counts[$key]['installs']++;
        }

        return array_values($counts);
    }

    /**
     * Actifs qui portent l'un de ces firmwares dans l'une de ces versions (normalisées).
     *
     * @param list<string> $names
     * @param list<string> $versions
     * @param array<string, mixed> $entityCriteria Comme counts().
     * @return list<array{itemtype: string, items_id: int, entities_id: int}>
     */
    public static function machines(array $names, array $versions, array $entityCriteria = []): array
    {
        if ($names === [] || $versions === []) {
            return [];
        }
        $machines = [];
        foreach (self::links($names, $entityCriteria) as $row) {
            if (!in_array($row['version'], $versions, true)) {
                continue;
            }
            $machines[$row['itemtype'] . "\0" . $row['items_id']] = [
                'itemtype'    => $row['itemtype'],
                'items_id'    => $row['items_id'],
                'entities_id' => $row['entities_id'],
            ];
        }

        return array_values($machines);
    }

    /**
     * Un enregistrement par firmware posé sur un actif actif (ni supprimé ni gabarit).
     *
     * @param list<string> $names
     * @param array<string, mixed> $entityCriteria
     * @return list<array{itemtype: string, items_id: int, entities_id: int, name: string, version: string}>
     */
    private static function links(array $names, array $entityCriteria): array
    {
        $links = [];
        foreach (self::itemtypes() as $itemtype) {
            $criteria = self::assetCriteria($itemtype) + $entityCriteria;
            if ($names !== []) {
                $criteria[self::FIRMWARE_TABLE . '.designation'] = $names;
            }
            foreach (self::query($itemtype, [self::LINK_TABLE . '.entities_id'], $criteria) as $row) {
                $links[] = [
                    'itemtype'    => $itemtype,
                    'items_id'    => (int) $row['items_id'],
                    'entities_id' => (int) $row['entities_id'],
                    'name'        => (string) $row['firmware_name'],
                    'version'     => FirmwareVersion::normalize((string) ($row['version'] ?? '')),
                ];
            }
        }

        return $links;
    }

    /**
     * Actif ni à la corbeille ni gabarit.
     *
     * @param class-string<\CommonDBTM> $itemtype
     * @return array<string, int>
     */
    private static function assetCriteria(string $itemtype): array
    {
        global $DB;

        $itemTable = $itemtype::getTable();
        $criteria = [];
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($itemTable, $flag)) {
                $criteria["{$itemTable}.{$flag}"] = 0;
            }
        }

        return $criteria;
    }

    /**
     * @param class-string<\CommonDBTM> $itemtype
     * @param list<string> $extraSelect
     * @param array<string, mixed> $where
     * @param bool $joinAsset Faux pour un actif déjà chargé (forItem()).
     * @return iterable<array<string, mixed>>
     */
    private static function query(string $itemtype, array $extraSelect, array $where, bool $joinAsset = true): iterable
    {
        global $DB;

        $link = self::LINK_TABLE;
        $firmware = self::FIRMWARE_TABLE;
        $join = [
            $firmware => ['FKEY' => [$link => 'devicefirmwares_id', $firmware => 'id']],
        ];
        if ($joinAsset) {
            $itemTable = $itemtype::getTable();
            $join[$itemTable] = ['FKEY' => [$link => 'items_id', $itemTable => 'id']];
        }

        return $DB->request([
            'SELECT'     => array_merge([
                "{$link}.items_id",
                "{$firmware}.designation AS firmware_name",
                "{$firmware}.version",
            ], $extraSelect),
            'FROM'       => $link,
            'INNER JOIN' => $join,
            'WHERE'      => [
                "{$link}.itemtype"   => $itemtype,
                "{$link}.is_deleted" => 0,
            ] + $where,
        ]);
    }
}
