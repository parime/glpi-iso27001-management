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
 * Issue #1037 : une rubrique de menu du plugin, déclarée dans son propre fichier
 * src/Navigation/Section/<Rubrique>Section.php et découverte par Sections. Déclarer, déplacer ou
 * réordonner une rubrique ne touche que ce fichier, jamais src/Menu.php ni setup.php.
 *
 * Le plus simple est d'étendre AbstractSection, qui fournit les valeurs par défaut.
 */
interface MenuSection
{
    /**
     * Secteur GLPI de la rubrique : Sections::GRC (menu du plugin), 'helpdesk' (Assistance),
     * 'assets' (Parc), 'management' (Gestion) ou 'tools' (Outils).
     */
    public static function sector(): string;

    /**
     * Classe de l'entrée de menu : sa méthode getMenuContent() donne le titre, l'icône, l'adresse
     * et les droits de l'entrée (avec Menu::withSubPages() quand la rubrique a des sous-pages).
     *
     * @return class-string
     */
    public static function parent(): string;

    /**
     * Place de l'entrée dans son secteur :
     * - null : à la fin du secteur ;
     * - Sections::FIRST : en tête ;
     * - la classe parente d'une autre rubrique du même secteur : juste après elle ;
     * - la clé d'une entrée du cœur de GLPI (par exemple 'change' dans Assistance) : juste après
     *   elle. GLPI ajoute les entrées des plugins à la fin du secteur ; Sections::reorderMenu()
     *   (hook redefine_menus) les déplace ensuite.
     */
    public static function after(): ?string;

    /**
     * Droit GLPI principal de la rubrique (celui de la classe parente par défaut). Documentaire et
     * vérifié par les tests : la visibilité reste décidée par canView() de chaque écran.
     */
    public static function right(): string;

    /**
     * Sous-pages de la rubrique, dans l'ordre de la barre d'onglets. Chaque élément est :
     * - une classe dont getMenuContent() donne l'entrée (droits compris) : `Waiver::class` ;
     * - ou une clé avec ses options : `Waiver::class => ['group' => __('...'), 'from' => Risk::class]`.
     *
     * Options :
     * - `group` : libellé du groupe d'onglets de la sous-page (facultatif) ;
     * - `from` : rubrique d'origine d'une sous-page déplacée ; elle y est retirée, sans modifier le
     *   fichier de la rubrique d'origine ;
     * - pour une page sans classe propre (clé libre) : `title`, `page` (adresse depuis la racine de
     *   GLPI), `icon` et `rights_of` (classe dont canView() décide de la visibilité).
     *
     * @return array<int|string, string|array<string, mixed>>
     */
    public static function pages(): array;

    /** Faux pour retirer la rubrique du menu (module désactivé) ; ses pages gardent leur secteur. */
    public static function enabled(): bool;
}
