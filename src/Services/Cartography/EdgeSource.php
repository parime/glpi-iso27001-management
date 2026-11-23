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

namespace GlpiPlugin\Grcmanager\Services\Cartography;

/**
 * Issue #1082 : source de liens de la cartographie. Une source lit ce que GLPI, GRC ou un autre
 * plugin connaissent déjà (connexions réseau, machines virtuelles, logiciels installés, processus
 * métier...) ; elle n'invente aucun lien. Point d'extension : une fonctionnalité ajoute ses sources
 * par src/Feature/<X>Feature.php (CartographySourcesProvider), par exemple les données réseau
 * importées d'un laboratoire.
 *
 * Les clés sont celles de ImpactGraph::key() (« Itemtype:id »). Les droits ne sont pas vérifiés ici :
 * la page ne nomme que les éléments que la personne peut voir.
 */
interface EdgeSource
{
    /**
     * Éléments dont l'élément dépend (vers le bas, vers le réseau).
     *
     * @return list<string>
     */
    public static function supporters(string $itemtype, int $itemsId): array;

    /**
     * Éléments qui dépendent de l'élément (vers le haut, vers les processus métier).
     *
     * @return list<string>
     */
    public static function dependents(string $itemtype, int $itemsId): array;
}
