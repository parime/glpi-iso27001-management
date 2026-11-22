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
 * Issue #1037 : fonctionnalité qui ajoute ses écrans en sous-pages d'une rubrique de menu existante
 * (src/Navigation/Section/), sans modifier le fichier de la rubrique.
 */
interface MenuPagesProvider
{
    /**
     * Sous-pages par rubrique (classe parente de la rubrique), au format de MenuSection::pages().
     * Option supplémentaire `after` : sous-page que la nouvelle suit dans la barre d'onglets ;
     * sinon, elle va à la fin.
     *
     * @return array<string, array<int|string, string|array<string, mixed>>>
     */
    public static function menuPages(): array;
}
