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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareWarrantyInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\WarrantyRules;
use Html;
use Infocom;
use Session;

/**
 * Issue #782 : fin de garantie du matériel, d'après les informations financières natives de GLPI
 * (date de début et durée de garantie), en complément de la fin de support des logiciels (#704).
 *
 * - Carte « Fin de garantie du matériel » sur la page des vulnérabilités du parc, sous la carte
 *   « Fin de support » ; états expirée, fin proche (seuil commun de la fin de support) et sous
 *   garantie, par type de matériel et par entité ; export CSV.
 * - Preuves des mesures A.7.13 (maintenance du matériel) et A.8.9, règle du contrôle de cohérence.
 * - Calculé à la demande avec les droits et les entités de la personne : rien n'est conservé et
 *   aucune donnée de GLPI n'est modifiée.
 *
 * Droits : droit des registres sensibles du plugin (comme la fin de support), plus la lecture
 * native des informations financières et celle de chaque type de matériel. Ancre sans table.
 *
 * @phpstan-import-type Asset from HardwareWarrantyInventory
 * @phpstan-import-type Warranty from WarrantyRules
 */
class HardwareWarranty extends CommonGLPI
{
    /** Matériels nommés au plus sur la page ; le reste est compté et reste dans l'export. */
    public const MAX_ROWS = 200;

    /** @var array<string, list<array<string, mixed>>> Matériels évalués pendant la requête, par date. */
    private static array $memo = [];

    public static function getTypeName($nb = 0)
    {
        return __('Hardware warranty', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public static function canView(): bool
    {
        return Session::haveRight(ParkVulnerability::RIGHTNAME, READ)
            && Session::haveRight(Infocom::$rightname, READ);
    }

    public static function getExportURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/hardwarewarranty.export.php';
    }

    /**
     * Oublie les matériels évalués (tests).
     */
    public static function reset(): void
    {
        self::$memo = [];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * Libellé, couleur, icône et aide de chaque état.
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            WarrantyRules::STATUS_EXPIRED => [
                'label' => __('Warranty expired', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-shield-x',
                'help'  => __(
                    'The manufacturer warranty has ended: check that a maintenance contract covers this hardware, '
                        . 'or plan its replacement.',
                    'grcmanager'
                ),
            ],
            WarrantyRules::STATUS_SOON    => [
                'label' => __('Warranty ends soon', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-hourglass-high',
                'help'  => __('Renew the warranty or a maintenance contract, or plan the replacement.', 'grcmanager'),
            ],
            WarrantyRules::STATUS_COVERED => [
                'label' => __('Under warranty', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
                'help'  => __('The warranty recorded in GLPI is still running.', 'grcmanager'),
            ],
            WarrantyRules::STATUS_UNKNOWN => [
                'label' => __('Warranty unknown', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'No warranty start date or duration in the financial information: never counted as under warranty.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function statusInfo(string $status): array
    {
        return self::statusInfos()[$status] ?? self::statusInfos()[WarrantyRules::STATUS_UNKNOWN];
    }

    /**
     * Phrase de détail d'une garantie.
     *
     * @param Warranty $warranty
     */
    public static function detail(array $warranty): string
    {
        $days = $warranty['days'];

        return match (true) {
            $warranty['lifetime'] => __('Lifetime warranty.', 'grcmanager'),
            $warranty['reason'] === WarrantyRules::REASON_NO_INFOCOM
                => __('No financial information recorded in GLPI.', 'grcmanager'),
            $warranty['reason'] === WarrantyRules::REASON_NO_DATE
                => __('No warranty start date in the financial information.', 'grcmanager'),
            $warranty['reason'] === WarrantyRules::REASON_NO_DURATION
                => __('No warranty duration in the financial information.', 'grcmanager'),
            $days !== null && $days <= 0 => sprintf(
                __('Warranty ended on %s.', 'grcmanager'),
                Html::convDate($warranty['end'])
            ),
            $days !== null => sprintf(
                _n(
                    'Warranty ends on %1$s (in %2$d day).',
                    'Warranty ends on %1$s (in %2$d days).',
                    $days,
                    'grcmanager'
                ),
                Html::convDate($warranty['end']),
                $days
            ),
            default => '',
        };
    }

    // ------------------------------------------------------------------ Évaluation.

    /**
     * Matériels visibles par la personne connectée, avec l'état de leur garantie, du plus grave au
     * moins grave ([] sans les droits).
     *
     * @param string|null $today « 2026-11-20 » (tests) ; date du jour sinon.
     * @return list<array<string, mixed>>
     */
    public static function assets(?string $today = null): array
    {
        if (!self::canView()) {
            return [];
        }
        $today ??= substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
        if (!isset(self::$memo[$today])) {
            $warning = EndOfLifeStore::warningDays();
            $assets = [];
            foreach (HardwareWarrantyInventory::visibleAssets() as $asset) {
                $warranty = WarrantyRules::evaluate(
                    $asset['has_infocom'],
                    $asset['warranty_date'],
                    $asset['warranty_duration'],
                    $today,
                    $warning
                );
                $assets[] = $asset + ['status' => $warranty['status'], 'warranty' => $warranty];
            }
            usort($assets, static fn (array $a, array $b): int => [
                WarrantyRules::severity($a['status']),
                $a['warranty']['end'] === '' ? '9' : $a['warranty']['end'],
                $a['itemtype'],
                $a['item_name'],
                $a['items_id'],
            ] <=> [
                WarrantyRules::severity($b['status']),
                $b['warranty']['end'] === '' ? '9' : $b['warranty']['end'],
                $b['itemtype'],
                $b['item_name'],
                $b['items_id'],
            ]);
            self::$memo[$today] = $assets;
        }

        return self::$memo[$today];
    }

    private static function typeLabel(string $itemtype): string
    {
        return is_a($itemtype, CommonGLPI::class, true) ? (string) $itemtype::getTypeName(1) : $itemtype;
    }

    private static function entityName(int $entityId): string
    {
        return (string) Dropdown::getDropdownName('glpi_entities', $entityId);
    }

    /**
     * Ligne affichable d'un matériel.
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private static function present(array $asset): array
    {
        $itemtype = (string) $asset['itemtype'];
        $type = self::typeLabel($itemtype);

        return [
            'status'       => self::statusInfo((string) $asset['status']),
            'status_code'  => $asset['status'],
            'type'         => $type,
            'name'         => $asset['item_name'] !== ''
                ? $asset['item_name']
                : sprintf(__('%1$s (ID %2$d)', 'grcmanager'), $type, $asset['items_id']),
            'url'          => is_a($itemtype, CommonDBTM::class, true)
                ? $itemtype::getFormURLWithID((int) $asset['items_id']) : '',
            'entity'       => self::entityName((int) $asset['entities_id']),
            'model'        => trim($asset['manufacturer'] . ' ' . $asset['model']),
            'end'          => $asset['warranty']['end'],
            'detail'       => self::detail($asset['warranty']),
        ];
    }

    /**
     * Données de la carte « Fin de garantie du matériel ». Null sans les droits (carte masquée).
     *
     * @param bool $all Tous les matériels, et pas seulement « expirée » et « fin proche ».
     * @return array<string, mixed>|null
     */
    public static function pageData(bool $all = false, ?string $today = null): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $assets = self::assets($today);
        $listed = $all ? $assets : array_values(array_filter(
            $assets,
            static fn (array $a): bool => in_array(
                $a['status'],
                [WarrantyRules::STATUS_EXPIRED, WarrantyRules::STATUS_SOON],
                true
            )
        ));
        $groups = array_map(static fn (array $g): array => $g + [
            'type'   => self::typeLabel($g['itemtype']),
            'entity' => self::entityName($g['entities_id']),
        ], WarrantyRules::crossTab($assets));
        usort(
            $groups,
            static fn (array $a, array $b): int => [$a['type'], $a['entity']] <=> [$b['type'], $b['entity']]
        );

        return [
            'all'          => $all,
            'warning_days' => EndOfLifeStore::warningDays(),
            'statuses'     => self::statusInfos(),
            'counts'       => WarrantyRules::counts($assets),
            'total_assets' => count($assets),
            'groups'       => $groups,
            'rows'         => array_map([self::class, 'present'], array_slice($listed, 0, self::MAX_ROWS)),
            'total_rows'   => count($listed),
            'export_url'   => self::getExportURL(),
        ];
    }

    /**
     * Lignes de l'export CSV : un matériel par ligne.
     *
     * @return list<list<string>>
     */
    public static function csvRows(?string $today = null): array
    {
        $rows = [[
            __('Status', 'grcmanager'),
            __('Asset', 'grcmanager'),
            __('Asset type', 'grcmanager'),
            __('Entity', 'grcmanager'),
            __('Manufacturer', 'grcmanager'),
            __('Model', 'grcmanager'),
            __('Warranty start date', 'grcmanager'),
            __('Warranty duration (months)', 'grcmanager'),
            __('Warranty end date', 'grcmanager'),
            __('Detail', 'grcmanager'),
        ]];
        foreach (self::assets($today) as $asset) {
            $row = self::present($asset);
            $duration = (int) $asset['warranty_duration'];
            $rows[] = [
                $row['status']['label'],
                $row['name'],
                $row['type'],
                $row['entity'],
                $asset['manufacturer'],
                $asset['model'],
                (string) ($asset['warranty_date'] ?? ''),
                $duration === WarrantyRules::LIFETIME
                    ? __('Lifetime', 'grcmanager')
                    : ($duration > 0 ? (string) $duration : ''),
                $asset['warranty']['end'],
                $row['detail'],
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Preuves et cohérence.

    /**
     * Preuve des mesures A.7.13 et A.8.9 : matériels de chaque état (entités actives). Null si la
     * mesure n'est pas concernée ou sans les droits.
     *
     * @return array{expired: int, soon: int, covered: int, unknown: int, warning_days: int, url: string,
     *     export_url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.7.13', 'A.8.9'], true) || !self::canView()) {
            return null;
        }
        $counts = WarrantyRules::counts(self::assets());

        return [
            'expired'      => $counts[WarrantyRules::STATUS_EXPIRED],
            'soon'         => $counts[WarrantyRules::STATUS_SOON],
            'covered'      => $counts[WarrantyRules::STATUS_COVERED],
            'unknown'      => $counts[WarrantyRules::STATUS_UNKNOWN],
            'warning_days' => EndOfLifeStore::warningDays(),
            'url'          => ParkVulnerability::getSearchURL() . '#grc-hardware-warranty',
            'export_url'   => self::getExportURL(),
        ];
    }

    /**
     * Contrôle de cohérence : matériels visibles dont la garantie est expirée. Null sans les droits
     * (règle non évaluable).
     *
     * @return list<array{itemtype: string, items_id: int, name: string, detail: string}>|null
     */
    public static function consistencyGaps(?string $today = null): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $gaps = [];
        foreach (self::assets($today) as $asset) {
            if ($asset['status'] !== WarrantyRules::STATUS_EXPIRED) {
                continue;
            }
            $gaps[] = [
                'itemtype' => (string) $asset['itemtype'],
                'items_id' => (int) $asset['items_id'],
                'name'     => (string) $asset['item_name'],
                'detail'   => self::detail($asset['warranty']),
            ];
        }

        return $gaps;
    }
}
