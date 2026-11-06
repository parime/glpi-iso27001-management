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

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessProcessLinkBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\ProcessDependencyRules;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use Log;
use Session;

/**
 * Issue #377 : dépendance entre deux processus métier (la paie a besoin de la gestion des temps).
 *
 * Le lien appartient au processus qui dépend de l'autre : l'ajouter ou le retirer exige de pouvoir
 * modifier ce processus (droit de la continuité et entité), et de pouvoir lire le processus amont.
 * Un processus que la personne ne peut pas lire n'est jamais nommé : il est seulement compté.
 * Un lien qui fermerait une boucle est refusé.
 *
 * Onglet « Processus liés » : processus dont il dépend (directement ou non), avec leurs écarts face
 * à ses objectifs, processus qui en dépendent, et objectifs que ceux-ci lui imposent.
 */
class BusinessProcessLink extends BusinessProcessLinkBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const UPSTREAM_FIELD = 'plugin_grcmanager_businessprocesses_id_upstream';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessprocesses_businessprocesses';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Linked processes', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-split-2';
    }

    /**
     * Toutes les arêtes [amont, aval].
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function loadEdges(): array
    {
        global $DB;

        $edges = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_businessprocesses_id', self::UPSTREAM_FIELD],
                'FROM'   => self::getTable(),
            ]) as $row
        ) {
            $edges[] = [(int) $row[self::UPSTREAM_FIELD], (int) $row['plugin_grcmanager_businessprocesses_id']];
        }

        return $edges;
    }

    /**
     * Processus amont et aval de $process, limités à ceux que la personne peut lire, écarts face à
     * ses objectifs et objectifs exigés par les processus qui en dépendent.
     *
     * @return array{upstream: list<array<string, mixed>>, downstream: list<array<string, mixed>>,
     *     required: array{rto: int, rto_from: string, rpo: int, rpo_from: string}, hidden: int}
     */
    public static function analyze(BusinessProcess $process): array
    {
        $processId = (int) $process->getID();
        $edges = self::loadEdges();
        $stringEdges = array_map(static fn (array $e): array => [(string) $e[0], (string) $e[1]], $edges);
        $direct = [];
        foreach ((new self())->find(['plugin_grcmanager_businessprocesses_id' => $processId]) as $row) {
            $direct[(int) $row[self::UPSTREAM_FIELD]] = $row;
        }

        $hidden = 0;
        $upstream = [];
        foreach (ImpactGraph::upstream($stringEdges, (string) $processId) as $id => $depth) {
            $node = self::describe((int) $id);
            if ($node === null) {
                $hidden++;
                continue;
            }
            $upstream[(int) $id] = $node + [
                'depth'   => $depth,
                'link_id' => (int) ($direct[(int) $id]['id'] ?? 0),
                'comment' => (string) ($direct[(int) $id]['comment'] ?? ''),
                'issues'  => [],
            ];
        }
        $issues = ProcessDependencyRules::upstreamIssues(
            (int) ($process->fields['rto_hours'] ?? 0),
            (int) ($process->fields['rpo_hours'] ?? 0),
            array_map(static fn (array $n): array => ['rto' => $n['rto'], 'rpo' => $n['rpo']], $upstream)
        );
        foreach ($issues as $id => $codes) {
            $upstream[$id]['issues'] = $codes;
        }

        $downstream = [];
        foreach (ImpactGraph::downstream($stringEdges, (string) $processId) as $id => $depth) {
            $node = self::describe((int) $id);
            if ($node === null) {
                $hidden++;
                continue;
            }
            $downstream[(int) $id] = $node + ['depth' => $depth];
        }
        $required = ProcessDependencyRules::requiredObjectives(
            array_map(static fn (array $n): array => ['rto' => $n['rto'], 'rpo' => $n['rpo']], $downstream)
        );

        $sort = static fn (array $a, array $b): int => [$a['depth'], $a['name']] <=> [$b['depth'], $b['name']];
        uasort($upstream, $sort);
        uasort($downstream, $sort);

        return [
            'upstream'   => array_values($upstream),
            'downstream' => array_values($downstream),
            'required'   => [
                'rto'      => $required['rto'],
                'rto_from' => $required['rto_from'] > 0 ? (string) $downstream[$required['rto_from']]['name'] : '',
                'rpo'      => $required['rpo'],
                'rpo_from' => $required['rpo_from'] > 0 ? (string) $downstream[$required['rpo_from']]['name'] : '',
            ],
            'hidden'     => $hidden,
        ];
    }

    /**
     * Processus lisible par la personne, ou null.
     *
     * @return array{id: int, name: string, url: string, rto: int, rpo: int, mtpd: int}|null
     */
    private static function describe(int $id): ?array
    {
        $process = new BusinessProcess();
        if (!$process->getFromDB($id) || !$process->canViewItem()) {
            return null;
        }

        return [
            'id'   => $id,
            'name' => (string) $process->fields['name'],
            'url'  => $process->getLinkURL(),
            'rto'  => (int) $process->fields['rto_hours'],
            'rpo'  => (int) $process->fields['rpo_hours'],
            'mtpd' => (int) $process->fields['mtpd_hours'],
        ];
    }

    /**
     * Issue #377, quatrième étape : carte des dépendances d'un processus (éléments Cytoscape, rendus
     * par le gabarit du graphe d'impact) : processus amont et aval, éléments dont il dépend
     * (directement ou par l'analyse d'impact de GLPI), avec leurs écarts. Seuls les processus et les
     * éléments que la personne peut lire sont des nœuds ; les autres sont seulement comptés, et leurs
     * liens ne sont pas tracés.
     *
     * @return array{elements: list<array{data: array<string, mixed>, classes?: string}>, hidden: int}
     */
    public static function mapElements(BusinessProcess $process): array
    {
        $durations = BusinessProcess::getDurationOptions();
        $links = self::analyze($process);
        $items = BusinessProcess::analyze($process);
        $processKey = static fn (int $id): string => ImpactGraph::key(BusinessProcess::class, $id);
        $label = static fn (string $name, int $rto): string => $name
            . ($rto > 0 ? "\nRTO " . ($durations[$rto] ?? $rto . ' h') : '');

        $center = $processKey((int) $process->getID());
        $nodes = [$center => [
            'data'    => [
                'id'    => $center,
                'label' => $label((string) $process->fields['name'], (int) $process->fields['rto_hours']),
                'url'   => '',
            ],
            'classes' => 'process center',
        ]];
        foreach (array_merge($links['upstream'], $links['downstream']) as $node) {
            $nodes[$processKey((int) $node['id'])] = [
                'data'    => [
                    'id'    => $processKey((int) $node['id']),
                    'label' => $label((string) $node['name'], (int) $node['rto']),
                    'url'   => (string) $node['url'],
                ],
                'classes' => 'process' . (($node['issues'] ?? []) !== [] ? ' late' : ''),
            ];
        }
        foreach ($items['dependencies'] as $dependency) {
            $nodes[$dependency['key']] = [
                'data'    => [
                    'id'    => $dependency['key'],
                    'label' => $label((string) $dependency['label'], (int) $dependency['rto']),
                    'url'   => (string) $dependency['url'],
                ],
                'classes' => 'asset availability-' . $dependency['availability']
                    . ($dependency['issues'] !== [] ? ' late' : ''),
            ];
        }

        // Arêtes « la panne de la source affecte la cible », entre nœuds lisibles seulement.
        $edges = [];
        foreach (self::loadEdges() as [$upstream, $downstream]) {
            $edges[] = [$processKey($upstream), $processKey($downstream)];
        }
        foreach ($items['dependencies'] as $dependency) {
            if ($dependency['depth'] === 0) {
                $edges[] = [$dependency['key'], $center];
            }
        }
        $elements = array_values($nodes);
        foreach (array_merge($edges, ImpactAnalysis::loadEdges()) as [$source, $target]) {
            if ($source !== $target && isset($nodes[$source], $nodes[$target])) {
                $elements[] = ['data' => ['id' => $source . '>' . $target, 'source' => $source, 'target' => $target]];
            }
        }

        return ['elements' => $elements, 'hidden' => (int) $links['hidden'] + (int) $items['hidden']];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !BusinessProcess::canView()) {
            return '';
        }

        return [
            1 => self::createTabEntry(
                self::getTypeName(Session::getPluralNumber()),
                countElementsInTable(self::getTable(), ['plugin_grcmanager_businessprocesses_id' => $item->getID()]),
                $item::class,
                self::getIcon()
            ),
            // Issue #377, quatrième étape : carte des dépendances.
            2 => self::createTabEntry(__('Dependency map', 'grcmanager'), 0, $item::class, 'ti ti-topology-star-3'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (
            !$item instanceof BusinessProcess || $item->isNewItem() || !BusinessProcess::canView()
            || !$item->canViewItem()
        ) {
            return false;
        }
        if ((int) $tabnum === 2) {
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_map.html.twig', [
                'map' => self::mapElements($item),
            ]);

            return true;
        }
        $analysis = self::analyze($item);
        $used = array_merge([(int) $item->getID()], array_column(
            array_filter($analysis['upstream'], static fn (array $n): bool => $n['link_id'] > 0),
            'id'
        ));
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_links.html.twig', [
            'item'      => $item,
            'analysis'  => $analysis,
            'issues'    => BusinessProcess::getIssueLabels(),
            'durations' => BusinessProcess::getDurationOptions(),
            'can_edit'  => $item->canUpdateItem(),
            'form_url'  => self::getFormURL(),
            'used'      => $used,
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $processId = (int) ($input['plugin_grcmanager_businessprocesses_id'] ?? 0);
        $upstreamId = (int) ($input[self::UPSTREAM_FIELD] ?? 0);
        $process = new BusinessProcess();
        if (!$process->getFromDB($processId) || !$process->canUpdateItem()) {
            return false;
        }
        $upstream = new BusinessProcess();
        if ($upstreamId <= 0 || !$upstream->getFromDB($upstreamId) || !$upstream->canViewItem()) {
            Session::addMessageAfterRedirect(htmlescape(__('Choose a process to link.', 'grcmanager')), false, ERROR);

            return false;
        }
        if (ProcessDependencyRules::wouldCreateCycle(self::loadEdges(), $processId, $upstreamId)) {
            Session::addMessageAfterRedirect(
                htmlescape(__(
                    'This link would create a loop: the chosen process already depends on this one.',
                    'grcmanager'
                )),
                false,
                ERROR
            );

            return false;
        }
        $existing = ['plugin_grcmanager_businessprocesses_id' => $processId, self::UPSTREAM_FIELD => $upstreamId];
        if (countElementsInTable(self::getTable(), $existing) > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('This process is already linked.', 'grcmanager')),
                false,
                WARNING
            );

            return false;
        }

        return $existing + ['comment' => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255)];
    }

    public function prepareInputForUpdate($input)
    {
        // Seul le rôle se modifie : les deux processus restent ceux du lien.
        return [
            'id'      => (int) ($input['id'] ?? 0),
            'comment' => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255),
        ];
    }

    public function post_addItem()
    {
        $this->history(Log::HISTORY_ADD_SUBITEM);
    }

    public function post_purgeItem()
    {
        $this->history(Log::HISTORY_DELETE_SUBITEM);
    }

    private function history(int $action): void
    {
        $label = sprintf('%s #%d', BusinessProcess::getTypeName(1), (int) $this->fields[self::UPSTREAM_FIELD]);
        Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            $action === Log::HISTORY_ADD_SUBITEM ? [0, '', $label] : [0, $label, ''],
            self::class,
            $action
        );
    }
}
