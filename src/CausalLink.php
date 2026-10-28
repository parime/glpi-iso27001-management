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
use GlpiPlugin\Grcmanager\Compatibility\Base\CausalLinkBase;
use GlpiPlugin\Grcmanager\Services\Risk\CausalGraph;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #171 : effet domino. Un lien relie une cause à sa conséquence parmi les risques, les
 * risques fournisseurs et les incidents de sécurité (« Hameçonnage réussi » → « Compromission
 * de compte » → « Fuite de données »). Onglet sur chacun, et graphe d'ensemble (Cytoscape, déjà
 * fourni par GLPI pour son analyse d'impact).
 */
class CausalLink extends CausalLinkBase
{
    // Carte des dépendances et des points de défaillance : registre sensible, voir
    // Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /** Niveaux de causes et de conséquences affichés autour d'un élément. */
    private const TAB_DEPTH = 4;

    /** Éléments proposés par type dans la liste d'ajout. */
    private const MAX_CHOICES = 500;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_causallinks';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Domino effect', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-hierarchy-3';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/causalgraph.php';
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @return list<class-string<CommonDBTM>>
     */
    public static function getLinkableItemtypes(): array
    {
        return [
            Risk::class,
            SupplierRisk::class,
            PluginGrcmanagerSecurityIncident::class,
        ];
    }

    public static function key(string $itemtype, int $id): string
    {
        return $itemtype . ':' . $id;
    }

    /**
     * Tous les liens, sous forme de paires de clés cause → conséquence.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function allEdges(): array
    {
        global $DB;

        $edges = [];
        foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
            $edges[] = [
                self::key((string) $row['itemtype_cause'], (int) $row['items_id_cause']),
                self::key((string) $row['itemtype_effect'], (int) $row['items_id_effect']),
            ];
        }

        return $edges;
    }

    /**
     * Description d'un nœud pour le graphe ; un élément que la personne ne peut pas voir est
     * compté sans être nommé.
     *
     * @return array{key: string, label: string, kind: string, level: string, url: string, visible: bool}
     */
    public static function describe(string $key): array
    {
        [$itemtype, $id] = explode(':', $key, 2) + [1 => '0'];
        $node = [
            'key'     => $key,
            'label'   => __('Item not visible', 'grcmanager'),
            'kind'    => 'hidden',
            'level'   => '',
            'url'     => '',
            'visible' => false,
        ];
        if (!in_array($itemtype, self::getLinkableItemtypes(), true)) {
            return $node;
        }
        // can() contrôle le droit du type ET l'élément : canViewItem() seul vaut vrai pour les
        // registres sans entité (risques, risques fournisseurs).
        $item = new $itemtype();
        if (!$item->can((int) $id, READ)) {
            return $node;
        }
        $kinds = [
            Risk::class             => 'risk',
            SupplierRisk::class     => 'supplierrisk',
            PluginGrcmanagerSecurityIncident::class => 'incident',
        ];

        return [
            'key'     => $key,
            'label'   => (string) ($item->fields['title'] ?? $item->fields['name'] ?? ''),
            'kind'    => $kinds[$itemtype],
            'level'   => (string) ($item->fields['risk_level'] ?? ''),
            'url'     => $itemtype::getFormURLWithID((int) $id),
            'visible' => true,
        ];
    }

    /**
     * Éléments Cytoscape (nœuds et arêtes) d'un sous-graphe.
     *
     * @param array{nodes: list<string>, edges: list<array{0: string, 1: string}>} $graph
     * @return list<array{data: array<string, mixed>, classes?: string}>
     */
    public static function cytoscapeElements(array $graph, string $center = ''): array
    {
        $elements = [];
        foreach ($graph['nodes'] as $key) {
            $node = self::describe($key);
            $elements[] = [
                'data'    => ['id' => $key, 'label' => $node['label'], 'url' => $node['url']],
                'classes' => trim($node['kind'] . ' level-' . $node['level'] . ($key === $center ? ' center' : '')),
            ];
        }
        foreach ($graph['edges'] as [$cause, $effect]) {
            $elements[] = ['data' => ['id' => $cause . '>' . $effect, 'source' => $cause, 'target' => $effect]];
        }

        return $elements;
    }

    /**
     * Risques, risques fournisseurs et incidents visibles, pour la liste d'ajout d'un lien.
     *
     * @return array<string, array<string, string>> groupe => [clé => libellé]
     */
    public static function getChoices(string $exclude = ''): array
    {
        $choices = [];
        foreach (self::getLinkableItemtypes() as $itemtype) {
            if (!$itemtype::canView()) {
                continue;
            }
            $field = $itemtype === PluginGrcmanagerSecurityIncident::class ? 'name' : 'title';
            $item = new $itemtype();
            $group = [];
            foreach ($item->find([], [$field . ' ASC'], self::MAX_CHOICES) as $row) {
                $key = self::key($itemtype, (int) $row['id']);
                if ($key !== $exclude && $item->getFromDB((int) $row['id']) && $item->canViewItem()) {
                    // Le numéro distingue deux éléments de même titre.
                    $group[$key] = sprintf('%s (#%d)', $row[$field], $row['id']);
                }
            }
            if ($group !== []) {
                $choices[$itemtype::getTypeName(2)] = $group;
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $cause = (string) ($input['cause'] ?? '');
        $effect = (string) ($input['effect'] ?? '');
        foreach (['cause' => $cause, 'effect' => $effect] as $side => $key) {
            [$itemtype, $id] = explode(':', $key, 2) + [1 => '0'];
            if (!in_array($itemtype, self::getLinkableItemtypes(), true)) {
                return false;
            }
            $item = new $itemtype();
            if (!$item->getFromDB((int) $id) || !$item->canViewItem()) {
                return false;
            }
            $input['itemtype_' . $side] = $itemtype;
            $input['items_id_' . $side] = (int) $id;
        }
        if (in_array([$cause, $effect], self::allEdges(), true)) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'This link already exists.',
                'grcmanager'
            )), false, WARNING);
            return false;
        }
        if (CausalGraph::wouldCreateCycle(self::allEdges(), $cause, $effect)) {
            Session::addMessageAfterRedirect(
                htmlescape(__(
                    'This link would close a loop: the consequence already leads to the cause.',
                    'grcmanager'
                )),
                false,
                ERROR
            );
            return false;
        }
        unset($input['cause'], $input['effect']);

        return $input;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem()
            || !in_array($item::class, self::getLinkableItemtypes(), true) || !self::canView()
        ) {
            return '';
        }
        $key = self::key($item::class, $item->getID());
        $count = count(array_filter(
            self::allEdges(),
            static fn (array $edge): bool => $edge[0] === $key || $edge[1] === $key
        ));

        return self::createTabEntry(self::getTypeName(), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $DB, $CFG_GLPI;

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof CommonDBTM || !self::canView()) {
            return false;
        }
        $key = self::key($item::class, $item->getID());
        $graph = CausalGraph::around(self::allEdges(), $key, self::TAB_DEPTH);
        $direct = ['causes' => [], 'effects' => []];
        foreach (
            $DB->request(['FROM' => self::getTable(), 'WHERE' => ['OR' => [
                ['itemtype_cause' => $item::class, 'items_id_cause' => $item->getID()],
                ['itemtype_effect' => $item::class, 'items_id_effect' => $item->getID()],
            ]]]) as $row
        ) {
            $isCause = $row['itemtype_cause'] === $item::class && (int) $row['items_id_cause'] === $item->getID();
            $other = $isCause
                ? self::key((string) $row['itemtype_effect'], (int) $row['items_id_effect'])
                : self::key((string) $row['itemtype_cause'], (int) $row['items_id_cause']);
            $direct[$isCause ? 'effects' : 'causes'][] = ['id' => (int) $row['id'], 'node' => self::describe($other)];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/causal_tab.html.twig', [
            'item_key'  => $key,
            'elements'  => self::cytoscapeElements($graph, $key),
            'direct'    => $direct,
            'choices'   => self::canCreate() ? self::getChoices($key) : [],
            'can_edit'  => self::canCreate(),
            'form_url'  => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/causallink.form.php',
            'graph_url' => self::getSearchURL(),
        ]);

        return true;
    }
}
