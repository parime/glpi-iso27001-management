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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

use GlpiPlugin\Grcmanager\Services\Cve\FirmwareInventory;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcFirmwareInventory;

/**
 * Issue #704 : éléments du parc dont la fin de support est suivie, et leur état.
 *
 * - logiciels installés, sur tous les types de $CFG_GLPI['software_types'] ;
 * - systèmes d'exploitation (Item_OperatingSystem), sur $CFG_GLPI['operatingsystem_types'] ;
 * - firmwares des matériels (Item_DeviceFirmware, voir FirmwareInventory, issue #705).
 *
 * Pour l'affichage, seuls les actifs que la personne peut voir sont lus : types d'actifs qu'elle a
 * le droit de lire, dans ses entités actives (#536), hors corbeille et gabarits, même règle que le
 * rapprochement des CVE (InventoryCveMatcher). La tâche automatique, sans session, ne lit que les
 * noms en service pour savoir quels produits relire.
 *
 * NOTE : dépend du $DB et du $CFG_GLPI de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 *
 * @phpstan-import-type Evaluation from CycleMatcher
 * @phpstan-type Item array{kind: string, itemtype: string, items_id: int, item_name: string,
 *     name: string, version: string}
 * @phpstan-type EvaluatedItem array{kind: string, itemtype: string, items_id: int, item_name: string,
 *     name: string, version: string, origin: string, evaluation: Evaluation}
 */
final class EndOfLifeInventory
{
    /**
     * Éléments suivis des actifs que la personne connectée peut voir.
     *
     * @return list<Item>
     */
    public static function visibleItems(): array
    {
        $items = [];
        foreach (self::softwareRows(true) as $row) {
            $items[] = $row;
        }
        foreach (self::operatingSystemRows(true) as $row) {
            $items[] = $row;
        }
        // Issue #843 : firmware des contrôleurs de gestion des serveurs et BIOS lus par eux.
        $firmwares = [...FirmwareInventory::visibleToCurrentUser(), ...BmcFirmwareInventory::visibleToCurrentUser()];
        foreach ($firmwares as $firmware) {
            $items[] = [
                'kind'      => ProductMatcher::KIND_FIRMWARE,
                'itemtype'  => $firmware['itemtype'],
                'items_id'  => $firmware['items_id'],
                'item_name' => $firmware['item_name'],
                'name'      => $firmware['firmware_name'],
                'version'   => $firmware['version'],
            ];
        }

        return $items;
    }

    /**
     * Noms en service dans tout le parc (tâche automatique), par type d'élément.
     *
     * @return list<array{kind: string, name: string}>
     */
    public static function namesInUse(): array
    {
        $names = [];
        foreach ([...self::softwareRows(false), ...self::operatingSystemRows(false)] as $row) {
            $names[$row['kind'] . "\0" . $row['name']] = ['kind' => $row['kind'], 'name' => $row['name']];
        }
        foreach ([...FirmwareInventory::counts(), ...BmcFirmwareInventory::counts()] as $firmware) {
            $names[ProductMatcher::KIND_FIRMWARE . "\0" . $firmware['software']] = [
                'kind' => ProductMatcher::KIND_FIRMWARE,
                'name' => $firmware['software'],
            ];
        }

        return array_values($names);
    }

    /**
     * Produits à tenir en cache : ceux des noms en service, correspondances manuelles comprises.
     *
     * @return list<string>
     */
    public static function productsInUse(): array
    {
        $manual = EndOfLifeStore::manualMap();
        $products = [];
        foreach (self::namesInUse() as $entry) {
            $product = ProductMatcher::resolve($entry['kind'], $entry['name'], $manual)['product'];
            if ($product !== '') {
                $products[$product] = true;
            }
        }
        $list = array_keys($products);
        sort($list);

        return $list;
    }

    /**
     * État de chaque élément visible.
     *
     * @param string|null $today « 2026-11-18 » (tests) ; date du jour sinon.
     * @return list<EvaluatedItem>
     */
    public static function evaluateVisible(?string $today = null): array
    {
        return array_values(self::evaluate(self::visibleItems(), $today));
    }

    /**
     * État d'éléments donnés (issue #843 : firmwares des contrôleurs de gestion des serveurs),
     * d'après les correspondances et le cache existants ; aucun appel à endoflife.date.
     *
     * @param array<array-key, Item> $items
     * @param string|null $today « 2026-11-18 » (tests) ; date du jour sinon.
     * @return array<array-key, EvaluatedItem> mêmes clés que $items
     */
    public static function evaluate(array $items, ?string $today = null): array
    {
        $today ??= substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
        $manual = EndOfLifeStore::manualMap();
        $cache = EndOfLifeStore::cache();
        $warning = EndOfLifeStore::warningDays();
        $memo = [];
        $result = [];
        foreach ($items as $index => $item) {
            $key = $item['kind'] . "\0" . $item['name'] . "\0" . $item['version'];
            if (!isset($memo[$key])) {
                $resolved = ProductMatcher::resolve($item['kind'], $item['name'], $manual);
                $cached = $resolved['product'] === '' ? null : ($cache[$resolved['product']] ?? null);
                $memo[$key] = [
                    'origin'     => $resolved['origin'],
                    'evaluation' => CycleMatcher::evaluate(
                        $resolved['product'],
                        $item['name'],
                        $item['version'],
                        $cached['cycles'] ?? null,
                        $today,
                        $warning,
                        $cached !== null && $cached['error'] === EndOfLifeException::NOT_FOUND
                    ),
                ];
            }
            $result[$index] = $item + $memo[$key];
        }

        return $result;
    }

    /**
     * @return list<Item>
     */
    private static function softwareRows(bool $visibleOnly): array
    {
        global $DB, $CFG_GLPI;

        $rows = [];
        foreach ($CFG_GLPI['software_types'] ?? [] as $itemtype) {
            $assetCriteria = self::assetCriteria($itemtype, $visibleOnly);
            if ($assetCriteria === null) {
                continue;
            }
            $itemTable = $itemtype::getTable();
            $iterator = $DB->request([
                'SELECT'     => [
                    'glpi_items_softwareversions.items_id',
                    'glpi_softwares.name AS software_name',
                    'glpi_softwareversions.name AS version',
                    "{$itemTable}.name AS item_name",
                ],
                'FROM'       => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => ['FKEY' => [
                        'glpi_items_softwareversions' => 'softwareversions_id',
                        'glpi_softwareversions'       => 'id',
                    ]],
                    'glpi_softwares' => ['FKEY' => [
                        'glpi_softwareversions' => 'softwares_id',
                        'glpi_softwares'        => 'id',
                    ]],
                    $itemTable => ['FKEY' => [
                        'glpi_items_softwareversions' => 'items_id',
                        $itemTable                    => 'id',
                    ]],
                ],
                'WHERE'      => [
                    'glpi_items_softwareversions.itemtype'   => $itemtype,
                    'glpi_items_softwareversions.is_deleted' => 0,
                    'glpi_softwares.is_deleted'              => 0,
                ] + $assetCriteria,
            ]);
            foreach ($iterator as $row) {
                $rows[] = [
                    'kind'      => ProductMatcher::KIND_SOFTWARE,
                    'itemtype'  => $itemtype,
                    'items_id'  => (int) $row['items_id'],
                    'item_name' => (string) ($row['item_name'] ?? ''),
                    'name'      => (string) $row['software_name'],
                    'version'   => (string) ($row['version'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<Item>
     */
    private static function operatingSystemRows(bool $visibleOnly): array
    {
        global $DB, $CFG_GLPI;

        $link = 'glpi_items_operatingsystems';
        $rows = [];
        foreach ($CFG_GLPI['operatingsystem_types'] ?? [] as $itemtype) {
            $assetCriteria = self::assetCriteria($itemtype, $visibleOnly);
            if ($assetCriteria === null) {
                continue;
            }
            $itemTable = $itemtype::getTable();
            $iterator = $DB->request([
                'SELECT'     => [
                    "{$link}.items_id",
                    'glpi_operatingsystems.name AS os_name',
                    'glpi_operatingsystemversions.name AS version',
                    "{$itemTable}.name AS item_name",
                ],
                'FROM'       => $link,
                'INNER JOIN' => [
                    'glpi_operatingsystems' => ['FKEY' => [
                        $link                   => 'operatingsystems_id',
                        'glpi_operatingsystems' => 'id',
                    ]],
                    $itemTable => ['FKEY' => [$link => 'items_id', $itemTable => 'id']],
                ],
                'LEFT JOIN'  => [
                    'glpi_operatingsystemversions' => ['FKEY' => [
                        $link                          => 'operatingsystemversions_id',
                        'glpi_operatingsystemversions' => 'id',
                    ]],
                ],
                'WHERE'      => [
                    "{$link}.itemtype"   => $itemtype,
                    "{$link}.is_deleted" => 0,
                ] + $assetCriteria,
            ]);
            foreach ($iterator as $row) {
                $rows[] = [
                    'kind'      => ProductMatcher::KIND_OS,
                    'itemtype'  => $itemtype,
                    'items_id'  => (int) $row['items_id'],
                    'item_name' => (string) ($row['item_name'] ?? ''),
                    'name'      => (string) $row['os_name'],
                    'version'   => (string) ($row['version'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * Critères sur la table de l'actif (ni corbeille ni gabarit ; entités actives et droit de
     * lecture pour l'affichage), null si le type n'est pas lisible.
     *
     * @return array<mixed>|null
     */
    private static function assetCriteria(mixed $itemtype, bool $visibleOnly): ?array
    {
        global $DB;

        if (!is_string($itemtype) || !is_a($itemtype, \CommonDBTM::class, true)) {
            return null;
        }
        if ($visibleOnly && !$itemtype::canView()) {
            return null;
        }
        $itemTable = $itemtype::getTable();
        if (!$DB->tableExists($itemTable) || !$DB->fieldExists($itemTable, 'name')) {
            return null;
        }
        $criteria = [];
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($itemTable, $flag)) {
                $criteria["{$itemTable}.{$flag}"] = 0;
            }
        }
        if ($visibleOnly && $DB->fieldExists($itemTable, 'entities_id')) {
            $recursive = $DB->fieldExists($itemTable, 'is_recursive');
            $criteria += getEntitiesRestrictCriteria($itemTable, '', '', $recursive);
        }

        return $criteria;
    }
}
