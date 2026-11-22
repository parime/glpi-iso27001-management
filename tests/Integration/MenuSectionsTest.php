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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\MenuSection;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;

/**
 * Issue #1037 : chaque rubrique déclarée dans src/Navigation/Section/ est valide (secteur connu, droit
 * existant, place possible, pages existantes), et l'en-tête de chaque page suit sa déclaration.
 */
final class MenuSectionsTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Le registre DORA n'a d'entrée qu'avec son référentiel, optionnel (annulé avec la transaction).
        FrameworkConfig::setEnabled('dora', true);
        FrameworkConfig::reset();
        Sections::reset();
    }

    protected function tearDown(): void
    {
        FrameworkConfig::reset();
        Sections::reset();
        parent::tearDown();
    }

    /** @return list<class-string<MenuSection>> */
    private function sections(): array
    {
        $sections = Sections::all();
        $this->assertNotSame([], $sections, 'Rubriques découvertes dans src/Navigation/Section/.');

        return $sections;
    }

    public function testEachSectionDeclaresAKnownSectorAnExistingParentAndRight(): void
    {
        $core = \Html::getMenuInfos();
        $rights = \ProfileRight::getAllPossibleRights();
        $parents = [];
        foreach ($this->sections() as $section) {
            $sector = $section::sector();
            $parent = $section::parent();
            $this->assertContains($sector, Sections::SECTORS, "$section : secteur connu.");
            if ($sector !== Sections::GRC) {
                $this->assertArrayHasKey($sector, $core, "$section : secteur de GLPI.");
            }
            $this->assertTrue(class_exists($parent), "$section : classe parente $parent.");
            $this->assertTrue(method_exists($parent, 'getMenuContent'), "$section : $parent a une entrée de menu.");
            $this->assertArrayHasKey($section::right(), $rights, "$section : droit {$section::right()} existant.");
            $this->assertNotContains($parent, $parents, "$section : une seule rubrique pour $parent.");
            $parents[] = $parent;
        }
    }

    public function testEachSectionCanBePlacedWhereItAsks(): void
    {
        $core = \Html::getMenuInfos();
        foreach (Sections::SECTORS as $sector) {
            $declared = array_values(array_filter(
                $this->sections(),
                static fn (string $section): bool => $section::sector() === $sector
            ));
            $order = Sections::ordered($sector, false);
            $this->assertCount(count($declared), $order, "$sector : chaque rubrique est placée une fois.");
            foreach ($declared as $section) {
                $after = $section::after();
                if ($after === null || $after === Sections::FIRST) {
                    continue;
                }
                if (in_array($after, $order, true)) {
                    $this->assertLessThan(
                        array_search($section::parent(), $order, true),
                        array_search($after, $order, true),
                        "$section : placée après $after (pas de boucle)."
                    );
                    continue;
                }
                // Après une entrée du cœur de GLPI, dans un secteur du cœur.
                $this->assertNotSame(Sections::GRC, $sector, "$section : $after n'est pas une rubrique du menu GRC.");
                $types = array_map('strtolower', $core[$sector]['types'] ?? []);
                $this->assertContains(strtolower($after), $types, "$section : $after est une entrée de $sector.");
            }
        }
    }

    public function testEachSubPageExistsAndBelongsToASingleSection(): void
    {
        $parents = array_map(static fn (string $section): string => $section::parent(), $this->sections());
        $seen = [];
        foreach ($parents as $parent) {
            foreach (Sections::pages($parent) as $key => $options) {
                $this->assertArrayNotHasKey($key, $seen, "$key : une seule rubrique (déplacement : option from).");
                $seen[$key] = $parent;
                $this->assertNotContains($key, $parents, "$key : une rubrique n'est pas la sous-page d'une autre.");
                if (isset($options['from'])) {
                    $this->assertContains($options['from'], $parents, "$key : rubrique d'origine connue.");
                }
                if (isset($options['group'])) {
                    $this->assertIsString($options['group'], "$key : libellé de groupe.");
                }
                if (isset($options['page'])) {
                    $this->assertNotSame('', (string) ($options['title'] ?? ''), "$key : titre.");
                    $rightsOf = (string) ($options['rights_of'] ?? '');
                    $this->assertTrue(class_exists($rightsOf), "$key : droits empruntés.");
                    $this->assertPageFileExists((string) $options['page'], $key);
                    continue;
                }
                $this->assertTrue(class_exists($key), "$key : classe de la sous-page.");
                $this->assertTrue(method_exists($key, 'getMenuContent'), "$key : entrée de menu.");
            }
        }
        // Avec tous les droits, chaque entrée mène à une page front/ existante.
        foreach ($parents as $parent) {
            $menu = $parent::getMenuContent();
            if (is_array($menu) && isset($menu['page'])) {
                $this->assertPageFileExists((string) $menu['page'], $parent);
            }
            foreach (array_keys(Sections::pages($parent)) as $key) {
                $menu = Menu::pageMenu($key);
                if ($menu !== null) {
                    $this->assertPageFileExists((string) $menu['page'], $key);
                }
            }
        }
    }

    public function testNoSectionHasTooManySubPages(): void
    {
        foreach ($this->sections() as $section) {
            $count = count(Sections::pages($section::parent()));
            if ($section::overLimit() === '') {
                $this->assertLessThanOrEqual(Sections::MAX_PAGES, $count, "$section : 7 sous-pages au plus.");
            } else {
                $this->assertGreaterThan(Sections::MAX_PAGES, $count, "$section : exemption devenue inutile.");
            }
        }
    }

    public function testInactiveOrForbiddenSubPagesAreNeverShown(): void
    {
        $this->assertTrue(Sections::allowed([]));
        $this->assertFalse(Sections::allowed(['active' => false]), 'Module ou référentiel désactivé.');
        $this->assertTrue(Sections::allowed(['active' => true]));
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_gdpr'] = READ;
            $this->assertTrue(Sections::allowed(['right' => 'plugin_grcmanager_gdpr']));
            $this->assertFalse(Sections::allowed(['right' => ['plugin_grcmanager_gdpr', UPDATE]]));
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_gdpr'] = 0;
            $this->assertFalse(Sections::allowed(['right' => 'plugin_grcmanager_gdpr']), 'Droit manquant.');
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
        // Chaque option « actif si » et « droit requis » déclarée porte sur un droit existant.
        $rights = \ProfileRight::getAllPossibleRights();
        foreach ($this->sections() as $section) {
            foreach (Sections::pages($section::parent()) as $key => $options) {
                if (isset($options['right'])) {
                    $right = (string) ((array) $options['right'])[0];
                    $this->assertArrayHasKey($right, $rights, "$key : droit existant.");
                }
                if (array_key_exists('active', $options)) {
                    $this->assertIsBool($options['active'], "$key : « actif si » booléen.");
                }
            }
        }
    }

    public function testPagesTheProfileCannotOpenAreNotInTheMenu(): void
    {
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            foreach (array_keys($_SESSION['glpiactiveprofile']) as $right) {
                if (str_starts_with((string) $right, 'plugin_grcmanager')) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
            }
            foreach ($this->sections() as $section) {
                $parent = $section::parent();
                if (Sections::pages($parent) === []) {
                    // Rubrique sans sous-page : son entrée de menu (GLPI) décide seule.
                    continue;
                }
                $menu = $parent::getMenuContent();
                if (!(bool) $parent::canView() && is_array($menu)) {
                    $this->assertNotEmpty($menu['parent_hidden'] ?? null, "$parent : rubrique interdite cachée.");
                }
                foreach (array_keys(Sections::pages($parent)) as $key) {
                    if (class_exists($key) && property_exists($key, 'rightname') && (string) $key::$rightname !== ''
                        && !(bool) $key::canView()) {
                        $this->assertNull(Menu::pageMenu($key), "$key : page interdite absente du menu.");
                    }
                }
            }
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testHeaderAndBreadcrumbFollowTheDeclaration(): void
    {
        global $PLUGIN_HOOKS;

        $toAdd = $PLUGIN_HOOKS['menu_toadd']['grcmanager'];
        foreach ($this->sections() as $section) {
            $parent = $section::parent();
            $sector = $section::sector();
            $this->assertSame([$sector, $parent], Menu::sectorPath($parent), "$parent : en-tête de la rubrique.");
            if ($section::enabled()) {
                $this->assertContains($parent, $toAdd[$sector] ?? [], "$parent : entrée du secteur $sector.");
            }
            foreach (array_keys(Sections::pages($parent)) as $key) {
                $this->assertSame([$sector, $parent, $key], Menu::sectorPath($key), "$key : fil d'Ariane.");
                $this->assertNotContains($key, $toAdd[$sector] ?? [], "$key : pas d'entrée propre.");
            }
        }
        $this->assertSame(Menu::class, $toAdd[Sections::GRC][0], 'Ancre du menu GRC en tête.');
    }

    private function assertPageFileExists(string $page, string $key): void
    {
        $path = (string) parse_url($page, PHP_URL_PATH);
        $prefix = '/plugins/grcmanager/';
        $this->assertStringStartsWith($prefix, $path, "$key : page du plugin.");
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . substr($path, strlen($prefix)), "$key : $page existe.");
    }
}
