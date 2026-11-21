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
 * Fonctionnalité qui ajoute des cartes au tableau de bord (catalogue de hook.php).
 */
interface DashboardCardsProvider
{
    /**
     * Cartes, au format des cartes de GLPI (widgettype, label, provider...), sans « group » (celui
     * du plugin est ajouté). Clé facultative « after » : identifiant de la carte que celle-ci suit
     * dans la liste, sinon elle va à la fin. Le fournisseur vérifie les droits : un fournisseur
     * SecuredDashboardCards::xxx passe par SecuredDashboardCards::canShow(), tout autre doit refuser
     * lui-même (aucune donnée sans droit).
     *
     * @see \GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards::filterCatalog()
     * @return array<string, array<string, mixed>>
     */
    public static function dashboardCards(): array;
}
