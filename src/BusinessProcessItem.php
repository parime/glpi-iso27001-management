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
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessProcessItemBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use Session;

/**
 * Issue #377 : élément GLPI dont dépend un processus métier (application du cœur, serveur,
 * logiciel, base de données, fournisseur…).
 *
 * - onglet « Dépendances » du processus : les éléments liés, puis ce dont ils dépendent selon
 *   l'analyse d'impact de GLPI, avec leur RTO et RPO (classification C/I/D) et leur criticité métier
 *   (informations financières du cœur) ; un élément plus lent que le processus est signalé ;
 * - onglet « Processus métier » sur chaque élément liable : les processus qui en dépendent.
 *
 * Lier un élément exige de pouvoir modifier le processus (droit et entité) et de pouvoir lire
 * l'élément. Un élément que la personne ne peut pas lire n'est jamais nommé.
 */
class BusinessProcessItem extends BusinessProcessItemBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessprocesses_items';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Dependencies', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-hierarchy-3';
    }

    /**
     * Types d'éléments liables : ceux du registre des risques (actifs du cœur et actifs
     * personnalisés), plus les applications, instances de bases de données et fournisseurs du cœur.
     *
     * @return list<string>
     */
    public static function getItemtypes(): array
    {
        // Pas de test d'existence des classes : setup.php appelle cette liste avant le chargement des
        // actifs personnalisés (voir son commentaire de l'issue #88), qui en seraient retirés.
        // Mêmes types que la classification C/I/D (AssetClassification), qui porte leur RTO et leur RPO.
        return array_values(array_unique(array_merge(
            ['Appliance', 'DatabaseInstance'],
            AssetClassification::getItemtypes()
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForProcess(int $processId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_businessprocesses_id' => $processId],
            ['itemtype ASC', 'id ASC']
        ));
    }

    /**
     * Processus qui dépendent de l'élément, limités à ceux que la personne peut lire.
     *
     * @return list<array<string, mixed>>
     */
    public static function getProcessesForItem(string $itemtype, int $itemsId): array
    {
        if (!BusinessProcess::canView()) {
            return [];
        }
        $durations = BusinessProcess::getDurationOptions();
        $process = new BusinessProcess();
        $processes = [];
        foreach ((new self())->find(['itemtype' => $itemtype, 'items_id' => $itemsId]) as $link) {
            $processId = (int) $link['plugin_grcmanager_businessprocesses_id'];
            if (!$process->getFromDB($processId) || !$process->canViewItem()) {
                continue;
            }
            $processes[] = [
                'name'    => (string) $process->fields['name'],
                'url'     => $process->getLinkURL(),
                'rto'     => $durations[(int) $process->fields['rto_hours']] ?? '',
                'mtpd'    => $durations[(int) $process->fields['mtpd_hours']] ?? '',
                'comment' => (string) $link['comment'],
            ];
        }
        usort($processes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $processes;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !BusinessProcess::canView()) {
            return '';
        }
        if ($item instanceof BusinessProcess) {
            return self::createTabEntry(
                self::getTypeName(Session::getPluralNumber()),
                countElementsInTable(self::getTable(), ['plugin_grcmanager_businessprocesses_id' => $item->getID()]),
                $item::class,
                self::getIcon()
            );
        }
        if (!in_array($item::class, self::getItemtypes(), true)) {
            return '';
        }

        return self::createTabEntry(
            BusinessProcess::getTypeName(Session::getPluralNumber()),
            count(self::getProcessesForItem($item::class, (int) $item->getID())),
            $item::class,
            BusinessProcess::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !BusinessProcess::canView()
            || !$item->canViewItem()
        ) {
            return false;
        }
        if ($item instanceof BusinessProcess) {
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_items.html.twig', [
                'item'       => $item,
                'analysis'   => BusinessProcess::analyze($item),
                'issues'     => BusinessProcess::getIssueLabels(),
                'levels'     => AssetClassification::getLevels(),
                'durations'  => BusinessProcess::getDurationOptions(),
                'itemtypes'  => self::getItemtypes(),
                'can_edit'   => $item->canUpdateItem(),
                'form_url'   => self::getFormURL(),
                // Issue #377 : risque proposé pour un actif qui ne tient pas les objectifs.
                'can_propose_risk' => $item->canUpdateItem() && Risk::canView() && Risk::canCreate(),
                'risk_url'         => BusinessProcessRisk::getFormURL(),
                'risk_itemtypes'   => Risk::getLinkableItemtypes(),
            ]);

            return true;
        }
        if (!in_array($item::class, self::getItemtypes(), true)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/item_business_processes.html.twig', [
            'processes' => self::getProcessesForItem($item::class, (int) $item->getID()),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $processId = (int) ($input['plugin_grcmanager_businessprocesses_id'] ?? 0);
        $itemtype = (string) ($input['itemtype'] ?? '');
        $itemsId = (int) ($input['items_id'] ?? 0);
        $process = new BusinessProcess();
        if (!$process->getFromDB($processId) || !$process->canUpdateItem()) {
            return false;
        }
        if (!in_array($itemtype, self::getItemtypes(), true) || $itemsId <= 0) {
            Session::addMessageAfterRedirect(htmlescape(__('Choose an item to link.', 'grcmanager')), false, ERROR);

            return false;
        }
        // $itemtype est borné par la liste blanche des types liables juste au-dessus.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return false;
        }
        $existing = [
            'plugin_grcmanager_businessprocesses_id' => $processId,
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ];
        if (countElementsInTable(self::getTable(), $existing) > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('This item is already linked to the process.', 'grcmanager')),
                false,
                WARNING
            );

            return false;
        }

        return $existing + ['comment' => self::cleanComment($input)];
    }

    public function prepareInputForUpdate($input)
    {
        // Seul le rôle de l'élément se modifie : le processus et l'élément liés restent ceux du lien.
        return ['id' => (int) ($input['id'] ?? 0), 'comment' => self::cleanComment($input)];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function cleanComment(array $input): string
    {
        return mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255);
    }


    public function post_addItem()
    {
        \Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            [0, '', sprintf('%s #%d', $this->fields['itemtype'], $this->fields['items_id'])],
            self::class,
            \Log::HISTORY_ADD_SUBITEM
        );
    }

    public function post_purgeItem()
    {
        \Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            [0, sprintf('%s #%d', $this->fields['itemtype'], $this->fields['items_id']), ''],
            self::class,
            \Log::HISTORY_DELETE_SUBITEM
        );
    }
}
