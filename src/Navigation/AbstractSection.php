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

    public static function overLimit(): string
    {
        return '';
    }

    public static function title(): string
    {
        return '';
    }

    public static function hub(): bool
    {
        return false;
    }

    public static function description(): string
    {
        return '';
    }

    /**
     * Titre et sous-titre facultatifs de la carte de la page parente sur l'accueil de la rubrique
     * (clés « title » et « description »), quand le nom de l'objet seul n'est pas assez parlant.
     * Les sous-pages passent les leurs par les options « card_title » et « card_description » de
     * pages().
     *
     * @return array{title?: string, description?: string}
     */
    public static function parentCard(): array
    {
        return [];
    }

    public static function todoSources(): array
    {
        return [];
    }
}
