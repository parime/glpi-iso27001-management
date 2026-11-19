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

use CommonGLPI;
use CronTask;
use Html;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CacheRefresh;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeClient;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeException;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher;

/**
 * Issue #704 : fin de vie des logiciels, systèmes d'exploitation et firmwares du parc, d'après
 * l'API publique d'endoflife.date.
 *
 * - Source désactivée par défaut, activée dans la configuration du plugin ; cache rafraîchi par
 *   une tâche automatique quotidienne (« endoflifesync »), jamais à l'affichage d'une page.
 * - Restitution sur la page « Vulnérabilités du parc » (aucune entrée de menu), export CSV, preuves
 *   des mesures A.8.8 et A.8.9, règle du contrôle de cohérence.
 * - Aucun ticket ni statut n'est créé ou modifié automatiquement.
 *
 * Ancre sans table (CommonGLPI), comme ParkVulnerability, dont elle reprend les droits.
 *
 * @phpstan-import-type EvaluatedItem from EndOfLifeInventory
 */
class EndOfLife extends CommonGLPI
{
    public const CRON_NAME = 'endoflifesync';

    /** Groupes affichés au plus sur la page ; le reste est compté et reste dans l'export. */
    public const MAX_GROUPS = 200;

    /** Actifs nommés au plus par groupe sur la page. */
    public const MAX_ASSETS_PER_GROUP = 5;

    /** Noms non rapprochés proposés dans la configuration. */
    public const MAX_UNMAPPED = 30;

    public static function getTypeName($nb = 0)
    {
        return __('End of support', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-x';
    }

    public static function canView(): bool
    {
        return ParkVulnerability::canView();
    }

    public static function getExportURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/endoflife.export.php';
    }

    /**
     * Onglet « Fin de support » de la configuration du plugin.
     */
    public static function getConfigURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/config.php#grc-endoflife-config';
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
            CycleMatcher::STATUS_EOL       => [
                'label' => __('No longer supported', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-calendar-x',
                'help'  => __('This version no longer receives security fixes.', 'grcmanager'),
            ],
            CycleMatcher::STATUS_SOON      => [
                'label' => __('End of support soon', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-hourglass-high',
                'help'  => __('Plan the move to a version that is still supported.', 'grcmanager'),
            ],
            CycleMatcher::STATUS_SUPPORTED => [
                'label' => __('Supported', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
                'help'  => __('This version still receives security fixes.', 'grcmanager'),
            ],
            CycleMatcher::STATUS_UNKNOWN   => [
                'label' => __('Support unknown', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __('End of support not determined: never counted as supported.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function statusInfo(string $status): array
    {
        return self::statusInfos()[$status] ?? self::statusInfos()[CycleMatcher::STATUS_UNKNOWN];
    }

    /**
     * @return array<string, string>
     */
    public static function reasonLabels(): array
    {
        return [
            CycleMatcher::REASON_NO_PRODUCT        => __('Not linked to an endoflife.date product.', 'grcmanager'),
            CycleMatcher::REASON_NOT_CHECKED       => __(
                'Not read yet: the daily task has not run since this product was linked, or the source '
                    . 'could not be reached.',
                'grcmanager'
            ),
            CycleMatcher::REASON_PRODUCT_NOT_FOUND => __(
                'Product unknown to endoflife.date: check the link in the configuration.',
                'grcmanager'
            ),
            CycleMatcher::REASON_NO_CYCLE          => __(
                'No release cycle of this product matches the installed version.',
                'grcmanager'
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function errorLabels(): array
    {
        return [
            EndOfLifeException::NOT_FOUND    => __('Product unknown to the source', 'grcmanager'),
            EndOfLifeException::RATE_LIMITED => __('Too many requests: the source asked to wait', 'grcmanager'),
            EndOfLifeException::SERVER_ERROR => __('Error on the source server', 'grcmanager'),
            EndOfLifeException::UNEXPECTED   => __('Unexpected answer from the source', 'grcmanager'),
            EndOfLifeException::UNREACHABLE  => __('Source unreachable', 'grcmanager'),
            EndOfLifeException::SKIPPED      => __('Not queried (earlier error or limit reached)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            ProductMatcher::KIND_SOFTWARE => __('Software', 'grcmanager'),
            ProductMatcher::KIND_OS       => __('Operating system', 'grcmanager'),
            ProductMatcher::KIND_FIRMWARE => __('Firmware', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function originLabels(): array
    {
        return [
            ProductMatcher::ORIGIN_MANUAL => __('Manual link', 'grcmanager'),
            ProductMatcher::ORIGIN_AUTO   => __('Automatic link', 'grcmanager'),
            ProductMatcher::ORIGIN_NONE   => __('Not linked', 'grcmanager'),
        ];
    }

    /**
     * Phrase de détail d'une évaluation (date de fin, raison d'un état inconnu).
     *
     * @param array{status: string, reason: string, eol: string, days: int|null} $evaluation
     */
    public static function detail(array $evaluation): string
    {
        $eol = $evaluation['eol'];
        $days = $evaluation['days'];

        return match (true) {
            $eol === EndOfLifeClient::EOL_WITHOUT_DATE => __('Support ended (date not published).', 'grcmanager'),
            $eol === '' && $evaluation['status'] === CycleMatcher::STATUS_SUPPORTED
                => __('No end of support announced.', 'grcmanager'),
            $days !== null && $days <= 0 => sprintf(
                __('Support ended on %s.', 'grcmanager'),
                Html::convDate($eol)
            ),
            $days !== null => sprintf(
                _n(
                    'Support ends on %1$s (in %2$d day).',
                    'Support ends on %1$s (in %2$d days).',
                    $days,
                    'grcmanager'
                ),
                Html::convDate($eol),
                $days
            ),
            default => self::reasonLabels()[$evaluation['reason']] ?? '',
        };
    }

    // ------------------------------------------------------------------ Mise à jour du cache.

    /**
     * Relit les produits en service dont le cache a plus d'un jour. Rien si la source est
     * désactivée. Jamais d'exception : limite de requêtes ou panne arrêtent seulement les appels.
     *
     * @param EndOfLifeClient|null $client Transport de test ; client réel sinon.
     * @return array{status: string, products: int, fetched: int, cached: int, not_found: int,
     *     requests: int, errors: list<array{product: string, code: string}>}
     */
    public static function refresh(int $budget = CacheRefresh::DEFAULT_BUDGET, ?EndOfLifeClient $client = null): array
    {
        if (!EndOfLifeStore::isEnabled()) {
            return ['status' => 'disabled', 'products' => 0, 'fetched' => 0, 'cached' => 0, 'not_found' => 0,
                'requests' => 0, 'errors' => []];
        }
        $now = date('Y-m-d H:i:s');
        $cache = [];
        foreach (EndOfLifeStore::cache() as $product => $row) {
            $cache[$product] = ['date_success' => $row['date_success'], 'error' => $row['error']];
        }
        $result = CacheRefresh::run(
            EndOfLifeInventory::productsInUse(),
            $cache,
            $client ?? new EndOfLifeClient(),
            $now,
            $budget
        );
        EndOfLifeStore::applyWrites($result['writes'], $now);

        return ['status' => $result['report']['errors'] === [] ? 'ok' : 'partial'] + $result['report'];
    }

    /**
     * Compte rendu lisible d'une mise à jour.
     *
     * @param array{status: string, products: int, fetched: int, cached: int, not_found: int,
     *     requests: int, errors: list<array{product: string, code: string}>} $report
     */
    public static function refreshMessage(array $report): string
    {
        if ($report['status'] === 'disabled') {
            return __('The end of support source is disabled: nothing was read.', 'grcmanager');
        }
        $message = sprintf(
            __(
                'End of support: %1$d products, %2$d read, %3$d already up to date, %4$d unknown to the source.',
                'grcmanager'
            ),
            $report['products'],
            $report['fetched'],
            $report['cached'],
            $report['not_found']
        );
        if ($report['errors'] !== []) {
            $message .= ' ' . sprintf(
                __('%d product(s) not read: the data already in cache is still used.', 'grcmanager'),
                count($report['errors'])
            );
        }

        return $message;
    }

    /**
     * @return array{description?: string}
     */
    public static function cronInfo(string $name): array
    {
        return $name === self::CRON_NAME
            ? ['description' => __('End of support of software, systems and firmwares (endoflife.date)', 'grcmanager')]
            : [];
    }

    /**
     * Tâche automatique quotidienne : rien tant que la source est désactivée ; jamais en échec à
     * cause de la source (429, panne) : les données en cache restent utilisées.
     */
    public static function cronEndoflifesync(CronTask $task): int
    {
        $report = self::refresh();
        if ($report['status'] === 'disabled') {
            return 0;
        }
        $task->addVolume($report['fetched']);
        $task->log(self::refreshMessage($report));
        foreach ($report['errors'] as $error) {
            if ($error['code'] !== EndOfLifeException::SKIPPED) {
                $task->log($error['product'] . ' : ' . (self::errorLabels()[$error['code']] ?? $error['code']));
            }
        }

        return 1;
    }

    // ------------------------------------------------------------------ Restitution.

    /**
     * Éléments visibles avec un produit (suivis), du plus grave au moins grave.
     *
     * @return list<EvaluatedItem>
     */
    public static function trackedItems(?string $today = null): array
    {
        $items = array_values(array_filter(
            EndOfLifeInventory::evaluateVisible($today),
            static fn (array $item): bool => $item['evaluation']['product'] !== ''
        ));
        usort($items, static fn (array $a, array $b): int => [
            CycleMatcher::severity($a['evaluation']['status']),
            $a['evaluation']['eol'],
            $a['name'],
            $a['item_name'],
        ] <=> [
            CycleMatcher::severity($b['evaluation']['status']),
            $b['evaluation']['eol'],
            $b['name'],
            $b['item_name'],
        ]);

        return $items;
    }

    /**
     * Données de la carte « Fin de support » de la page des vulnérabilités du parc.
     *
     * @param bool $all Tous les éléments suivis, et pas seulement « plus supporté » et « fin proche ».
     * @return array<string, mixed>
     */
    public static function pageData(bool $all = false, ?string $today = null): array
    {
        $settings = EndOfLifeStore::settings();
        $data = [
            'enabled'      => $settings['enabled'],
            'warning_days' => $settings['warning_days'],
            'all'          => $all,
            'export_url'   => self::getExportURL(),
            'config_url'   => self::getConfigURL(),
            'statuses'     => self::statusInfos(),
        ];
        if (!$settings['enabled']) {
            return $data;
        }
        $evaluated = EndOfLifeInventory::evaluateVisible($today);
        $counts = array_fill_keys(CycleMatcher::STATUSES, 0);
        $assets = array_fill_keys(CycleMatcher::STATUSES, []);
        $unlinked = [];
        $groups = [];
        foreach ($evaluated as $item) {
            $evaluation = $item['evaluation'];
            if ($evaluation['product'] === '') {
                $unlinked[$item['kind'] . "\0" . $item['name']] = true;
                continue;
            }
            $status = $evaluation['status'];
            $counts[$status]++;
            $assets[$status][$item['itemtype'] . "\0" . $item['items_id']] = true;
            if (!$all && !in_array($status, [CycleMatcher::STATUS_EOL, CycleMatcher::STATUS_SOON], true)) {
                continue;
            }
            $key = $item['kind'] . "\0" . $item['name'] . "\0" . $item['version'];
            $groups[$key] ??= [
                'kind'         => $item['kind'],
                'kind_label'   => self::kindLabels()[$item['kind']] ?? $item['kind'],
                'name'         => $item['name'],
                'version'      => $item['version'],
                'origin'       => $item['origin'],
                'origin_label' => self::originLabels()[$item['origin']] ?? $item['origin'],
                'status'       => self::statusInfo($status),
                'status_code'  => $status,
                'product'      => $evaluation['product'],
                'product_url'  => self::productUrl($evaluation['product']),
                'cycle'        => $evaluation['cycle'],
                'eol'          => $evaluation['eol'],
                'latest'       => $evaluation['latest'],
                'detail'       => self::detail($evaluation),
                'count'        => 0,
                'assets'       => [],
            ];
            $groups[$key]['count']++;
            if (count($groups[$key]['assets']) < self::MAX_ASSETS_PER_GROUP) {
                $groups[$key]['assets'][] = self::assetLink($item);
            }
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [
            CycleMatcher::severity($a['status_code']), $a['eol'] === '' ? '9' : $a['eol'], -$a['count'], $a['name'],
        ] <=> [
            CycleMatcher::severity($b['status_code']), $b['eol'] === '' ? '9' : $b['eol'], -$b['count'], $b['name'],
        ]);
        $source = EndOfLifeStore::sourceState();

        return $data + [
            'counts'       => $counts,
            'asset_counts' => array_map('count', $assets),
            'unlinked'     => count($unlinked),
            'groups'       => array_slice($groups, 0, self::MAX_GROUPS),
            'total_groups' => count($groups),
            'last_success' => $source['last_success'] === '' ? '' : Html::convDateTime($source['last_success']),
            'never_read'   => $source['last_success'] === '',
            'errors'       => array_map(static fn (array $e): array => [
                'product' => $e['product'],
                'label'   => self::errorLabels()[$e['code']] ?? $e['code'],
            ], $source['errors']),
        ];
    }

    /**
     * Lien vers l'actif (nom, type, adresse de sa fiche).
     *
     * @param array{itemtype: string, items_id: int, item_name: string} $item
     * @return array{name: string, type: string, url: string}
     */
    private static function assetLink(array $item): array
    {
        $itemtype = $item['itemtype'];
        $type = is_a($itemtype, \CommonGLPI::class, true) ? $itemtype::getTypeName(1) : $itemtype;

        return [
            'name' => $item['item_name'] !== ''
                ? $item['item_name']
                : sprintf(__('%1$s (ID %2$d)', 'grcmanager'), $type, $item['items_id']),
            'type' => $type,
            'url'  => is_a($itemtype, \CommonDBTM::class, true) ? $itemtype::getFormURLWithID($item['items_id']) : '',
        ];
    }

    /**
     * Page publique du produit, seulement avec la source par défaut ('' sinon).
     */
    public static function productUrl(string $product): string
    {
        return $product !== '' && EndOfLifeClient::configuredUrl() === EndOfLifeClient::DEFAULT_URL
            ? EndOfLifeClient::PRODUCT_PAGE . rawurlencode($product)
            : '';
    }

    /**
     * Lignes de l'export CSV : un élément suivi par ligne, avec son actif.
     *
     * @return list<list<string>>
     */
    public static function csvRows(?string $today = null): array
    {
        $rows = [[
            __('Status', 'grcmanager'),
            __('Asset', 'grcmanager'),
            __('Asset type', 'grcmanager'),
            __('Kind of item', 'grcmanager'),
            __('Name in GLPI', 'grcmanager'),
            __('Installed version', 'grcmanager'),
            __('endoflife.date product', 'grcmanager'),
            __('Release cycle', 'grcmanager'),
            __('End of support', 'grcmanager'),
            __('Latest version of the cycle', 'grcmanager'),
            __('How it was linked', 'grcmanager'),
            __('Detail', 'grcmanager'),
        ]];
        foreach (self::trackedItems($today) as $item) {
            $evaluation = $item['evaluation'];
            $asset = self::assetLink($item);
            $rows[] = [
                self::statusInfo($evaluation['status'])['label'],
                $asset['name'],
                $asset['type'],
                self::kindLabels()[$item['kind']] ?? $item['kind'],
                $item['name'],
                $item['version'],
                $evaluation['product'],
                $evaluation['cycle'],
                $evaluation['eol'] === EndOfLifeClient::EOL_WITHOUT_DATE ? '' : $evaluation['eol'],
                $evaluation['latest'],
                self::originLabels()[$item['origin']] ?? $item['origin'],
                self::detail($evaluation),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Configuration.

    /**
     * Données de l'onglet de configuration : réglages, état de la source, correspondances
     * manuelles et automatiques, noms encore non rapprochés.
     *
     * @return array<string, mixed>
     */
    public static function configData(): array
    {
        $settings = EndOfLifeStore::settings();
        $manual = EndOfLifeStore::manualMap();
        $auto = [];
        $unlinked = [];
        foreach (EndOfLifeInventory::namesInUse() as $entry) {
            $resolved = ProductMatcher::resolve($entry['kind'], $entry['name'], $manual);
            if ($resolved['origin'] === ProductMatcher::ORIGIN_AUTO) {
                $auto[] = $entry + [
                    'product'    => $resolved['product'],
                    'kind_label' => self::kindLabels()[$entry['kind']],
                ];
            } elseif (!array_key_exists(ProductMatcher::key($entry['kind'], $entry['name']), $manual)) {
                $unlinked[] = $entry + ['kind_label' => self::kindLabels()[$entry['kind']]];
            }
        }
        $sort = static fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']];
        usort($auto, $sort);
        usort($unlinked, $sort);
        $cache = EndOfLifeStore::cache();
        $mappings = array_map(static fn (array $row): array => $row + [
            'kind_label'  => self::kindLabels()[$row['kind']] ?? $row['kind'],
            'product_url' => self::productUrl($row['product']),
            'not_found'   => ($cache[$row['product']]['error'] ?? '') === EndOfLifeException::NOT_FOUND,
        ], EndOfLifeStore::mappings());
        $source = EndOfLifeStore::sourceState();

        return [
            'enabled'          => $settings['enabled'],
            'warning_days'     => $settings['warning_days'],
            'min_days'         => EndOfLifeStore::MIN_WARNING_DAYS,
            'max_days'         => EndOfLifeStore::MAX_WARNING_DAYS,
            'kinds'            => self::kindLabels(),
            'mappings'         => $mappings,
            'auto'             => $auto,
            'unlinked'         => array_slice($unlinked, 0, self::MAX_UNMAPPED),
            'unlinked_total'   => count($unlinked),
            'last_success'     => $source['last_success'] === '' ? '' : Html::convDateTime($source['last_success']),
            'last_attempt'     => $source['last_attempt'] === '' ? '' : Html::convDateTime($source['last_attempt']),
            'cached_products'  => $source['products'],
            'errors'           => array_map(static fn (array $e): array => [
                'product' => $e['product'],
                'label'   => self::errorLabels()[$e['code']] ?? $e['code'],
            ], $source['errors']),
        ];
    }

    // ------------------------------------------------------------------ Preuves et cohérence.

    /**
     * Preuve des mesures A.8.8 et A.8.9 : éléments et actifs plus supportés ou en fin proche
     * (entités actives). Null si la mesure n'est pas concernée ou la source désactivée.
     *
     * @return array{eol: int, eol_assets: int, soon: int, soon_assets: int, supported: int,
     *     warning_days: int, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.8', 'A.8.9'], true) || !EndOfLifeStore::isEnabled() || !self::canView()) {
            return null;
        }
        $data = self::pageData();

        return [
            'eol'          => (int) $data['counts'][CycleMatcher::STATUS_EOL],
            'eol_assets'   => (int) $data['asset_counts'][CycleMatcher::STATUS_EOL],
            'soon'         => (int) $data['counts'][CycleMatcher::STATUS_SOON],
            'soon_assets'  => (int) $data['asset_counts'][CycleMatcher::STATUS_SOON],
            'supported'    => (int) $data['counts'][CycleMatcher::STATUS_SUPPORTED],
            'warning_days' => (int) $data['warning_days'],
            'url'          => ParkVulnerability::getSearchURL() . '#grc-endoflife',
        ];
    }

    /**
     * Contrôle de cohérence : actifs visibles qui portent un élément plus supporté, avec le détail.
     * Null si la source est désactivée ou la page non lisible (règle non évaluable).
     *
     * @return list<array{itemtype: string, items_id: int, item_name: string, details: list<string>}>|null
     */
    public static function unsupportedAssets(?string $today = null): ?array
    {
        if (!EndOfLifeStore::isEnabled() || !self::canView()) {
            return null;
        }
        $assets = [];
        foreach (self::trackedItems($today) as $item) {
            if ($item['evaluation']['status'] !== CycleMatcher::STATUS_EOL) {
                continue;
            }
            $key = $item['itemtype'] . "\0" . $item['items_id'];
            $assets[$key] ??= [
                'itemtype'  => $item['itemtype'],
                'items_id'  => $item['items_id'],
                'item_name' => $item['item_name'],
                'details'   => [],
            ];
            $label = trim($item['name'] . ' ' . $item['version']);
            if (!in_array($label, $assets[$key]['details'], true)) {
                $assets[$key]['details'][] = $label;
            }
        }

        return array_values($assets);
    }
}
