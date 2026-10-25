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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;

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
class PluginGrcmanagerImpactAnalysis extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Points de défaillance uniques', 'grcmanager');
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

        return ['title' => self::getTypeName(), 'page' => self::getSearchURL(false), 'icon' => self::getIcon()];
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
        foreach ($DB->request(['FROM' => PluginGrcmanagerAssetClassification::getTable(), 'WHERE' => $where]) as $row) {
            $keys[] = ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id']);
        }

        return $keys;
    }

    /**
     * @return array{label: string, url: string, visible: bool, availability: string}
     */
    public static function describe(string $key): array
    {
        [$itemtype, $itemsId] = ImpactGraph::split($key);
        $classification = PluginGrcmanagerAssetClassification::getByItem($itemtype, $itemsId);
        $availability = (string) ($classification['availability'] ?? '');
        if (!is_a($itemtype, CommonDBTM::class, true)) {
            return ['label' => $itemtype, 'url' => '', 'visible' => false, 'availability' => $availability];
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return [
                'label'        => __('Élément non visible', 'grcmanager'),
                'url'          => '',
                'visible'      => false,
                'availability' => $availability,
            ];
        }

        return [
            'label'        => $itemtype::getTypeName(1) . ' ' . $item->getName(),
            'url'          => $item->getLinkURL(),
            'visible'      => true,
            'availability' => $availability,
        ];
    }

    /**
     * @return array{downstream: list<array<string, mixed>>, risks: list<array<string, mixed>>,
     *     spof_for: list<array<string, mixed>>, has_relations: bool}
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
            foreach (PluginGrcmanagerRisk::getRisksLinkedToItem($itemtype, $itemsId) as $risk) {
                $url = PluginGrcmanagerRisk::getFormURLWithID((int) $risk['id']);
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

        return [
            'downstream'    => $downstream,
            'risks'         => array_values($risks),
            'spof_for'      => $spofFor,
            'has_relations' => $hasRelations,
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
        $title = sprintf(__('Point de défaillance unique : %s', 'grcmanager'), $item->getName());
        foreach (PluginGrcmanagerRisk::getRisksLinkedToItem($item::class, (int) $item->getID()) as $risk) {
            if ($risk['title'] === $title) {
                return 0;
            }
        }
        $names = array_map(static fn (array $c): string => $c['label'], $critical);
        $description = __('Ces éléments à disponibilité haute ne dépendent que de lui :', 'grcmanager')
            . "\n- " . implode("\n- ", $names) . "\n\n"
            . __(
                'Prévoir une redondance (second équipement, seconde alimentation, second lien) ou accepter le '
                . 'risque.',
                'grcmanager'
            );
        $field = 'linked_items_' . str_replace('\\', '_', $item::class);

        return (int) (new PluginGrcmanagerRisk())->add([
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
            || !in_array($item::class, PluginGrcmanagerRisk::getLinkableItemtypes(), true)
        ) {
            return '';
        }

        return self::createTabEntry(__('Impact d\'une panne', 'grcmanager'));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/impact_tab.html.twig', [
            'analysis'   => self::analyze($item),
            'levels'     => PluginGrcmanagerAssetClassification::getLevels(),
            'action'     => self::getSearchURL(),
            'itemtype'   => $item::class,
            'items_id'   => $item->getID(),
            'can_create' => Session::haveRight(PluginGrcmanagerRisk::$rightname, CREATE),
            'impact_url' => $item->getLinkURL() . '&forcetab=Impact$1',
        ]);

        return true;
    }
}
