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
 * Fonctionnalité qui donne l'encadré « À quoi sert cette page ? » de ses pages (PageHelp::show()).
 */
interface PageHelpProvider
{
    /**
     * Textes par page (clé passée à PageHelp::show() dans front/) : purpose, expected, reference
     * facultative, tous traduits.
     *
     * @return array<string, array{purpose: string, expected: string, reference?: string}>
     */
    public static function pageHelp(): array;
}
