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

namespace GlpiPlugin\Grcmanager\Services\Ui;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Plugin\Hooks;
use Plugin;

/**
 * Issue #1093 : liste de recherche complète d'un registre du plugin, avec des colonnes imposées.
 *
 * Search::showList() n'affiche que le tableau de résultats. Seul Search::show() (SearchEngine::show())
 * l'entoure de ce dont la recherche a besoin pour fonctionner :
 * - le conteneur `.search-container` : le bouton « Enregistrer la recherche » y cherche son
 *   gestionnaire de clic (GenericView.js) ; sans lui, le bouton ne fait rien ;
 * - le panneau des recherches enregistrées (bouton « Afficher les recherches sauvegardées ») ;
 * - le formulaire de critères quand la préférence « Afficher le formulaire de recherche au-dessus
 *   des résultats » est active : sans lui, il n'y a plus du tout de recherche.
 * Search::show() ne prend pas de colonnes imposées (DefaultSearchColumns) : ce gabarit reprend donc
 * son enveloppe (GLPI 11 et 12) autour de Search::showList().
 */
final class SearchPage
{
    /**
     * @param class-string $itemtype
     * @param array<string, mixed> $params       paramètres déjà fusionnés avec $_GET
     *                                           (QueryBuilder::manageParams())
     * @param list<int>            $forcedisplay colonnes imposées (DefaultSearchColumns)
     */
    public static function show(string $itemtype, array $params, array $forcedisplay = []): void
    {
        Plugin::doHook(Hooks::PRE_ITEM_LIST, ['itemtype' => $itemtype, 'options' => []]);

        TemplateRenderer::getInstance()->display('@grcmanager/_search_page.html.twig', [
            'itemtype'     => $itemtype,
            'params'       => $params,
            'forcedisplay' => $forcedisplay,
        ]);

        Plugin::doHook(Hooks::POST_ITEM_LIST, ['itemtype' => $itemtype, 'options' => []]);
    }
}
