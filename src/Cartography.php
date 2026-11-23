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
use DbUtils;
use GlpiPlugin\Grcmanager\Feature\CartographySourcesProvider;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\EdgeSource;
use GlpiPlugin\Grcmanager\Services\Cartography\LayeredLayout;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\DockerSource;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\InventorySource;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\RelationSource;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use NetworkEquipment;
use Session;

/**
 * Issue #1082 : cartographie du SI par couches (urbanisation), du switch au processus métier
 * (A.5.9, inventaire des actifs et de leurs dépendances ; A.5.30, continuité des TIC).
 *
 * À partir d'un élément, la page répond à deux questions :
 * - « que touche la panne de cet équipement ? » : tout ce qui en dépend, jusqu'aux processus métier
 *   du BIA, rangés par RTO ;
 * - « de quoi dépend ce processus ? » : tout ce qui le porte, jusqu'au réseau.
 *
 * Les liens viennent de ce que GLPI et GRC connaissent déjà (voir Services\Cartography\Source) ; rien
 * n'est créé ni modifié. Un élément que la personne ne peut pas voir est compté sans être nommé.
 */
class Cartography extends CommonGLPI
{
    // Carte des dépendances : registre sensible, comme l'analyse d'impact.
    public const RIGHTNAME = ImpactAnalysis::RIGHTNAME;

    public static function getTypeName($nb = 0)
    {
        return __('IT system map', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-stack-2';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/cartography.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /**
     * @return array<string, string>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Sources de liens : celles du plugin, puis celles des fonctionnalités (point d'extension).
     *
     * @return list<class-string<EdgeSource>>
     */
    public static function sources(): array
    {
        $sources = [InventorySource::class, RelationSource::class, DockerSource::class];
        foreach (FeatureRegistry::implementing(CartographySourcesProvider::class) as $feature) {
            $sources = array_merge($sources, $feature::cartographySources());
        }

        return array_values(array_unique($sources));
    }

    /**
     * Types d'éléments proposés comme point de départ.
     *
     * @return list<string>
     */
    public static function startItemtypes(): array
    {
        $types = ['NetworkEquipment', 'Computer', 'Software', 'Appliance', 'DatabaseInstance', BusinessProcess::class];
        if (DockerSource::available()) {
            $types[] = Layers::DOCKER_SERVICE;
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(CommonDBTM $item, string $direction): array
    {
        $sources = self::sources();
        $down = $direction === DependencyWalker::DOWN;
        $next = static function (string $key) use ($sources, $down): array {
            [$itemtype, $itemsId] = ImpactGraph::split($key);
            $keys = [];
            foreach ($sources as $source) {
                $keys = array_merge(
                    $keys,
                    $down ? $source::supporters($itemtype, $itemsId) : $source::dependents($itemtype, $itemsId)
                );
            }

            return array_values(array_unique($keys));
        };
        $start = ImpactGraph::key($item::class, (int) $item->getID());
        $walk = DependencyWalker::walk($start, $down ? DependencyWalker::DOWN : DependencyWalker::UP, $next);

        $nodes = [];
        $rto = [];
        foreach ($walk['nodes'] as $key => $depth) {
            $nodes[$key] = self::describe($key) + ['depth' => $depth, 'start' => $key === $start];
            if ($nodes[$key]['layer'] === Layers::BUSINESS && $key !== $start) {
                $rto[$key] = $nodes[$key]['rto'];
            }
        }
        $layers = DependencyWalker::byLayer(
            $walk['nodes'],
            static fn (string $key): int => $nodes[$key]['layer']
        );
        $layout = LayeredLayout::place($layers);
        $edges = [];
        foreach ($walk['edges'] as [$supporter, $dependent]) {
            if (isset($layout['nodes'][$supporter], $layout['nodes'][$dependent])) {
                $edges[] = ['from' => $layout['nodes'][$supporter], 'to' => $layout['nodes'][$dependent]];
            }
        }
        $processes = [];
        foreach (DependencyWalker::byRecoveryTime(array_intersect_key($walk['nodes'], $rto), $rto) as $key) {
            $processes[] = $nodes[$key];
        }
        $labels = Layers::labels();
        $icons = Layers::icons();
        $byLayer = [];
        foreach (array_reverse(Layers::ALL) as $layer) {
            $byLayer[] = [
                'layer' => $layer,
                'label' => $labels[$layer],
                'icon'  => $icons[$layer],
                'nodes' => array_map(static fn (string $key): array => $nodes[$key], $layers[$layer]),
            ];
        }
        $bands = [];
        foreach ($layout['bands'] as $band) {
            $bands[] = $band + ['short' => LayeredLayout::shortLabel($labels[$band['layer']], 20)];
        }
        $drawn = [];
        foreach ($layout['nodes'] as $key => $position) {
            $drawn[] = $nodes[$key] + $position;
        }

        return [
            'start'       => $nodes[$start],
            'start_layer' => $labels[$nodes[$start]['layer']],
            'direction'   => $down ? DependencyWalker::DOWN : DependencyWalker::UP,
            'count'       => count($walk['nodes']) - 1,
            'truncated'   => $walk['truncated'],
            'processes'   => $processes,
            'layers'      => $byLayer,
            'drawing'     => [
                'width'  => $layout['width'],
                'height' => $layout['height'],
                'bands'  => $bands,
                'nodes'  => $drawn,
                'edges'  => $edges,
                'node_w' => LayeredLayout::NODE_WIDTH,
                'node_h' => LayeredLayout::NODE_HEIGHT,
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, short: string, url: string, visible: bool, layer: int,
     *     type: string, rto: int, rto_label: string, rto_short: string}
     */
    public static function describe(string $key): array
    {
        [$itemtype, $itemsId] = ImpactGraph::split($key);
        $node = ImpactAnalysis::describe($key);
        $rto = $node['rto'];
        if ($itemtype === BusinessProcess::class && $node['visible']) {
            $process = new BusinessProcess();
            $rto = $process->getFromDB($itemsId) ? (int) $process->fields['rto_hours'] : 0;
        }
        $durations = BusinessProcess::getDurationOptions();
        $rtoLabel = $node['visible'] && $rto > 0 ? ($durations[$rto] ?? $rto . ' h') : '';
        $type = $node['visible'] && is_a($itemtype, CommonDBTM::class, true) ? (string) $itemtype::getTypeName(1) : '';

        return [
            'key'       => $key,
            'label'     => $node['label'],
            'short'     => LayeredLayout::shortLabel($node['label']),
            'url'       => $node['url'],
            'visible'   => $node['visible'],
            'layer'     => Layers::of($itemtype),
            'type'      => $type,
            'rto'       => $node['visible'] ? $rto : 0,
            'rto_label' => $rtoLabel,
            'rto_short' => LayeredLayout::shortLabel($rtoLabel, 16),
        ];
    }

    /**
     * Points de départ proposés quand aucun élément n'est choisi : processus métier (« de quoi
     * dépend-il ? ») et matériels réseau (« que touche leur panne ? »), dans les entités actives.
     *
     * @return array{processes: list<array<string, mixed>>, network: list<array<string, mixed>>}
     */
    public static function entryPoints(int $limit = 30): array
    {
        $dbu = new DbUtils();
        $out = ['processes' => [], 'network' => []];
        if (BusinessProcess::canView()) {
            $where = $dbu->getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true);
            foreach ((new BusinessProcess())->find($where, ['name'], $limit) as $row) {
                $out['processes'][] = self::describe(ImpactGraph::key(BusinessProcess::class, (int) $row['id']));
            }
        }
        if (NetworkEquipment::canView()) {
            $where = ['is_deleted' => 0, 'is_template' => 0]
                + $dbu->getEntitiesRestrictCriteria(NetworkEquipment::getTable(), '', '', true);
            foreach ((new NetworkEquipment())->find($where, ['name'], $limit) as $row) {
                $out['network'][] = self::describe(ImpactGraph::key('NetworkEquipment', (int) $row['id']));
            }
        }

        return $out;
    }
}
