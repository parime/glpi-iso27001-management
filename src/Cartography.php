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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Feature\CartographySourcesProvider;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\EdgeSource;
use GlpiPlugin\Grcmanager\Services\Cartography\LayeredLayout;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\DockerSource;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\FlowSource;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\InventorySource;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\RelationSource;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use Html;
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
     * Issue #1082, lot 2 : fiches qui portent l'onglet « Cartographie du SI ».
     *
     * @return list<string>
     */
    public static function tabItemtypes(): array
    {
        return ['Computer', 'NetworkEquipment', BusinessProcess::class];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::canView()
            || !in_array($item::class, self::tabItemtypes(), true)
        ) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof CommonDBTM || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        // Une panne d'équipement se lit vers le haut, un processus vers le bas.
        $direction = $item instanceof BusinessProcess ? DependencyWalker::DOWN : DependencyWalker::UP;
        TemplateRenderer::getInstance()->display('@grcmanager/_cartography_map.html.twig', [
            'map'       => self::map($item, $direction, ['flows' => true]),
            'max_nodes' => DependencyWalker::MAX_NODES,
            'in_tab'    => true,
        ]);

        return true;
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
     * @param array{flows?: bool, layers?: list<int>} $options flows : calque des flux inter-applicatifs ;
     *     layers : couches affichées (toutes par défaut), le parcours les traverse toutes
     * @return array<string, mixed>
     */
    public static function map(CommonDBTM $item, string $direction, array $options = []): array
    {
        $flows = (bool) ($options['flows'] ?? false);
        $shown = array_values(array_intersect(Layers::ALL, $options['layers'] ?? Layers::ALL));
        $shown = $shown === [] ? Layers::ALL : $shown;
        $sources = self::sources();
        if ($flows) {
            $sources[] = FlowSource::class;
        }
        $down = $direction === DependencyWalker::DOWN;
        $direction = $down ? DependencyWalker::DOWN : DependencyWalker::UP;
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
        $walk = DependencyWalker::walk($start, $direction, $next);
        $startTypes = self::startItemtypes();
        $state = ['direction' => $direction, 'flows' => $flows, 'layers' => $shown];

        $nodes = [];
        $rto = [];
        foreach ($walk['nodes'] as $key => $depth) {
            [$itemtype, $itemsId] = ImpactGraph::split($key);
            $node = self::describe($key) + ['depth' => $depth, 'start' => $key === $start];
            // Issue #1082, lot 2 : repartir de cette case en un clic.
            $recenter = $node['visible'] && $key !== $start && in_array($itemtype, $startTypes, true);
            $node['recenter'] = $recenter ? self::mapUrl($itemtype, $itemsId, $state) : '';
            $nodes[$key] = $node;
            if ($node['layer'] === Layers::BUSINESS && $key !== $start) {
                $rto[$key] = $node['rto'];
            }
        }
        $layers = DependencyWalker::byLayer(
            $walk['nodes'],
            static fn (string $key): int => $nodes[$key]['layer']
        );
        $drawnLayers = [];
        foreach (Layers::ALL as $layer) {
            $drawnLayers[$layer] = in_array($layer, $shown, true) ? $layers[$layer] : [];
        }
        $layout = LayeredLayout::place($drawnLayers, $shown);

        // Flux de la matrice entre deux éléments visibles : tracés en pointillés et listés.
        $flowList = [];
        $flowPairs = [];
        if ($flows) {
            foreach (FlowSource::between(array_keys($walk['nodes'])) as $flow) {
                if ($nodes[$flow['supporter']]['visible'] && $nodes[$flow['dependent']]['visible']) {
                    $flowPairs[$flow['supporter'] . '>' . $flow['dependent']] = true;
                    $flowList[] = $flow + ['from' => $nodes[$flow['dependent']], 'to' => $nodes[$flow['supporter']]];
                }
            }
        }
        $edges = [];
        foreach ($walk['edges'] as [$supporter, $dependent]) {
            if (isset($layout['nodes'][$supporter], $layout['nodes'][$dependent])) {
                $edges[] = [
                    'from' => $layout['nodes'][$supporter],
                    'to'   => $layout['nodes'][$dependent],
                    'flow' => isset($flowPairs[$supporter . '>' . $dependent]),
                ];
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
                'shown' => in_array($layer, $shown, true),
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
        [$startType, $startId] = ImpactGraph::split($start);

        return [
            'start'       => $nodes[$start],
            'start_layer' => $labels[$nodes[$start]['layer']],
            'direction'   => $direction,
            'flows'       => $flows,
            'shown'       => $shown,
            'count'       => count($walk['nodes']) - 1,
            'truncated'   => $walk['truncated'],
            'max_nodes'   => DependencyWalker::MAX_NODES,
            'processes'   => $processes,
            'layers'      => $byLayer,
            'flow_list'   => $flowList,
            'url'         => self::mapUrl($startType, $startId, $state),
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
     * Adresse de la carte d'un élément, avec le sens, le calque des flux et les couches affichées.
     *
     * @param array{direction: string, flows: bool, layers: list<int>} $state
     */
    public static function mapUrl(string $itemtype, int $itemsId, array $state): string
    {
        $query = ['itemtype' => $itemtype, 'items_id' => $itemsId, 'direction' => $state['direction']];
        if ($state['flows']) {
            $query['flows'] = 1;
        }
        if (count($state['layers']) < count(Layers::ALL)) {
            $query['layers'] = $state['layers'];
        }

        return self::getSearchURL() . '?' . http_build_query($query);
    }

    /**
     * Issue #1082, lot 2 : la carte en tableau, pour l'export CSV (PCA/PRA, documentation d'audit).
     *
     * @param array<string, mixed> $map résultat de map()
     * @return list<list<string>>
     */
    public static function exportRows(array $map): array
    {
        $rows = [[
            __('Layer', 'grcmanager'),
            __('Item', 'grcmanager'),
            __('Type', 'grcmanager'),
            __('Distance', 'grcmanager'),
            'RTO',
        ]];
        foreach ($map['layers'] as $layer) {
            if (!$layer['shown']) {
                continue;
            }
            foreach ($layer['nodes'] as $node) {
                $rows[] = [
                    (string) $layer['label'],
                    (string) $node['name'],
                    (string) $node['type'],
                    (string) $node['depth'],
                    (string) $node['rto_label'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Issue #1082, lot 2 : la carte en PDF (HTML pour PdfRenderer).
     *
     * @param array<string, mixed> $map résultat de map()
     */
    public static function pdfHtml(array $map): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/cartography_report.html.twig', [
            'report_title'       => sprintf('%s : %s', self::getTypeName(), $map['start']['label']),
            'generated_at_label' => sprintf(
                __('Generated on %1$s by %2$s', 'grcmanager'),
                Html::convDateTime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                getUserName((int) Session::getLoginUserID())
            ),
            'map'                => $map,
        ]);
    }

    /**
     * @return array{key: string, label: string, name: string, url: string, visible: bool, layer: int,
     *     type: string, rto: int, rto_label: string, short: string, type_short: string, rto_short: string}
     */
    public static function describe(string $key): array
    {
        [$itemtype, $itemsId] = ImpactGraph::split($key);
        $node = [
            'key'       => $key,
            'label'     => __('Item not visible', 'grcmanager'),
            'name'      => __('Item not visible', 'grcmanager'),
            'url'       => '',
            'visible'   => false,
            'layer'     => Layers::of($itemtype),
            'type'      => '',
            'rto'       => 0,
            'rto_label' => '',
        ];
        $item = is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null;
        if ($item !== null && $item->can($itemsId, READ)) {
            $type = (string) $itemtype::getTypeName(1);
            $name = (string) $item->getName();
            $rto = $item instanceof BusinessProcess
                ? (int) $item->fields['rto_hours']
                : (int) (AssetClassification::getByItem($itemtype, $itemsId)['rto_hours'] ?? 0);
            $durations = BusinessProcess::getDurationOptions();
            $node = [
                'label'     => $type . ' ' . $name,
                'name'      => $name,
                'url'       => $item->getLinkURL(),
                'visible'   => true,
                'type'      => $type,
                'rto'       => $rto,
                'rto_label' => $rto > 0 ? ($durations[$rto] ?? $rto . ' h') : '',
            ] + $node;
        }

        // Le schéma n'a la place que pour le nom : la couche dit déjà le type.
        return $node + [
            'short'      => LayeredLayout::shortLabel($node['name']),
            'type_short' => LayeredLayout::shortLabel($node['type']),
            'rto_short'  => LayeredLayout::shortLabel($node['rto_label'], 16),
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
        if ((bool) BusinessProcess::canView()) {
            $where = $dbu->getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true);
            foreach ((new BusinessProcess())->find($where, ['name'], $limit) as $row) {
                $out['processes'][] = self::describe(ImpactGraph::key(BusinessProcess::class, (int) $row['id']));
            }
        }
        if ((bool) NetworkEquipment::canView()) {
            $where = ['is_deleted' => 0, 'is_template' => 0]
                + $dbu->getEntitiesRestrictCriteria(NetworkEquipment::getTable(), '', '', true);
            foreach ((new NetworkEquipment())->find($where, ['name'], $limit) as $row) {
                $out['network'][] = self::describe(ImpactGraph::key('NetworkEquipment', (int) $row['id']));
            }
        }

        return $out;
    }
}
