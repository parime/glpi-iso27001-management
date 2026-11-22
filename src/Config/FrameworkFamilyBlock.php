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

use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkFamilyRules;

/**
 * Issue #1053 : famille de référentiels de l'onglet Référentiels de la configuration, repliable,
 * avec le nombre de référentiels actifs. Une famille est un fichier
 * src/Config/Block/Frameworks<Famille>Block.php (et son gabarit) ; un nouveau référentiel s'ajoute
 * dans members() de sa famille, sans toucher à une liste commune.
 *
 * Chaque famille a son formulaire : il renvoie aussi, en champs cachés, les référentiels cochés des
 * autres familles, si bien que le traitement existant (update_frameworks, front/config.php) garde
 * tout ce qui n'est pas affiché ici.
 */
abstract class FrameworkFamilyBlock extends ConfigBlock
{
    /** Nom affiché de la famille (traduit). */
    abstract public static function label(): string;

    /**
     * Membres de la famille : clés de référentiel ou regroupements (voir FrameworkFamilyRules),
     * sans appel à la base ni traduction (lus aussi hors session et par les tests).
     *
     * @return list<string|array{group: string, parent?: string, children: list<string>}>
     */
    abstract public static function members(): array;

    /** Icône Tabler de la famille. */
    public static function icon(): string
    {
        return 'ti-books';
    }

    /**
     * Textes des regroupements, par clé « group » : titre, aide, titre de la liste des déclinaisons
     * et nom court de chaque référentiel du regroupement.
     *
     * @return array<string, array{title?: string, hint?: string, children_label?: string, labels?: array<string, string>}>
     */
    public static function groupTexts(): array
    {
        return [];
    }

    /**
     * Membres ajoutés à l'exécution (référentiels importés) ; aucun par défaut.
     *
     * @return list<string>
     */
    public static function extraMembers(): array
    {
        return [];
    }

    /**
     * Lien affiché sous la famille ; aucun par défaut.
     *
     * @return array{label: string, url: string, icon: string}|null
     */
    public static function footerLink(): ?array
    {
        return null;
    }

    final public static function tab(): string
    {
        return 'frameworks';
    }

    /** Identifiant HTML du cadre : grc-frameworks-<famille>. */
    final public static function domId(): string
    {
        return 'grc-frameworks-' . strtolower(substr(static::key(), strlen('Frameworks')));
    }

    public static function anchors(): array
    {
        return [static::domId()];
    }

    /** @return list<string|array{group: string, parent?: string, children: list<string>}> */
    final public static function allMembers(): array
    {
        return array_merge(static::members(), static::extraMembers());
    }

    public static function data(): array
    {
        $names = FrameworkConfig::frameworks();
        $help = FrameworkConfig::helpTexts();
        $stored = FrameworkConfig::storedEnabled();
        $effective = FrameworkConfig::enabled();
        $parents = FrameworkFamilies::parents();
        $texts = static::groupTexts();
        $members = array_values(array_filter(
            static::allMembers(),
            static fn (string|array $member): bool => is_array($member) || isset($names[$member])
        ));
        $keys = array_values(array_filter(
            FrameworkFamilyRules::keys($members),
            static fn (string $key): bool => isset($names[$key])
        ));
        // Déclinaison affichée hors du regroupement de son parent (ReCyF dans le bloc ANSSI) alors
        // que le parent est désactivé : case grisée, sa valeur repart telle quelle en champ caché.
        $editable = [];
        foreach ($keys as $key) {
            $parent = $parents[$key] ?? null;
            if ($parent === null || in_array($parent, $keys, true) || in_array($parent, $stored, true)) {
                $editable[] = $key;
            }
        }
        $item = static function (string $key, string $label) use ($names, $help, $stored, $effective, $parents, $editable): array {
            $parent = $parents[$key] ?? null;

            return [
                'key'          => $key,
                'label'        => $label !== '' ? $label : $names[$key],
                'help'         => $help[$key] ?? '',
                'checked'      => in_array($key, $stored, true),
                'active'       => in_array($key, $effective, true),
                'editable'     => in_array($key, $editable, true),
                'parent_label' => $parent !== null ? ($names[$parent] ?? $parent) : '',
            ];
        };
        $items = [];
        foreach ($members as $member) {
            if (is_string($member)) {
                $items[] = ['type' => 'single'] + $item($member, '');
                continue;
            }
            $text = $texts[$member['group']] ?? [];
            $labels = $text['labels'] ?? [];
            $children = [];
            foreach ($member['children'] as $child) {
                if (isset($names[$child])) {
                    $children[] = $item($child, $labels[$child] ?? '');
                }
            }
            $parentKey = $member['parent'] ?? null;
            $items[] = [
                'type'           => 'group',
                'group'          => $member['group'],
                'title'          => $text['title'] ?? $member['group'],
                'hint'           => $text['hint'] ?? '',
                'children_label' => $text['children_label'] ?? '',
                'parent'         => $parentKey !== null && isset($names[$parentKey])
                    ? $item($parentKey, $labels[$parentKey] ?? '') : null,
                'children'       => $children,
            ];
        }

        return ['family' => [
            'id'     => static::domId(),
            'label'  => static::label(),
            'icon'   => static::icon(),
            'items'  => $items,
            'count'  => FrameworkFamilyRules::count($keys, $effective),
            'hidden' => array_values(array_diff($stored, $editable)),
            'link'   => static::footerLink(),
        ]];
    }
}
