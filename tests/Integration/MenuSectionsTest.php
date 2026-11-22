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
                    $this->assertTrue(class_exists((string) ($options['rights_of'] ?? '')), "$key : droits empruntés.");
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
