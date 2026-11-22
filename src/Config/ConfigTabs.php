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

/**
 * Page de configuration du plugin en onglets (issue #1036) : onglets src/Config/Tab/*Tab.php et
 * blocs src/Config/Block/*Block.php, découverts comme les fonctionnalités de src/Feature/. Un lot
 * qui ajoute un réglage ajoute un fichier de bloc et son gabarit, sans modifier de liste commune.
 *
 * L'onglet ouvert est dans l'adresse (front/config.php?tab=<clé>) ; seul cet onglet est calculé
 * et affiché. Les anciens liens config.php#<identifiant> ouvrent l'onglet du bloc qui porte cet
 * identifiant (anchorMap(), lu par un court script de la page).
 */
final class ConfigTabs
{
    /** @var list<class-string<ConfigTab>>|null */
    private static ?array $tabs = null;

    /** @var list<class-string<ConfigBlock>>|null */
    private static ?array $blocks = null;

    /**
     * Onglets, dans l'ordre de la barre.
     *
     * @return list<class-string<ConfigTab>>
     */
    public static function tabClasses(): array
    {
        if (self::$tabs === null) {
            $found = self::discover('Tab', ConfigTab::class);
            usort($found, static fn (string $a, string $b): int => [$a::order(), $a] <=> [$b::order(), $b]);
            self::$tabs = $found;
        }

        return self::$tabs;
    }

    /**
     * Blocs de tous les onglets, chacun à sa place dans son onglet (order(), puis nom de fichier,
     * puis clé « after »).
     *
     * @return list<class-string<ConfigBlock>>
     */
    public static function blockClasses(): array
    {
        if (self::$blocks === null) {
            $found = self::discover('Block', ConfigBlock::class);
            usort($found, static fn (string $a, string $b): int => [$a::order(), $a] <=> [$b::order(), $b]);
            $list = [];
            $after = [];
            foreach ($found as $class) {
                $entry = ['class' => $class];
                if ($class::after() !== '') {
                    $entry['after'] = $class::after();
                    $after[$class::key()] = $entry;
                } else {
                    $list[$class::key()] = $entry;
                }
            }
            self::$blocks = array_values(array_column(self::insertAfter($list, $after), 'class'));
        }

        return self::$blocks;
    }

    /**
     * Blocs d'un onglet, dans l'ordre d'affichage.
     *
     * @return list<class-string<ConfigBlock>>
     */
    public static function blocksOf(string $tab): array
    {
        return array_values(array_filter(
            self::blockClasses(),
            static fn (string $class): bool => $class::tab() === $tab
        ));
    }

    /**
     * Clés des onglets montrés à l'utilisateur : ceux dont au moins un bloc lui est visible.
     *
     * @return list<string>
     */
    public static function visibleTabs(): array
    {
        $visible = [];
        foreach (self::tabClasses() as $tab) {
            foreach (self::blocksOf($tab::key()) as $block) {
                if ($block::canView()) {
                    $visible[] = $tab::key();
                    break;
                }
            }
        }

        return $visible;
    }

    /** Onglet à ouvrir : celui demandé s'il est montré, sinon le premier montré ('' : aucun). */
    public static function current(mixed $requested): string
    {
        $visible = self::visibleTabs();
        if (is_string($requested) && in_array($requested, $visible, true)) {
            return $requested;
        }

        return $visible[0] ?? '';
    }

    /** Adresse d'un onglet (et d'un bloc de cet onglet si $anchor est donné). */
    public static function url(string $tab, string $anchor = '', bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? (string) ($CFG_GLPI['root_doc'] ?? '') : '') . '/plugins/grcmanager/front/config.php?tab='
            . rawurlencode($tab) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
    }

    /** Onglet du bloc qui porte l'identifiant HTML $anchor ('' si aucun). */
    public static function tabOfAnchor(string $anchor): string
    {
        foreach (self::blockClasses() as $class) {
            if (in_array($anchor, $class::anchors(), true)) {
                return $class::tab();
            }
        }

        return '';
    }

    /** Adresse d'un bloc d'après son identifiant HTML (onglet ouvert, puis le bloc). */
    public static function anchorUrl(string $anchor, bool $full = true): string
    {
        $tab = self::tabOfAnchor($anchor);
        if ($tab === '') {
            global $CFG_GLPI;

            return ($full ? (string) ($CFG_GLPI['root_doc'] ?? '') : '') . '/plugins/grcmanager/front/config.php';
        }

        return self::url($tab, $anchor, $full);
    }

    /**
     * Anciens identifiants HTML et adresse de leur onglet, pour le script de redirection de la
     * page (config.php#<identifiant> d'avant les onglets).
     *
     * @return array<string, string>
     */
    public static function anchorMap(): array
    {
        $map = [];
        foreach (self::blockClasses() as $class) {
            foreach ($class::anchors() as $anchor) {
                $map[$anchor] = self::url($class::tab());
            }
        }

        return $map;
    }

    /**
     * Envoi d'un formulaire de bloc : le premier bloc qui le reconnaît le traite (et redirige).
     */
    public static function handlePost(): bool
    {
        foreach (self::blockClasses() as $class) {
            if ($class::handle()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Données de la page : barre d'onglets et blocs visibles de l'onglet ouvert.
     *
     * @return array{tabs: list<array{key: string, label: string, icon: string, url: string}>, current: string, current_label: string, blocks: list<array{key: string, template: string, data: array<string, mixed>}>, anchors: array<string, string>}
     */
    public static function pageData(string $current): array
    {
        $visible = self::visibleTabs();
        $tabs = [];
        foreach (self::tabClasses() as $tab) {
            if (in_array($tab::key(), $visible, true)) {
                $tabs[] = [
                    'key'   => $tab::key(),
                    'label' => $tab::label(),
                    'icon'  => $tab::icon(),
                    'url'   => self::url($tab::key()),
                ];
            }
        }
        $label = '';
        foreach ($tabs as $tab) {
            if ($tab['key'] === $current) {
                $label = $tab['label'];
            }
        }
        $blocks = [];
        foreach (self::blocksOf($current) as $class) {
            if ($class::canView()) {
                $blocks[] = ['key' => $class::key(), 'template' => $class::template(), 'data' => $class::data()];
            }
        }

        return [
            'tabs'          => $tabs,
            'current'       => $current,
            'current_label' => $label,
            'blocks'        => $blocks,
            'anchors'       => self::anchorMap(),
        ];
    }

    /**
     * Ajoute des entrées après la clé de leur « after » si elle existe, sinon à la fin (même règle
     * que FeatureRegistry::insertAfter()).
     *
     * @param array<string, array<string, mixed>> $list
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private static function insertAfter(array $list, array $entries): array
    {
        return \GlpiPlugin\Grcmanager\Feature\FeatureRegistry::insertAfter($list, $entries);
    }

    /**
     * @template T of object
     * @param class-string<T> $parent
     * @return list<class-string<T>>
     */
    private static function discover(string $kind, string $parent): array
    {
        $files = glob(__DIR__ . '/' . $kind . '/*' . $kind . '.php') ?: [];
        sort($files, SORT_STRING);
        $found = [];
        foreach ($files as $file) {
            $class = __NAMESPACE__ . '\\' . $kind . '\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, $parent)) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
