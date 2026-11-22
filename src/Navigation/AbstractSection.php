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

namespace GlpiPlugin\Grcmanager\Navigation;

/**
 * Valeurs par défaut d'une rubrique : menu du plugin, à la fin du secteur, droit de la classe
 * parente, sans sous-page, toujours affichée. Une rubrique ne redéfinit que ce qui change.
 */
abstract class AbstractSection implements MenuSection
{
    public static function sector(): string
    {
        return Sections::GRC;
    }

    public static function after(): ?string
    {
        return null;
    }

    public static function right(): string
    {
        $parent = static::parent();
        if (defined($parent . '::RIGHTNAME')) {
            return (string) constant($parent . '::RIGHTNAME');
        }

        return (string) $parent::$rightname;
    }

    public static function pages(): array
    {
        return [];
    }

    public static function enabled(): bool
    {
        return true;
    }
}
