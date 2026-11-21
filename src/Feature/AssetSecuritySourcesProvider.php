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

namespace GlpiPlugin\Grcmanager\Feature;

/**
 * Issue #837 : fonctionnalité qui ajoute une ou plusieurs sources à l'onglet « Sécurité 360° » des
 * ordinateurs et matériels réseau (AssetSecurity360). Une nouvelle source se branche en déclarant ce
 * descripteur dans un fichier src/Feature/<X>Feature.php, sans toucher à l'onglet.
 *
 * Chaque source :
 * - « label » (traduit), « icon » (Tabler), « itemtypes » (types d'éléments concernés) ;
 * - « weight » : poids dans le score global (1 par défaut, 0 pour une source seulement informative) ;
 * - « compute » : callable qui reçoit l'élément (CommonDBTM) et renvoie null si la source est absente
 *   (plugin ou module non utilisé, aucune donnée, pas le droit), sinon un tableau
 *   { status: Security360Rules::STATUS_*, summary: string, url?: string, facts?: list<string> }.
 *   Le calcul est en lecture seule et léger (quelques requêtes ciblées sur l'élément) ;
 * - « after » (facultatif) : clé d'une source existante à suivre dans l'affichage.
 */
interface AssetSecuritySourcesProvider
{
    /**
     * @return array<string, array{label: string, icon: string, itemtypes: list<class-string>,
     *     weight?: int, compute: callable, after?: string}>
     */
    public static function assetSecuritySources(): array;
}
