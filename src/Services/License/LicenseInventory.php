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

namespace GlpiPlugin\Grcmanager\Services\License;

use Config;

/**
 * Issue #779 : lecture, en lecture seule, des objets natifs de GLPI utiles au contrôle des licences
 * (Software, SoftwareVersion, Item_SoftwareVersion, SoftwareLicense, Item_SoftwareLicense,
 * Manufacturer), et réglages des éditeurs surveillés.
 *
 * Seul ce que la personne peut voir est lu :
 * - installations sur les types d'actifs qu'elle a le droit de lire, dans ses entités actives (#536),
 *   hors corbeille et gabarits ;
 * - licences de ses entités actives, et licences récursives de leurs entités parentes (règle native
 *   getEntitiesRestrictCriteria(..., true)), hors corbeille et gabarits.
 * Rien n'est écrit dans les tables de GLPI.
 *
 * NOTE : dépend du $DB et du $CFG_GLPI de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 *
 * @phpstan-import-type Software from LicenseComplianceRules
 * @phpstan-import-type Install from LicenseComplianceRules
 * @phpstan-import-type Licence from LicenseComplianceRules
 * @phpstan-import-type Assignment from LicenseComplianceRules
 */
final class LicenseInventory
{
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const KEY_PUBLISHERS = 'license_watch_publishers';
    public const KEY_IGNORED = 'license_watch_ignored';
    public const KEY_EXPIRING = 'license_expiring_days';

    // ------------------------------------------------------------------ Réglages.

    /**
     * Éditeurs surveillés, logiciels ignorés et horizon d'alerte avant l'échéance des licences ;
     * valeurs par défaut tant que rien n'est enregistré.
     *
     * @return array{publishers: list<string>, ignored: list<string>, expiring_days: int}
     */
    public static function settings(): array
    {
        $values = Config::getConfigurationValues(
            self::CONFIG_CONTEXT,
            [self::KEY_PUBLISHERS, self::KEY_IGNORED, self::KEY_EXPIRING]
        );

        return [
            'publishers' => isset($values[self::KEY_PUBLISHERS])
                ? LicenseComplianceRules::parseList((string) $values[self::KEY_PUBLISHERS])
                : LicenseComplianceRules::DEFAULT_PUBLISHERS,
            'ignored'    => isset($values[self::KEY_IGNORED])
                ? LicenseComplianceRules::parseList((string) $values[self::KEY_IGNORED])
                : LicenseComplianceRules::DEFAULT_IGNORED,
            'expiring_days' => isset($values[self::KEY_EXPIRING]) && is_numeric($values[self::KEY_EXPIRING])
                ? LicenseComplianceRules::boundExpiringDays((int) $values[self::KEY_EXPIRING])
                : LicenseComplianceRules::DEFAULT_EXPIRING_DAYS,
        ];
    }

    /**
     * Enregistre les deux listes (texte saisi, une entrée par ligne), nettoyées, et l'horizon
     * d'alerte avant l'échéance (borné entre 0 et 365 jours).
     */
    public static function save(
        string $publishers,
        string $ignored,
        int $expiringDays = LicenseComplianceRules::DEFAULT_EXPIRING_DAYS
    ): void {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::KEY_PUBLISHERS => implode("\n", LicenseComplianceRules::parseList($publishers)),
            self::KEY_IGNORED    => implode("\n", LicenseComplianceRules::parseList($ignored)),
            self::KEY_EXPIRING   => (string) LicenseComplianceRules::boundExpiringDays($expiringDays),
        ]);
    }

    /**
     * Revient aux listes par défaut (tests, bouton « Listes par défaut »).
     */
    public static function reset(): void
    {
        Config::deleteConfigurationValues(
            self::CONFIG_CONTEXT,
            [self::KEY_PUBLISHERS, self::KEY_IGNORED, self::KEY_EXPIRING]
        );
    }

    // ------------------------------------------------------------------ Inventaire visible.

    /**
     * Tout ce qu'il faut à LicenseComplianceRules::evaluate(), limité à ce que la personne voit.
     *
     * @return array{software: array<int, Software>, installs: list<Install>, licences: list<Licence>,
     *     assignments: list<Assignment>, ancestors: array<int, list<int>>}
     */
    public static function visible(): array
    {
        $installs = [];
        $software = [];
        foreach (self::installRows() as $row) {
            $installs[] = $row['install'];
            $software[$row['software']['id']] = $row['software'];
        }
        $licences = self::licences(array_keys($software));
        $assignments = self::assignments(array_map(static fn (array $l): int => $l['id'], $licences));
        $entities = [];
        foreach ($installs as $install) {
            $entities[$install['entity_id']] = true;
        }
        foreach ($licences as $licence) {
            $entities[$licence['entity_id']] = true;
        }
        $ancestors = [];
        foreach (array_keys($entities) as $entityId) {
            $ancestors[$entityId] = array_values(array_map(
                'intval',
                array_keys(getAncestorsOf('glpi_entities', $entityId))
            ));
        }

        return [
            'software'    => $software,
            'installs'    => $installs,
            'licences'    => $licences,
            'assignments' => $assignments,
            'ancestors'   => $ancestors,
        ];
    }

    /**
     * Installations visibles, avec leur logiciel et l'éditeur de celui-ci.
     *
     * @return list<array{install: Install, software: Software}>
     */
    private static function installRows(): array
    {
        global $DB, $CFG_GLPI;

        $rows = [];
        foreach ($CFG_GLPI['software_types'] ?? [] as $itemtype) {
            $assetCriteria = self::assetCriteria($itemtype);
            if ($assetCriteria === null) {
                continue;
            }
            $itemTable = $itemtype::getTable();
            $iterator = $DB->request([
                'SELECT'     => [
                    'glpi_items_softwareversions.items_id',
                    "{$itemTable}.name AS item_name",
                    "{$itemTable}.entities_id AS item_entity",
                    'glpi_softwares.id AS software_id',
                    'glpi_softwares.name AS software_name',
                    'glpi_manufacturers.name AS manufacturer',
                ],
                'DISTINCT'   => true,
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
                'LEFT JOIN'  => [
                    'glpi_manufacturers' => ['FKEY' => [
                        'glpi_softwares'     => 'manufacturers_id',
                        'glpi_manufacturers' => 'id',
                    ]],
                ],
                'WHERE'      => [
                    'glpi_items_softwareversions.itemtype'   => $itemtype,
                    'glpi_items_softwareversions.is_deleted' => 0,
                    'glpi_softwares.is_deleted'              => 0,
                    'glpi_softwares.is_template'             => 0,
                ] + $assetCriteria,
            ]);
            foreach ($iterator as $row) {
                $softwareId = (int) $row['software_id'];
                $rows[] = [
                    'install'  => [
                        'software_id' => $softwareId,
                        'entity_id'   => (int) $row['item_entity'],
                        'itemtype'    => $itemtype,
                        'items_id'    => (int) $row['items_id'],
                        'item_name'   => (string) ($row['item_name'] ?? ''),
                    ],
                    'software' => [
                        'id'           => $softwareId,
                        'name'         => (string) ($row['software_name'] ?? ''),
                        'manufacturer' => (string) ($row['manufacturer'] ?? ''),
                    ],
                ];
            }
        }

        return $rows;
    }

    /**
     * Licences visibles de ces logiciels : entités actives, et licences récursives des entités
     * parentes (règle native de GLPI).
     *
     * @param list<int> $softwareIds
     * @return list<Licence>
     */
    private static function licences(array $softwareIds): array
    {
        global $DB;

        if ($softwareIds === []) {
            return [];
        }
        $licences = [];
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'softwares_id', 'entities_id', 'is_recursive', 'number', 'expire', 'allow_overquota',
            ],
            'FROM'   => 'glpi_softwarelicenses',
            'WHERE'  => [
                'softwares_id' => $softwareIds,
                'is_deleted'   => 0,
                'is_template'  => 0,
            ] + getEntitiesRestrictCriteria('glpi_softwarelicenses', '', '', true),
            'ORDER'  => ['id'],
        ]);
        foreach ($iterator as $row) {
            $licences[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) ($row['name'] ?? ''),
                'software_id'  => (int) $row['softwares_id'],
                'entity_id'    => (int) $row['entities_id'],
                'is_recursive' => (int) $row['is_recursive'] === 1,
                'number'       => (int) $row['number'],
                'expire'       => (string) ($row['expire'] ?? ''),
                'allow_overquota' => (int) ($row['allow_overquota'] ?? 0) === 1,
            ];
        }

        return $licences;
    }

    /**
     * Actifs affectés à ces licences (Item_SoftwareLicense), hors affectations supprimées.
     *
     * @param list<int> $licenceIds
     * @return list<Assignment>
     */
    private static function assignments(array $licenceIds): array
    {
        global $DB;

        if ($licenceIds === []) {
            return [];
        }
        $assignments = [];
        $iterator = $DB->request([
            'SELECT' => ['softwarelicenses_id', 'itemtype', 'items_id'],
            'FROM'   => 'glpi_items_softwarelicenses',
            'WHERE'  => ['softwarelicenses_id' => $licenceIds, 'is_deleted' => 0],
        ]);
        foreach ($iterator as $row) {
            $assignments[] = [
                'licence_id' => (int) $row['softwarelicenses_id'],
                'itemtype'   => (string) $row['itemtype'],
                'items_id'   => (int) $row['items_id'],
            ];
        }

        return $assignments;
    }

    /**
     * Critères sur la table de l'actif (ni corbeille ni gabarit, entités actives), null si le type
     * n'est pas lisible par la personne ou sans entité.
     *
     * @return array<mixed>|null
     */
    private static function assetCriteria(mixed $itemtype): ?array
    {
        global $DB;

        if (!is_string($itemtype) || !is_a($itemtype, \CommonDBTM::class, true) || !$itemtype::canView()) {
            return null;
        }
        $itemTable = $itemtype::getTable();
        if (
            !$DB->tableExists($itemTable) || !$DB->fieldExists($itemTable, 'name')
            || !$DB->fieldExists($itemTable, 'entities_id')
        ) {
            return null;
        }
        $criteria = [];
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($itemTable, $flag)) {
                $criteria["{$itemTable}.{$flag}"] = 0;
            }
        }

        return $criteria + getEntitiesRestrictCriteria(
            $itemTable,
            '',
            '',
            $DB->fieldExists($itemTable, 'is_recursive')
        );
    }
}
