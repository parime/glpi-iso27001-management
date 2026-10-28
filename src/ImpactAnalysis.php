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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use GlpiPlugin\Grcmanager\Services\Impact\RecoveryObjectives;
use GlpiPlugin\Grcmanager\AssetClassification;
use Session;

/**
 * Issue #177 : analyse d'impact d'une panne (A.5.29, A.5.30, A.8.14), à partir de l'analyse
 * d'impact native de GLPI (qui dépend de quoi).
 *
 * - onglet « Impact d'une panne » sur chaque actif : tout ce qui en dépend, directement ou non,
 *   avec la disponibilité classée de chaque élément et les risques liés ;
 * - points de défaillance uniques : un élément dont la disponibilité est classée « haute » et qui
 *   ne dépend que d'une seule source. Le risque correspondant est proposé, jamais créé
 *   automatiquement.
 *
 * Un élément que la personne ne peut pas voir dans GLPI est compté sans être nommé.
 */
class ImpactAnalysis extends CommonGLPI
{
    // Carte des dépendances et des points de défaillance : registre sensible, voir
    // Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public static function getTypeName($nb = 0)
    {
        return __('Single points of failure', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-sitemap';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/impactanalysis.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Risques » (Menu::sections()).
        return [
            'title' => __('Impact analysis', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function loadEdges(): array
    {
        global $DB;

        $edges = [];
        foreach ($DB->request(['FROM' => 'glpi_impactrelations']) as $row) {
            $edges[] = [
                ImpactGraph::key((string) $row['itemtype_source'], (int) $row['items_id_source']),
                ImpactGraph::key((string) $row['itemtype_impacted'], (int) $row['items_id_impacted']),
            ];
        }

        return $edges;
    }

    /**
     * Éléments dont la disponibilité est classée « haute ».
     *
     * @return list<string>
     */
    public static function criticalKeys(): array
    {
        global $DB;

        $keys = [];
        $where = ['availability' => ClassificationLevels::HIGH];
        foreach ($DB->request(['FROM' => AssetClassification::getTable(), 'WHERE' => $where]) as $row) {
            $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
        }

        return $keys;
    }

    /**
     * @return array{label: string, url: string, visible: bool, availability: string, rto: int, rpo: int}
     */
    public static function describe(string $key): array
    {
        [$itemtype, $itemsId] = ImpactGraph::split($key);
        $classification = AssetClassification::getByItem($itemtype, $itemsId);
        $base = [
            'availability' => (string) ($classification['availability'] ?? ''),
            // Issue #177, phase 2 : objectifs de reprise, en heures (0 : non définis).
            'rto'          => (int) ($classification['rto_hours'] ?? 0),
            'rpo'          => (int) ($classification['rpo_hours'] ?? 0),
        ];
        if (!is_a($itemtype, CommonDBTM::class, true)) {
            return ['label' => $itemtype, 'url' => '', 'visible' => false] + $base;
        }
        $item = new $itemtype();
        if (!$item->can($itemsId, READ)) {
            return ['label' => __('Item not visible', 'grcmanager'), 'url' => '', 'visible' => false] + $base;
        }

        return [
            'label'   => $itemtype::getTypeName(1) . ' ' . $item->getName(),
            'url'     => $item->getLinkURL(),
            'visible' => true,
        ] + $base;
    }

    /**
     * Issue #177, phase 2 : RTO de chaque élément classé.
     *
     * @return array<string, int>
     */
    public static function rtoByKey(): array
    {
        global $DB;

        $rto = [];
        foreach (
            $DB->request([
                'SELECT' => ['itemtype', 'items_id', 'rto_hours'],
                'FROM'   => AssetClassification::getTable(),
                'WHERE'  => ['rto_hours' => ['>', 0]],
            ]) as $row
        ) {
            $rto[ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id'])] = (int) $row['rto_hours'];
        }

        return $rto;
    }

    /**
     * Issue #177, phase 2 : éléments Cytoscape de l'impact d'une panne (l'élément et ce qui en
     * dépend), libellés avec leur RTO.
     *
     * @param list<array{0: string, 1: string}> $edges
     * @param array<string, int> $nodes clé => profondeur
     * @return list<array{data: array<string, mixed>, classes?: string}>
     */
    public static function graphElements(array $edges, array $nodes, string $start): array
    {
        $labels = AssetClassification::getRecoveryOptions();
        $elements = [];
        foreach (array_keys($nodes) as $key) {
            $node = self::describe($key);
            $rto = $node['rto'] > 0 ? "\nRTO " . ($labels[$node['rto']] ?? $node['rto'] . ' h') : '';
            $label = $node['label'] . $rto;
            $elements[] = [
                'data'    => ['id' => $key, 'label' => $label, 'url' => $node['url']],
                'classes' => trim('asset availability-' . $node['availability'] . ($key === $start ? ' center' : '')),
            ];
        }
        foreach ($edges as [$source, $impacted]) {
            if (isset($nodes[$source], $nodes[$impacted])) {
                $elements[] = ['data' => [
                    'id'     => $source . '>' . $impacted,
                    'source' => $source,
                    'target' => $impacted,
                ]];
            }
        }

        return $elements;
    }

    /**
     * Issue #177, phase 2 : export pour le plan de continuité (CSV) — actifs classés en
     * disponibilité ou dotés d'objectifs de reprise, avec leurs dépendances.
     *
     * @return list<list<string>>
     */
    public static function continuityRows(): array
    {
        global $DB;

        $edges = self::loadEdges();
        $sources = [];
        foreach ($edges as [$source, $impacted]) {
            $sources[$impacted][] = $source;
        }
        $spof = [];
        foreach (ImpactGraph::singlePointsOfFailure($edges, self::criticalKeys()) as $point) {
            $spof[$point['source']][] = $point['critical'];
        }
        $levels = AssetClassification::getLevels();
        $labels = AssetClassification::getRecoveryOptions();
        $rows = [[
            __('Item', 'grcmanager'),
            __('Availability', 'grcmanager'),
            'RTO',
            'RPO',
            __('Depends on', 'grcmanager'),
            __('Items affected by a failure', 'grcmanager'),
            __('Single point of failure for', 'grcmanager'),
            __('Linked risks', 'grcmanager'),
        ]];
        foreach (
            $DB->request([
                'FROM'  => AssetClassification::getTable(),
                'WHERE' => ['OR' => ['availability' => ['<>', ''], 'rto_hours' => ['>', 0], 'rpo_hours' => ['>', 0]]],
            ]) as $row
        ) {
            $key = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
            $node = self::describe($key);
            if (!$node['visible']) {
                continue;
            }
            $names = static fn (array $keys): string => implode(', ', array_map(
                static fn (string $k): string => self::describe($k)['label'],
                $keys
            ));
            $rows[] = [
                $node['label'],
                $levels[$node['availability']] ?? '',
                $labels[$node['rto']] ?? '',
                $labels[$node['rpo']] ?? '',
                $names($sources[$key] ?? []),
                (string) count(ImpactGraph::downstream($edges, $key)),
                $names($spof[$key] ?? []),
                (string) count(Risk::getRisksLinkedToItem(
                    (string) $row['itemtype'],
                    (int) $row['items_id']
                )),
            ];
        }

        return $rows;
    }

    /**
     * @return array{downstream: list<array<string, mixed>>, risks: list<array<string, mixed>>,
     *     spof_for: list<array<string, mixed>>, has_relations: bool, recovery: list<array<string, mixed>>,
     *     graph: list<array<string, mixed>>}
     */
    public static function analyze(CommonDBTM $item): array
    {
        $edges = self::loadEdges();
        $start = ImpactGraph::key($item::class, (int) $item->getID());
        $downstream = [];
        $risks = [];
        $nodes = [$start => 0] + ImpactGraph::downstream($edges, $start);
        foreach ($nodes as $key => $depth) {
            [$itemtype, $itemsId] = ImpactGraph::split($key);
            foreach (Risk::getRisksLinkedToItem($itemtype, $itemsId) as $risk) {
                $url = Risk::getFormURLWithID((int) $risk['id']);
                $risks[(int) $risk['id']] = $risk + ['url' => $url];
            }
            if ($depth > 0) {
                $downstream[] = self::describe($key) + ['depth' => $depth];
            }
        }
        usort(
            $downstream,
            static fn (array $a, array $b): int => [$a['depth'], $a['label']] <=> [$b['depth'], $b['label']]
        );

        $spofFor = [];
        foreach (ImpactGraph::singlePointsOfFailure($edges, self::criticalKeys()) as $point) {
            if ($point['source'] === $start) {
                $spofFor[] = self::describe($point['critical']);
            }
        }
        $hasRelations = false;
        foreach ($edges as [$source, $impacted]) {
            if ($source === $start || $impacted === $start) {
                $hasRelations = true;
                break;
            }
        }

        // Issue #177, phase 2 : objectifs de reprise incohérents autour de cet élément.
        $recovery = [];
        foreach (RecoveryObjectives::inconsistencies($edges, self::rtoByKey()) as $issue) {
            if ($issue['source'] === $start || $issue['impacted'] === $start) {
                $recovery[] = $issue + [
                    'source_node'   => self::describe($issue['source']),
                    'impacted_node' => self::describe($issue['impacted']),
                ];
            }
        }

        return [
            'downstream'    => $downstream,
            'risks'         => array_values($risks),
            'spof_for'      => $spofFor,
            'has_relations' => $hasRelations,
            'recovery'      => $recovery,
            'graph'         => self::graphElements($edges, $nodes, $start),
        ];
    }

    /**
     * Points de défaillance uniques de toute l'instance, regroupés par source.
     *
     * @return list<array{source: array<string, mixed>, critical: list<array<string, mixed>>, key: string}>
     */
    public static function allSinglePoints(): array
    {
        $bySource = [];
        foreach (ImpactGraph::singlePointsOfFailure(self::loadEdges(), self::criticalKeys()) as $point) {
            $bySource[$point['source']][] = self::describe($point['critical']);
        }
        $result = [];
        foreach ($bySource as $source => $critical) {
            $result[] = ['source' => self::describe($source), 'critical' => $critical, 'key' => $source];
        }

        return $result;
    }

    /**
     * Crée le risque « point de défaillance unique » d'un actif, lié à lui, à évaluer. Ne recrée
     * pas un risque déjà ouvert du même titre pour cet actif.
     */
    public static function createSinglePointRisk(CommonDBTM $item, array $critical): int
    {
        $title = sprintf(__('Single point of failure: %s', 'grcmanager'), $item->getName());
        foreach (Risk::getRisksLinkedToItem($item::class, (int) $item->getID()) as $risk) {
            if ($risk['title'] === $title) {
                return 0;
            }
        }
        $names = array_map(static fn (array $c): string => $c['label'], $critical);
        $description = __('These high-availability items depend only on it:', 'grcmanager')
            . "\n- " . implode("\n- ", $names) . "\n\n"
            . __(
                'Plan for redundancy (second device, second power supply, second link) or accept the risk.',
                'grcmanager'
            );
        $field = 'linked_items_' . str_replace('\\', '_', $item::class);

        return (int) (new Risk())->add([
            'title'       => $title,
            'description' => $description,
            'category'    => 'technical',
            'probability' => 'possible',
            'impact'      => 'high',
            'status'      => 'identified',
            'users_id'    => (int) Session::getLoginUserID(),
            $field        => [(int) $item->getID()],
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::canView()
            || !in_array($item::class, Risk::getLinkableItemtypes(), true)
        ) {
            return '';
        }

        return self::createTabEntry(__('Failure impact', 'grcmanager'));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof CommonDBTM || !self::canView()) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/impact_tab.html.twig', [
            'analysis'   => self::analyze($item),
            'levels'     => AssetClassification::getLevels(),
            'action'     => self::getSearchURL(),
            'itemtype'   => $item::class,
            'items_id'   => $item->getID(),
            'can_create' => Session::haveRight(Risk::$rightname, CREATE),
            'impact_url' => $item->getLinkURL() . '&forcetab=Impact$1',
            'recovery_labels' => AssetClassification::getRecoveryOptions(),
        ]);

        return true;
    }
}
