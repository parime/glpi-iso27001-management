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

use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Feature\MenuPagesProvider;
use GlpiPlugin\Grcmanager\Menu;
use Session;

/**
 * Issue #1037 : registre des rubriques de menu. Chaque fichier src/Navigation/Section/<X>Section.php
 * (une classe qui implémente MenuSection) déclare une rubrique : son secteur GLPI, sa place, son
 * droit et ses sous-pages. Ce registre les découvre (ordre alphabétique des fichiers) et en tire :
 * - le tableau menu_toadd de setup.php (menuToAdd()) ;
 * - les sous-pages de chaque rubrique (pages(), lues par Menu::withSubPages() et Menu::showSection()) ;
 * - le secteur et la rubrique d'une page, pour son en-tête (locate(), lu par Menu::header()) ;
 * - la place des entrées dans un secteur du cœur de GLPI (reorderMenu(), hook redefine_menus).
 *
 * Ajouter ou déplacer une rubrique ne touche donc ni src/Menu.php ni setup.php.
 */
final class Sections
{
    /** Secteur propre au plugin : le menu « GRC & Conformité ». */
    public const GRC = 'grcmanager';

    /** Valeur de after() : en tête du secteur. */
    public const FIRST = '@first';

    /** Sous-pages au plus par rubrique : au-delà, découper la rubrique (règle de navigation, #1035). */
    public const MAX_PAGES = 7;

    /** Secteurs acceptés, dans l'ordre du tableau menu_toadd. */
    public const SECTORS = [self::GRC, 'helpdesk', 'assets', 'management', 'tools'];

    /** @var list<class-string<MenuSection>>|null */
    private static ?array $all = null;

    /** @var array<string, array<string, array<string, mixed>>>|null */
    private static ?array $pages = null;

    /**
     * Rubriques déclarées, dans l'ordre alphabétique de leur fichier.
     *
     * @return list<class-string<MenuSection>>
     */
    public static function all(): array
    {
        if (self::$all === null) {
            $files = glob(__DIR__ . '/Section/*Section.php') ?: [];
            sort($files, SORT_STRING);
            $found = [];
            foreach ($files as $file) {
                $class = __NAMESPACE__ . '\\Section\\' . basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, MenuSection::class)) {
                    $found[] = $class;
                }
            }
            self::$all = $found;
        }

        return self::$all;
    }

    /**
     * Rubrique dont $parent est l'entrée de menu.
     *
     * @return class-string<MenuSection>|null
     */
    public static function sectionOf(string $parent): ?string
    {
        foreach (self::all() as $section) {
            if ($section::parent() === $parent) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Entrées de menu (classes parentes) d'un secteur, dans l'ordre demandé par after().
     *
     * @return list<string>
     */
    public static function ordered(string $sector, bool $enabledOnly = true): array
    {
        $pending = [];
        foreach (self::all() as $section) {
            if ($section::sector() === $sector && (!$enabledOnly || $section::enabled())) {
                $pending[$section::parent()] = $section::after();
            }
        }
        $parents = array_keys($pending);
        $order = [];
        $firsts = 0;
        // Dernière entrée placée après chaque entrée : deux rubriques après la même gardent leur ordre.
        $tail = [];
        do {
            $progress = false;
            foreach ($pending as $parent => $after) {
                if ($after === self::FIRST) {
                    array_splice($order, $firsts++, 0, [$parent]);
                } elseif ($after === null || !in_array($after, $parents, true)) {
                    // Fin du secteur, ou entrée du cœur de GLPI : placée ensuite par reorderMenu().
                    $order[] = $parent;
                } elseif (in_array($after, $order, true)) {
                    $previous = $tail[$after] ?? $after;
                    $position = array_search($previous, $order, true);
                    array_splice($order, (int) $position + 1, 0, [$parent]);
                    $tail[$after] = $parent;
                } else {
                    continue;
                }
                unset($pending[$parent]);
                $progress = true;
            }
        } while ($progress && $pending !== []);

        // Boucle dans les after() : les restantes vont à la fin (le test de validité le signale).
        return array_merge($order, array_keys($pending));
    }

    /**
     * Tableau $PLUGIN_HOOKS['menu_toadd']['grcmanager'] : par secteur, les classes des entrées.
     * La classe Menu, simple ancre, reste en tête du secteur du plugin pour lui donner son titre et
     * son icône (Html::generateMenuSession()) ; plugin_grcmanager_redefine_menus() retire sa ligne.
     *
     * @return array<string, list<string>>
     */
    public static function menuToAdd(): array
    {
        $sectors = self::SECTORS;
        foreach (self::all() as $section) {
            if (!in_array($section::sector(), $sectors, true)) {
                $sectors[] = $section::sector();
            }
        }
        $menu = [];
        foreach ($sectors as $sector) {
            $entries = self::ordered($sector);
            if ($entries === []) {
                continue;
            }
            $menu[$sector] = $sector === self::GRC ? array_merge([Menu::class], $entries) : $entries;
        }

        return $menu;
    }

    /**
     * Sous-pages de chaque rubrique, après les déplacements (option « from »).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function allPages(): array
    {
        if (self::$pages === null) {
            $pages = [];
            foreach (self::all() as $section) {
                $pages[$section::parent()] = self::normalize($section::pages());
            }
            // Sous-pages ajoutées par une fonctionnalité (src/Feature/), après celles de la rubrique.
            foreach (FeatureRegistry::implementing(MenuPagesProvider::class) as $feature) {
                foreach ($feature::menuPages() as $parent => $own) {
                    $pages[$parent] = FeatureRegistry::insertAfter($pages[$parent] ?? [], self::normalize($own));
                }
            }
            // Sous-pages déplacées : retirées de leur rubrique d'origine.
            foreach ($pages as $parent => $own) {
                foreach ($own as $key => $options) {
                    $from = (string) ($options['from'] ?? '');
                    if ($from !== '' && $from !== $parent) {
                        unset($pages[$from][$key]);
                    }
                }
            }
            self::$pages = $pages;
        }

        return self::$pages;
    }

    /**
     * Sous-pages au format clé => options (une classe seule n'a pas d'options).
     *
     * @param array<int|string, string|array<string, mixed>> $declared
     * @return array<string, array<string, mixed>>
     */
    private static function normalize(array $declared): array
    {
        $pages = [];
        foreach ($declared as $key => $options) {
            if (is_int($key)) {
                $pages[(string) $options] = [];
            } else {
                $pages[$key] = is_array($options) ? $options : [];
            }
        }

        return $pages;
    }

    /**
     * Sous-pages de la rubrique $parent, dans l'ordre, avec leurs options.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(string $parent): array
    {
        return self::allPages()[$parent] ?? [];
    }

    /**
     * Options d'une sous-page, où qu'elle soit déclarée.
     *
     * @return array<string, mixed>|null
     */
    public static function page(string $key): ?array
    {
        foreach (self::allPages() as $pages) {
            if (isset($pages[$key])) {
                return $pages[$key];
            }
        }

        return null;
    }

    /**
     * Secteur et rubrique de $page : l'entrée de menu d'une rubrique ou l'une de ses sous-pages.
     * Les rubriques désactivées comptent aussi : leurs pages gardent leur secteur.
     *
     * @return array{sector: string, parent: string}|null
     */
    public static function locate(string $page): ?array
    {
        $section = self::sectionOf($page);
        if ($section !== null) {
            return ['sector' => $section::sector(), 'parent' => $page];
        }
        foreach (self::allPages() as $parent => $pages) {
            if (isset($pages[$page])) {
                $section = self::sectionOf($parent);

                return [
                    'sector' => $section !== null ? $section::sector() : self::GRC,
                    'parent' => $parent,
                ];
            }
        }

        return null;
    }

    /**
     * Hook redefine_menus : place après l'entrée du cœur demandée (ou en tête) chaque rubrique d'un
     * secteur de GLPI, où le cœur ajoute les entrées des plugins à la fin. Sans effet sur une entrée
     * absente (droit manquant, rubrique désactivée).
     *
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    public static function reorderMenu(array $menu): array
    {
        foreach (self::SECTORS as $sector) {
            if ($sector === self::GRC || !is_array($menu[$sector]['content'] ?? null)) {
                continue;
            }
            // Issue #1041 : dans l'ordre du secteur, pour qu'une rubrique placée après une autre
            // rubrique du plugin (Crise et continuité après Incidents de sécurité) la suive une fois
            // celle-ci déplacée après l'entrée du cœur. Plusieurs rubriques en tête gardent leur ordre.
            $lastFirst = self::FIRST;
            foreach (self::ordered($sector, false) as $parent) {
                $section = self::sectionOf($parent);
                $after = $section !== null ? $section::after() : null;
                if ($after === null) {
                    continue;
                }
                // Rubrique visée absente du menu (droit manquant) : la place qu'elle aurait prise.
                $seen = [];
                while (
                    $after !== null && $after !== self::FIRST && !in_array($after, $seen, true)
                    && !array_key_exists(strtolower($after), $menu[$sector]['content'])
                ) {
                    $aimed = self::sectionOf($after);
                    if ($aimed === null) {
                        break;
                    }
                    $seen[] = $after;
                    $after = $aimed::after();
                }
                if ($after === null) {
                    continue;
                }
                $key = strtolower($parent);
                $target = $after === self::FIRST ? $lastFirst : strtolower($after);
                $menu[$sector]['content'] = self::moveAfter($menu[$sector]['content'], $key, $target);
                if ($after === self::FIRST && array_key_exists($key, $menu[$sector]['content'])) {
                    $lastFirst = $key;
                }
            }
        }

        return $menu;
    }

    /**
     * Déplace la clé $key juste après la clé $after (ou en tête avec FIRST) d'une liste associative.
     * Liste inchangée si l'une des deux clés manque.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public static function moveAfter(array $content, string $key, string $after): array
    {
        if (!array_key_exists($key, $content) || ($after !== self::FIRST && !array_key_exists($after, $content))) {
            return $content;
        }
        $entry = $content[$key];
        unset($content[$key]);
        if ($after === self::FIRST) {
            return [$key => $entry] + $content;
        }
        $position = (int) array_search($after, array_keys($content), true);

        return array_slice($content, 0, $position + 1, true) + [$key => $entry]
            + array_slice($content, $position + 1, null, true);
    }

    /**
     * Options « actif si » et « droit requis » d'une sous-page : faux si elle ne doit pas être
     * affichée (module désactivé, droit manquant).
     *
     * @param array<string, mixed> $options
     */
    public static function allowed(array $options): bool
    {
        if (array_key_exists('active', $options) && !(bool) $options['active']) {
            return false;
        }
        if (!isset($options['right'])) {
            return true;
        }
        $right = (array) $options['right'];

        return (bool) Session::haveRight((string) $right[0], (int) ($right[1] ?? READ));
    }

    /** Vide les caches (tests). */
    public static function reset(): void
    {
        self::$all = null;
        self::$pages = null;
    }
}
