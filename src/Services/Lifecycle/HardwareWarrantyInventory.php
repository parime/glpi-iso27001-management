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

use CommonDBTM;

/**
 * Issue #782 : matériels du parc et leur garantie, lus dans les informations financières natives de
 * GLPI (glpi_infocoms), en lecture seule.
 *
 * - Types de matériel : ceux de la liste ci-dessous et les actifs personnalisés de GLPI 11, s'ils
 *   portent des informations financières ($CFG_GLPI['infocom_types']).
 * - Seuls les matériels que la personne peut voir sont lus : types qu'elle a le droit de lire, dans
 *   ses entités actives (#536), hors corbeille et gabarits, comme la fin de support des logiciels
 *   (EndOfLifeInventory). La lecture des informations financières est vérifiée par l'appelant.
 *
 * NOTE : dépend du $DB et du $CFG_GLPI de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 *
 * @phpstan-type Asset array{itemtype: string, items_id: int, item_name: string, entities_id: int,
 *     model: string, manufacturer: string, has_infocom: bool, warranty_date: string|null,
 *     warranty_duration: int}
 */
final class HardwareWarrantyInventory
{
    /** Types de matériel natifs suivis (s'ils portent des informations financières). */
    public const HARDWARE_TYPES = [
        'Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer',
        'Rack', 'Enclosure', 'PDU', 'PassiveDCEquipment',
    ];

    /**
     * Types de matériel suivis, lisibles ou non.
     *
     * @return list<class-string<CommonDBTM>>
     */
    public static function hardwareTypes(): array
    {
        global $CFG_GLPI;

        $types = [];
        foreach ($CFG_GLPI['infocom_types'] ?? [] as $itemtype) {
            if (!is_string($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }
            if (in_array($itemtype, self::HARDWARE_TYPES, true) || is_a($itemtype, 'Glpi\\Asset\\Asset', true)) {
                $types[] = $itemtype;
            }
        }

        return $types;
    }

    /**
     * Matériels visibles par la personne connectée, avec leurs informations de garantie.
     *
     * @return list<Asset>
     */
    public static function visibleAssets(): array
    {
        $assets = [];
        foreach (self::hardwareTypes() as $itemtype) {
            if (!$itemtype::canView()) {
                continue;
            }
            foreach (self::rows($itemtype) as $row) {
                $assets[] = $row;
            }
        }

        return $assets;
    }

    /**
     * @param class-string<CommonDBTM> $itemtype
     * @return list<Asset>
     */
    private static function rows(string $itemtype): array
    {
        global $DB;

        $table = $itemtype::getTable();
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'entities_id')) {
            return [];
        }
        $infocom = 'glpi_infocoms';
        $criteria = [
            'SELECT'    => [
                "{$table}.id AS items_id",
                "{$table}.entities_id",
                "{$infocom}.id AS infocoms_id",
                "{$infocom}.warranty_date",
                "{$infocom}.warranty_duration",
            ],
            'FROM'      => $table,
            'LEFT JOIN' => [
                $infocom => ['ON' => [
                    $table   => 'id',
                    $infocom => 'items_id',
                    ['AND' => ["{$infocom}.itemtype" => $itemtype]],
                ]],
            ],
            'WHERE'     => getEntitiesRestrictCriteria(
                $table,
                '',
                '',
                $DB->fieldExists($table, 'is_recursive')
            ),
            'ORDER'     => ["{$table}.id"],
        ];
        if ($DB->fieldExists($table, 'name')) {
            $criteria['SELECT'][] = "{$table}.name AS item_name";
        }
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($table, $flag)) {
                $criteria['WHERE']["{$table}.{$flag}"] = 0;
            }
        }
        if (is_a($itemtype, 'Glpi\\Asset\\Asset', true)) {
            // Actifs personnalisés : une seule table pour toutes les définitions.
            $criteria['WHERE'] += self::definitionCriteria($itemtype, $table);
        }
        $item = new $itemtype();
        $model = $item->getModelClass();
        if ($model !== null && $DB->fieldExists($table, $model::getForeignKeyField())) {
            $modelTable = $model::getTable();
            $criteria['SELECT'][] = "{$modelTable}.name AS model_name";
            $criteria['LEFT JOIN'][$modelTable] = ['FKEY' => [
                $table      => $model::getForeignKeyField(),
                $modelTable => 'id',
            ]];
        }
        if ($DB->fieldExists($table, 'manufacturers_id')) {
            $criteria['SELECT'][] = 'glpi_manufacturers.name AS manufacturer_name';
            $criteria['LEFT JOIN']['glpi_manufacturers'] = ['FKEY' => [
                $table               => 'manufacturers_id',
                'glpi_manufacturers' => 'id',
            ]];
        }

        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            $hasInfocom = (int) ($row['infocoms_id'] ?? 0) > 0;
            $rows[] = [
                'itemtype'          => $itemtype,
                'items_id'          => (int) $row['items_id'],
                'item_name'         => (string) ($row['item_name'] ?? ''),
                'entities_id'       => (int) $row['entities_id'],
                'model'             => (string) ($row['model_name'] ?? ''),
                'manufacturer'      => (string) ($row['manufacturer_name'] ?? ''),
                'has_infocom'       => $hasInfocom,
                'warranty_date'     => $hasInfocom && $row['warranty_date'] !== null
                    ? (string) $row['warranty_date'] : null,
                'warranty_duration' => $hasInfocom ? (int) $row['warranty_duration'] : 0,
            ];
        }

        return $rows;
    }

    /**
     * Critère de définition d'un actif personnalisé (GLPI 11) : sa table est commune à toutes les
     * définitions.
     *
     * @return array<string, int>
     */
    private static function definitionCriteria(string $itemtype, string $table): array
    {
        if (!method_exists($itemtype, 'getDefinition')) {
            return [];
        }
        $definition = $itemtype::getDefinition();

        return ["{$table}.assets_assetdefinitions_id" => (int) $definition->getID()];
    }
}
