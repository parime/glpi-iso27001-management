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
use GlpiPlugin\Grcmanager\Training;

/**
 * Issue #139 : onglet « Sessions » d'une formation principale. Chaque session est une formation
 * rattachée (plugin_grcmanager_trainings_id), avec sa date, son formateur, son lieu ou lien, ses
 * participants, ses documents et son taux de réussite ; l'onglet en donne la liste et le taux de
 * réussite de l'ensemble.
 */
class TrainingSession extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof Training || $item->isNewItem() || !Training::canView()
            || (int) ($item->fields['plugin_grcmanager_trainings_id'] ?? 0) > 0
        ) {
            return '';
        }

        return self::createTabEntry(
            __('Sessions', 'grcmanager'),
            count(Training::getSessions($item->getID())),
            null,
            'ti ti-calendar-event'
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Training || !$item->can($item->getID(), READ)) {
            return false;
        }
        $mainId = (int) ($item->fields['plugin_grcmanager_trainings_id'] ?? 0) ?: $item->getID();

        TemplateRenderer::getInstance()->display('@grcmanager/training_sessions_tab.html.twig', [
            'sessions'     => Training::getSessions($mainId),
            'program_rate' => Training::getProgramRate($mainId),
            'add_url'      => Training::canCreate()
                ? Training::getFormURL() . '?session_of=' . $mainId
                : '',
        ]);

        return true;
    }
}
