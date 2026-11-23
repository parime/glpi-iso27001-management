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

namespace GlpiPlugin\Grcmanager\Config;

use GlpiPlugin\Grcmanager\Services\Control\FrameworkFamilyRules;

/**
 * Issue #1053 : familles de référentiels déclarées par fichier (src/Config/Block, sous-classes de
 * FrameworkFamilyBlock), lues par la configuration, l'évaluation par référentiel et la fiche d'une
 * mesure, pour présenter partout les mêmes regroupements.
 */
final class FrameworkFamilies
{
    /** @var array<string, string>|null */
    private static ?array $parents = null;

    /**
     * Familles, dans l'ordre de l'onglet.
     *
     * @return list<class-string<FrameworkFamilyBlock>>
     */
    public static function classes(): array
    {
        return array_values(array_filter(
            ConfigTabs::blockClasses(),
            static fn (string $class): bool => is_subclass_of($class, FrameworkFamilyBlock::class)
        ));
    }

    /**
     * Déclinaisons et leur parent (ReCyF et ACN => NIS2), d'après les regroupements déclarés.
     *
     * @return array<string, string>
     */
    public static function parents(): array
    {
        if (self::$parents === null) {
            $parents = [];
            foreach (self::classes() as $class) {
                $parents += FrameworkFamilyRules::parents($class::members());
            }
            self::$parents = $parents;
        }

        return self::$parents;
    }

    /**
     * Familles prêtes pour FrameworkFamilyRules::arrange() (référentiels importés compris).
     *
     * @return list<array{key: string, label: string, icon: string, keys: list<string>, parents: array<string, string>}>
     */
    public static function describe(): array
    {
        $families = [];
        foreach (self::classes() as $class) {
            $members = $class::allMembers();
            $families[] = [
                'key'     => $class::domId(),
                'label'   => $class::label(),
                'icon'    => $class::icon(),
                'keys'    => FrameworkFamilyRules::keys($members),
                'parents' => FrameworkFamilyRules::parents($members),
            ];
        }

        return $families;
    }
}
