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
 * Issue #780 : systèmes d'exploitation du parc, troisième source du rapprochement des CVE après
 * les logiciels installés et les firmwares (#705). Schéma vérifié sur GLPI 11 :
 * `glpi_items_operatingsystems` (itemtype, items_id, entities_id, is_recursive, is_deleted,
 * operatingsystems_id, operatingsystemversions_id, operatingsystemeditions_id,
 * operatingsystemkernelversions_id) vers `glpi_operatingsystems`, `glpi_operatingsystemversions`
 * et `glpi_operatingsystemkernelversions` (le nom de chacun dans `name`).
 *
 * Chaque système est ramené à une désignation (rapprochée par alias déclaré, ProductAlias) et à
 * une version comparable aux CVE (OperatingSystemVersion). Comme pour les firmwares, le lien n'a
 * pas de copie de la corbeille ni du gabarit de l'actif : la table de l'actif est jointe, type par
 * type.
 *
 * NOTE : dépend du $DB et du $CFG_GLPI de GLPI, hors de l'analyse en PHP pur comme
 * FirmwareInventory.php (voir phpstan.neon.dist) ; la logique de version
 * (OperatingSystemVersion) est testée seule.
 */
final class OperatingSystemInventory
{
    public const LINK_TABLE = 'glpi_items_operatingsystems';

    /** Types d'actifs qui portent un système dans GLPI 11, si la configuration ne les donne pas. */
    private const DEFAULT_ITEMTYPES = ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'];

    /**
     * @return list<class-string<\CommonDBTM>>
     */
    public static function itemtypes(): array
    {
        global $CFG_GLPI, $DB;

        $itemtypes = [];
        foreach ($CFG_GLPI['operatingsystem_types'] ?? self::DEFAULT_ITEMTYPES as $itemtype) {
            if (
                is_string($itemtype)
                && is_a($itemtype, \CommonDBTM::class, true)
                && $DB->tableExists($itemtype::getTable())
                && $DB->fieldExists($itemtype::getTable(), 'name')
            ) {
                $itemtypes[] = $itemtype;
            }
        }

        return $itemtypes;
    }

    /**
     * Systèmes des actifs que l'utilisateur courant peut voir : types d'actifs qu'il a le droit de
     * lire, dans ses entités actives, hors corbeille et gabarits (même règle que les logiciels et
     * les firmwares de InventoryCveMatcher).
     *
     * @return list<array{itemtype: string, items_id: int, item_name: string, os_name: string, version: string}>
     */
    public static function visibleToCurrentUser(): array
    {
        global $DB;

        $systems = [];
        foreach (self::itemtypes() as $itemtype) {
            if (!$itemtype::canView()) {
                continue;
            }
            $itemTable = $itemtype::getTable();
            $criteria = self::assetCriteria($itemtype);
            if ($DB->fieldExists($itemTable, 'entities_id')) {
                $recursive = $DB->fieldExists($itemTable, 'is_recursive');
                $criteria += getEntitiesRestrictCriteria($itemTable, '', '', $recursive);
            }
            foreach (self::query($itemtype, ["{$itemTable}.name AS item_name"], $criteria) as $row) {
                $systems[] = [
                    'itemtype'  => $itemtype,
                    'items_id'  => (int) $row['items_id'],
                    'item_name' => (string) ($row['item_name'] ?? ''),
                ] + self::identity($row);
            }
        }

        return $systems;
    }

    /**
     * Système(s) d'un actif donné (onglet « Vulnérabilités » de l'actif, déjà ouvert).
     *
     * @return list<array{os_name: string, version: string}>
     */
    public static function forItem(\CommonDBTM $item): array
    {
        if (!in_array($item::class, self::itemtypes(), true)) {
            return [];
        }
        $systems = [];
        foreach (self::query($item::class, [], [self::LINK_TABLE . '.items_id' => $item->getID()], false) as $row) {
            $systems[] = self::identity($row);
        }

        return $systems;
    }

    /**
     * Issue #854 : système de chaque ordinateur donné (déjà filtrés par l'appelant : entités, droits),
     * avec le nom, la version et le noyau tels qu'inventoriés, et la désignation et la version
     * comparées aux CVE. Un seul système par ordinateur (le premier inventorié).
     *
     * @param list<int> $computerIds
     * @return array<int, array{name: string, os_version: string, kernel: string, os_name: string,
     *     version: string}>
     */
    public static function forComputers(array $computerIds): array
    {
        if ($computerIds === []) {
            return [];
        }
        $systems = [];
        foreach (self::query(\Computer::class, [], [self::LINK_TABLE . '.items_id' => $computerIds], false) as $row) {
            $id = (int) $row['items_id'];
            $systems[$id] ??= [
                'name'       => trim((string) ($row['os_name'] ?? '')),
                'os_version' => trim((string) ($row['os_version'] ?? '')),
                'kernel'     => trim((string) ($row['kernel_version'] ?? '')),
            ] + self::identity($row);
        }

        return $systems;
    }

    /**
     * Nombre d'actifs par désignation et par version, comme FirmwareInventory::counts().
     *
     * @param list<string> $names Désignations retenues ; vide : toutes.
     * @param array<string, mixed> $entityCriteria Restriction sur glpi_items_operatingsystems
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
                'source'   => InventoryCveMatcher::SOURCE_OS,
            ];
            $counts[$key]['installs']++;
        }

        return array_values($counts);
    }

    /**
     * Actifs qui portent l'une de ces désignations dans l'une de ces versions.
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
     * Désignation et version comparable d'une ligne de query().
     *
     * @param array<string, mixed> $row
     * @return array{os_name: string, version: string}
     */
    private static function identity(array $row): array
    {
        $name = (string) ($row['os_name'] ?? '');
        $version = (string) ($row['os_version'] ?? '');

        return [
            'os_name' => OperatingSystemVersion::designation($name, $version),
            'version' => OperatingSystemVersion::release($name, $version, (string) ($row['kernel_version'] ?? '')),
        ];
    }

    /**
     * Un enregistrement par système posé sur un actif actif (ni supprimé ni gabarit). La
     * désignation se calcule après lecture (version de Windows comprise) : le filtre sur les noms
     * se fait donc ici, pas dans la requête.
     *
     * @param list<string> $names
     * @param array<string, mixed> $entityCriteria
     * @return list<array{itemtype: string, items_id: int, entities_id: int, name: string, version: string}>
     */
    private static function links(array $names, array $entityCriteria): array
    {
        $wanted = array_fill_keys($names, true);
        $links = [];
        foreach (self::itemtypes() as $itemtype) {
            $criteria = self::assetCriteria($itemtype) + $entityCriteria;
            foreach (self::query($itemtype, [self::LINK_TABLE . '.entities_id'], $criteria) as $row) {
                $identity = self::identity($row);
                if ($wanted !== [] && !isset($wanted[$identity['os_name']])) {
                    continue;
                }
                $links[] = [
                    'itemtype'    => $itemtype,
                    'items_id'    => (int) $row['items_id'],
                    'entities_id' => (int) $row['entities_id'],
                    'name'        => $identity['os_name'],
                    'version'     => $identity['version'],
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
        $join = [
            'glpi_operatingsystems' => ['FKEY' => [$link => 'operatingsystems_id', 'glpi_operatingsystems' => 'id']],
        ];
        if ($joinAsset) {
            $itemTable = $itemtype::getTable();
            $join[$itemTable] = ['FKEY' => [$link => 'items_id', $itemTable => 'id']];
        }

        return $DB->request([
            'SELECT'     => array_merge([
                "{$link}.items_id",
                'glpi_operatingsystems.name AS os_name',
                'glpi_operatingsystemversions.name AS os_version',
                'glpi_operatingsystemkernelversions.name AS kernel_version',
            ], $extraSelect),
            'FROM'       => $link,
            'INNER JOIN' => $join,
            'LEFT JOIN'  => [
                'glpi_operatingsystemversions' => ['FKEY' => [
                    $link                          => 'operatingsystemversions_id',
                    'glpi_operatingsystemversions' => 'id',
                ]],
                'glpi_operatingsystemkernelversions' => ['FKEY' => [
                    $link                                => 'operatingsystemkernelversions_id',
                    'glpi_operatingsystemkernelversions' => 'id',
                ]],
            ],
            'WHERE'      => [
                "{$link}.itemtype"   => $itemtype,
                "{$link}.is_deleted" => 0,
            ] + $where,
        ]);
    }
}
