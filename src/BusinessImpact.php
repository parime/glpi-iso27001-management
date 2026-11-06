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
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessImpactBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\BusinessImpactRules;
use Log;
use Session;

/**
 * Issue #377 : analyse d'impact sur l'activité (BIA) d'un processus métier. Une ligne par durée
 * d'interruption évaluée, un niveau par nature d'impact (BusinessImpactRules). Se modifie depuis
 * l'onglet « Analyse d'impact (BIA) » du processus, avec le droit de modifier ce processus.
 */
class BusinessImpact extends BusinessImpactBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessimpacts';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Business impact analysis', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-arrows-vertical';
    }

    /**
     * @return array<string, string>
     */
    public static function getCategoryLabels(): array
    {
        return [
            'financial'   => __('Financial', 'grcmanager'),
            'legal'       => __('Legal and regulatory', 'grcmanager'),
            'reputation'  => __('Image and reputation', 'grcmanager'),
            'operational' => __('Operational', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            0 => __('Not rated', 'grcmanager'),
            1 => __('Negligible', 'grcmanager'),
            2 => __('Limited', 'grcmanager'),
            3 => __('Serious', 'grcmanager'),
            4 => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * Couleur du badge de chaque niveau.
     *
     * @return array<int, string>
     */
    public static function getLevelClasses(): array
    {
        return [0 => 'bg-secondary-lt', 1 => 'bg-green-lt', 2 => 'bg-yellow-lt', 3 => 'bg-orange-lt', 4 => 'bg-red-lt'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRows(int $processId): array
    {
        return array_values((new self())->find(['plugin_grcmanager_businessprocesses_id' => $processId]));
    }

    /**
     * Enregistre la grille d'un processus (une ligne par durée) et date sa BIA. L'appelant a vérifié
     * le droit de modifier le processus.
     *
     * @param array<mixed> $levels durée => nature => niveau
     */
    public static function saveGrid(BusinessProcess $process, array $levels): void
    {
        global $DB;

        $processId = (int) $process->getID();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (BusinessImpactRules::HORIZONS as $horizon) {
            $row = [];
            foreach (BusinessImpactRules::CATEGORIES as $category) {
                $value = is_array($levels[$horizon] ?? null) ? ($levels[$horizon][$category] ?? 0) : 0;
                $row[$category] = BusinessImpactRules::sanitizeLevel($value);
            }
            $where = ['plugin_grcmanager_businessprocesses_id' => $processId, 'horizon_hours' => $horizon];
            if (countElementsInTable(self::getTable(), $where) > 0) {
                $DB->update(self::getTable(), $row + ['date_mod' => $now], $where);
            } else {
                $DB->insert(self::getTable(), $where + $row + ['date_creation' => $now, 'date_mod' => $now]);
            }
        }
        $process->update(['id' => $processId, 'bia_date' => date('Y-m-d', strtotime($now) ?: time())]);
        Log::history(
            $processId,
            BusinessProcess::class,
            [0, '', __('Business impact analysis updated', 'grcmanager')],
            '',
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !BusinessProcess::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
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
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_impact.html.twig', [
            'item'          => $item,
            'analysis'      => BusinessProcess::analyze($item),
            'horizons'      => BusinessImpactRules::HORIZONS,
            'categories'    => self::getCategoryLabels(),
            'levels'        => self::getLevelLabels(),
            'level_classes' => self::getLevelClasses(),
            'durations'     => BusinessProcess::getDurationOptions(),
            'issues'        => BusinessProcess::getIssueLabels(),
            'can_edit'      => $item->canUpdateItem(),
            'form_url'      => self::getFormURL(),
            'report_url'    => BusinessProcess::getContinuityReportURL((int) $item->getID()),
        ]);

        return true;
    }
}
