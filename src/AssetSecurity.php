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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Navigation\Section\AssetSecuritySection;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use Session;

/**
 * Issue #1039 : page d'accueil de Parc > Sécurité du parc (front/assetsecurity.php). Elle range les
 * écrans de la rubrique en cartes groupées (groupes de AssetSecuritySection) ; seuls les écrans que
 * le profil peut ouvrir sont montrés, un module désactivé n'apparaît pas (Menu::pageMenu()).
 *
 * Ancre sans table : la page n'a pas de droit propre, elle est visible dès qu'un de ses écrans l'est.
 */
class AssetSecurity extends CommonGLPI
{
    public const RIGHTNAME = Installer::RIGHT_NAME;

    public static function getTypeName($nb = 0)
    {
        return __('Asset security', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    /** Visible dès que le profil peut ouvrir l'un des écrans de la rubrique. */
    public static function canView(): bool
    {
        return self::visiblePages() !== [];
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/assetsecurity.php';
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return Menu::withSubPages(self::class, [
            'title'         => self::getTypeName(),
            'section_label' => __('Overview', 'grcmanager'),
            'page'          => self::getSearchURL(false),
            'icon'          => self::getIcon(),
        ]);
    }

    /**
     * Message après un ancien envoi de la configuration, pour une action d'exploitation qui en est
     * sortie (issue #1039) : la personne est menée à l'écran de la rubrique qui l'accueille.
     */
    public static function movedNotice(string $screen): void
    {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('This is now done in Assets > Asset security > %s.', 'grcmanager'),
            $screen
        )), false, INFO);
    }

    /**
     * Écrans de la rubrique que le profil peut ouvrir, avec leur entrée de menu.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function visiblePages(): array
    {
        $pages = [];
        foreach (array_keys(Sections::pages(self::class)) as $key) {
            $menu = Menu::pageMenu($key);
            if ($menu !== null && isset($menu['page'])) {
                $pages[$key] = $menu;
            }
        }

        return $pages;
    }

    /**
     * Données de la page d'accueil : un groupe par thème, avec ses cartes (écrans visibles). Un
     * groupe sans écran visible n'est pas montré.
     *
     * @return list<array{key: string, label: string, icon: string, description: string,
     *     cards: list<array{title: string, icon: string, url: string}>}>
     */
    public static function homeGroups(): array
    {
        global $CFG_GLPI;

        $declared = Sections::pages(self::class);
        $byLabel = [];
        foreach (AssetSecuritySection::groups() as $key => $group) {
            $byLabel[$group['label']] = ['key' => $key] + $group + ['cards' => []];
        }
        $others = [];
        foreach (self::visiblePages() as $key => $menu) {
            $card = [
                'title' => (string) ($menu['title'] ?? ''),
                'icon'  => (string) ($menu['icon'] ?? ''),
                'url'   => (string) $CFG_GLPI['root_doc'] . (string) $menu['page'],
            ];
            $label = (string) ($declared[$key]['tab_group'] ?? '');
            if (isset($byLabel[$label])) {
                $byLabel[$label]['cards'][] = $card;
            } else {
                // Écran ajouté par une fonctionnalité sans groupe : à la fin.
                $others[] = $card;
            }
        }
        $groups = array_values(array_filter($byLabel, static fn (array $group): bool => $group['cards'] !== []));
        if ($others !== []) {
            $groups[] = [
                'key'         => 'other',
                'label'       => __('Other screens', 'grcmanager'),
                'icon'        => 'ti ti-dots',
                'description' => '',
                'cards'       => $others,
            ];
        }

        return $groups;
    }
}
