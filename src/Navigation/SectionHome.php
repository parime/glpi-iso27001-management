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

use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use Html;

/**
 * Issue #1038 : page d'accueil d'une rubrique (front/section.php?section=<clé>). En arrivant dans la
 * rubrique : ce qui attend l'utilisateur (éléments de « Mon espace » des sortes déclarées par
 * todoSources()), puis les écrans de la rubrique en cartes groupées (option « group »). Seuls les
 * écrans que le profil peut ouvrir sont montrés, les modules désactivés n'apparaissent pas
 * (Menu::pageMenu()). Aucune donnée n'est lue ici en dehors de MySpaceProvider.
 */
final class SectionHome
{
    /** Éléments « À faire » montrés au plus ; la suite est dans « Mon espace ». */
    public const MAX_TODO = 10;

    /**
     * Données du gabarit section_home.html.twig, ou null si le profil ne voit rien de la rubrique.
     *
     * @param class-string<MenuSection> $section
     * @return array<string, mixed>|null
     */
    public static function data(string $section, int $usersId, string $today): ?array
    {
        global $CFG_GLPI;

        $parent = $section::parent();
        $menu = $parent::getMenuContent();
        if (!is_array($menu) || $menu === []) {
            return null;
        }
        $root = (string) $CFG_GLPI['root_doc'];
        $declared = Sections::pages($parent);
        $cards = [];
        if (empty($menu['parent_hidden'])) {
            $firstGroup = '';
            foreach ($declared as $options) {
                $firstGroup = (string) ($options['group'] ?? '');
                break;
            }
            $cards[] = [
                'title' => (string) ($menu['section_label'] ?? $menu['title']),
                'icon'  => (string) ($menu['icon'] ?? ''),
                'url'   => $root . (string) ($menu['section_page'] ?? $menu['page']),
                'group' => $firstGroup,
            ];
        }
        foreach ($declared as $key => $options) {
            $child = Menu::pageMenu($key);
            if ($child === null || !isset($child['page'])) {
                continue;
            }
            $cards[] = [
                'title' => (string) ($child['title'] ?? ''),
                'icon'  => (string) ($child['icon'] ?? ''),
                'url'   => $root . (string) $child['page'],
                'group' => (string) ($options['group'] ?? ''),
            ];
        }

        $todo = self::todo($section::todoSources(), $usersId, $today);

        return [
            'key'         => Sections::key($section),
            'title'       => (string) $menu['title'],
            'icon'        => (string) ($menu['icon'] ?? ''),
            'description' => $section::description(),
            'groups'      => self::groups($cards),
            'has_todo'    => $section::todoSources() !== [],
            'todo'        => array_slice($todo, 0, self::MAX_TODO),
            'todo_total'  => count($todo),
            'myspace_url' => MySpace::getSearchURL(),
        ];
    }

    /**
     * Cartes rangées par groupe, dans l'ordre de la première carte de chaque groupe.
     *
     * @param list<array{title: string, icon: string, url: string, group: string}> $cards
     * @return list<array{label: string, cards: list<array{title: string, icon: string, url: string, group: string}>}>
     */
    public static function groups(array $cards): array
    {
        $groups = [];
        foreach ($cards as $card) {
            $groups[$card['group']][] = $card;
        }
        $result = [];
        foreach ($groups as $label => $list) {
            $result[] = ['label' => (string) $label, 'cards' => $list];
        }

        return $result;
    }

    /**
     * Éléments de « Mon espace » des sortes $sources, dans leur ordre (retards d'abord).
     *
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    private static function todo(array $sources, int $usersId, string $today): array
    {
        if ($sources === [] || $usersId <= 0) {
            return [];
        }
        $labels = MySpace::getSources();
        $rows = [];
        foreach (MySpaceProvider::items($usersId, $today) as $item) {
            if (!in_array($item['source'], $sources, true)) {
                continue;
            }
            $rows[] = ['name' => $item['name'] !== '' ? $item['name'] : '#' . $item['id']] + $item + [
                'label'    => $labels[$item['source']][0] ?? $item['source'],
                'icon'     => $labels[$item['source']][1] ?? 'ti-point',
                'due_text' => $item['due'] !== null ? (string) Html::convDate($item['due']) : '',
            ];
        }

        return $rows;
    }
}
