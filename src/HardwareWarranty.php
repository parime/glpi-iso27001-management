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
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareModelEolStore;
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
 * - Lot 2 : fin de vie déclarée par le constructeur, saisie à la main par modèle (issue #1039 :
 *   onglet des fiches de modèles et saisie groupée de HardwareModelEol), atteinte ou proche avec le
 *   même seuil commun ;
 *   règle du contrôle de cohérence.
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

    /** Modèles listés au plus dans la configuration de la fin de vie constructeur. */
    public const MAX_MODELS = 300;

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
     * Saisie de la fin de vie constructeur des modèles (lot 2). Issue #1039 : sortie de la
     * configuration, elle est dans Parc > Sécurité du parc (et l'onglet des fiches de modèles).
     */
    public static function getConfigURL(bool $full = true): string
    {
        return HardwareModelEol::getSearchURL($full);
    }

    /**
     * Oublie les matériels évalués (tests).
     */
    public static function reset(): void
    {
        self::$memo = [];
        HardwareModelEolStore::reset();
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
     * Libellé, couleur, icône et aide de chaque état de la fin de vie constructeur (lot 2).
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function endOfLifeInfos(): array
    {
        return [
            WarrantyRules::EOL_REACHED => [
                'label' => __('Manufacturer end of life reached', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-calendar-x',
                'help'  => __(
                    'The manufacturer no longer supports this model: plan its replacement, or record the '
                        . 'compensating measures.',
                    'grcmanager'
                ),
            ],
            WarrantyRules::EOL_SOON    => [
                'label' => __('Manufacturer end of life soon', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-calendar-due',
                'help'  => __(
                    'Plan the replacement before the manufacturer stops supporting this model.',
                    'grcmanager'
                ),
            ],
            WarrantyRules::EOL_FUTURE  => [
                'label' => __('Supported by the manufacturer', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-calendar-check',
                'help'  => __('The end of life date entered for this model is still ahead.', 'grcmanager'),
            ],
            WarrantyRules::EOL_NONE    => [
                'label' => __('No end of life date', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-calendar-question',
                'help'  => __(
                    'No manufacturer end of life date entered for this model (optional, on the form of the model).',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * Phrase de détail d'une fin de vie constructeur ('' sans date).
     *
     * @param array{status: string, date: string, days: int|null} $eol
     */
    public static function endOfLifeDetail(array $eol): string
    {
        $days = $eol['days'];

        return match (true) {
            $days === null => '',
            $days <= 0     => sprintf(
                __('Manufacturer end of life on %s.', 'grcmanager'),
                Html::convDate($eol['date'])
            ),
            default        => sprintf(
                _n(
                    'Manufacturer end of life on %1$s (in %2$d day).',
                    'Manufacturer end of life on %1$s (in %2$d days).',
                    $days,
                    'grcmanager'
                ),
                Html::convDate($eol['date']),
                $days
            ),
        };
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
                $eol = WarrantyRules::endOfLife(
                    HardwareModelEolStore::dateFor($asset['model_itemtype'], $asset['models_id']),
                    $today,
                    $warning
                );
                $assets[] = $asset + ['status' => $warranty['status'], 'warranty' => $warranty, 'eol' => $eol];
            }
            usort($assets, static fn (array $a, array $b): int => [
                self::urgency($a),
                WarrantyRules::severity($a['status']),
                $a['warranty']['end'] === '' ? '9' : $a['warranty']['end'],
                $a['itemtype'],
                $a['item_name'],
                $a['items_id'],
            ] <=> [
                self::urgency($b),
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

    /**
     * Rang d'urgence d'un matériel : le plus grave de sa garantie et de sa fin de vie constructeur.
     *
     * @param array<string, mixed> $asset
     */
    private static function urgency(array $asset): int
    {
        $eol = match ($asset['eol']['status']) {
            WarrantyRules::EOL_REACHED => 0,
            WarrantyRules::EOL_SOON    => 1,
            default                    => 9,
        };

        return min($eol, WarrantyRules::severity((string) $asset['status']));
    }

    /**
     * À traiter : garantie expirée ou proche, ou fin de vie constructeur atteinte ou proche.
     *
     * @param array<string, mixed> $asset
     */
    public static function needsAction(array $asset): bool
    {
        return in_array($asset['status'], [WarrantyRules::STATUS_EXPIRED, WarrantyRules::STATUS_SOON], true)
            || in_array($asset['eol']['status'], [WarrantyRules::EOL_REACHED, WarrantyRules::EOL_SOON], true);
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
            'eol'          => self::endOfLifeInfos()[$asset['eol']['status']]
                ?? self::endOfLifeInfos()[WarrantyRules::EOL_NONE],
            'eol_code'     => $asset['eol']['status'],
            'eol_detail'   => self::endOfLifeDetail($asset['eol']),
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
        $listed = $all ? $assets : array_values(array_filter($assets, [self::class, 'needsAction']));
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
            'eol_statuses' => self::endOfLifeInfos(),
            'eol_counts'   => WarrantyRules::endOfLifeCounts($assets),
            'config_url'   => self::getConfigURL(),
            'can_config'   => HardwareModelEol::canView(),
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
            __('Manufacturer end of life date', 'grcmanager'),
            __('Manufacturer end of life', 'grcmanager'),
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
                $asset['eol']['date'],
                $row['eol']['label'],
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Preuves et cohérence.

    /**
     * Preuve des mesures A.7.13 et A.8.9 : matériels de chaque état (entités actives). Null si la
     * mesure n'est pas concernée ou sans les droits.
     *
     * @return array{expired: int, soon: int, covered: int, unknown: int, eol_reached: int, eol_soon: int,
     *     warning_days: int, url: string, export_url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.7.13', 'A.8.9'], true) || !self::canView()) {
            return null;
        }
        $assets = self::assets();
        $counts = WarrantyRules::counts($assets);
        $eol = WarrantyRules::endOfLifeCounts($assets);

        return [
            'expired'      => $counts[WarrantyRules::STATUS_EXPIRED],
            'soon'         => $counts[WarrantyRules::STATUS_SOON],
            'covered'      => $counts[WarrantyRules::STATUS_COVERED],
            'unknown'      => $counts[WarrantyRules::STATUS_UNKNOWN],
            'eol_reached'  => $eol[WarrantyRules::EOL_REACHED],
            'eol_soon'     => $eol[WarrantyRules::EOL_SOON],
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

    /**
     * Contrôle de cohérence (lot 2) : matériels visibles dont le modèle a atteint la fin de vie
     * déclarée par le constructeur. Null sans les droits (règle non évaluable).
     *
     * @return list<array{itemtype: string, items_id: int, name: string, detail: string}>|null
     */
    public static function endOfLifeGaps(?string $today = null): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $gaps = [];
        foreach (self::assets($today) as $asset) {
            if ($asset['eol']['status'] !== WarrantyRules::EOL_REACHED) {
                continue;
            }
            $model = trim($asset['manufacturer'] . ' ' . $asset['model']);
            $gaps[] = [
                'itemtype' => (string) $asset['itemtype'],
                'items_id' => (int) $asset['items_id'],
                'name'     => (string) $asset['item_name'],
                'detail'   => ($model !== '' ? $model . ' : ' : '') . self::endOfLifeDetail($asset['eol']),
            ];
        }

        return $gaps;
    }

    // ------------------------------------------------------------------ Configuration (lot 2).

    /**
     * Modèles de matériel en service (types lisibles, entités actives) et ceux qui ont déjà une date,
     * pour la saisie de la fin de vie constructeur dans la configuration.
     *
     * @return array{models: list<array<string, mixed>>, total: int, max: int}
     */
    public static function modelConfigData(): array
    {
        $models = [];
        foreach (HardwareWarrantyInventory::visibleAssets() as $asset) {
            if ($asset['models_id'] <= 0) {
                continue;
            }
            $key = HardwareModelEolStore::key($asset['model_itemtype'], $asset['models_id']);
            $models[$key] ??= [
                'itemtype'     => $asset['model_itemtype'],
                'id'           => $asset['models_id'],
                'type'         => self::typeLabel($asset['itemtype']),
                'name'         => $asset['model'],
                'manufacturer' => $asset['manufacturer'],
                'assets'       => 0,
            ];
            $models[$key]['assets']++;
        }
        $dates = HardwareModelEolStore::dates();
        $classes = HardwareModelEolStore::modelClasses();
        foreach (array_keys($dates) as $key) {
            [$itemtype, $id] = explode(':', $key, 2) + ['', '0'];
            if (isset($models[$key]) || !in_array($itemtype, $classes, true)) {
                continue;
            }
            // Modèle daté, sans matériel visible : toujours listé pour pouvoir effacer sa date.
            $models[$key] = [
                'itemtype'     => $itemtype,
                'id'           => (int) $id,
                'type'         => self::typeLabel($itemtype),
                'name'         => (string) Dropdown::getDropdownName($itemtype::getTable(), (int) $id),
                'manufacturer' => '',
                'assets'       => 0,
            ];
        }
        foreach (array_keys($models) as $key) {
            $models[$key]['date'] = $dates[$key] ?? '';
            $models[$key]['field'] = $key;
            // Issue #1039 : lien vers la fiche du modèle (onglet « Fin de vie constructeur »).
            $modelType = (string) $models[$key]['itemtype'];
            $models[$key]['url'] = is_a($modelType, CommonDBTM::class, true) && $modelType::canView()
                ? $modelType::getFormURLWithID((int) $models[$key]['id'])
                : '';
        }
        // Modèles datés d'abord, puis les plus répandus.
        uasort($models, static fn (array $a, array $b): int => [
            $a['date'] === '' ? 1 : 0, -$a['assets'], $a['type'], $a['manufacturer'], $a['name'],
        ] <=> [
            $b['date'] === '' ? 1 : 0, -$b['assets'], $b['type'], $b['manufacturer'], $b['name'],
        ]);

        return [
            'models' => array_slice(array_values($models), 0, self::MAX_MODELS),
            'total'  => count($models),
            'max'    => self::MAX_MODELS,
        ];
    }

    /**
     * Enregistre les dates saisies (« ComputerModel:12 » => « 2027-06-30 », vide : effacer).
     * Renvoie le nombre de saisies refusées ; les changements sont tracés dans le journal d'audit.
     *
     * @param array<mixed> $input
     */
    public static function saveModelDates(array $input): int
    {
        $refused = 0;
        $changes = [];
        $dates = HardwareModelEolStore::dates();
        foreach ($input as $field => $date) {
            if (!is_string($field) || !is_string($date) || !str_contains($field, ':')) {
                $refused++;
                continue;
            }
            [$itemtype, $id] = explode(':', $field, 2);
            $before = $dates[HardwareModelEolStore::key($itemtype, (int) $id)] ?? '';
            if (trim($date) === $before) {
                continue;
            }
            if (!HardwareModelEolStore::save($itemtype, (int) $id, $date, (int) Session::getLoginUserID())) {
                $refused++;
                continue;
            }
            $changes[$field] = ['before' => $before, 'after' => trim($date)];
        }
        if ($changes !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['hardware_end_of_life' => $changes]);
        }
        self::reset();

        return $refused;
    }
}
