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
use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\Navigation\Section\IsmsOrganisationSection;
use GlpiPlugin\Grcmanager\Navigation\Section\RegulationSection;
use GlpiPlugin\Grcmanager\Navigation\Section\RiskSection;
use GlpiPlugin\Grcmanager\Navigation\SectionHome;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;

/**
 * Issue #1038 : menu GRC en rubriques aux noms parlants, chacune avec sa page d'accueil (ce qui est à
 * faire, puis les écrans en cartes groupées), et 7 sous-pages au plus sans exemption.
 */
final class SectionHomeTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FrameworkConfig::reset();
        Sections::reset();
    }

    protected function tearDown(): void
    {
        FrameworkConfig::reset();
        Sections::reset();
        parent::tearDown();
    }

    /** @return list<class-string<\GlpiPlugin\Grcmanager\Navigation\MenuSection>> */
    private function hubs(): array
    {
        $hubs = array_values(array_filter(Sections::all(), static fn (string $section): bool => $section::hub()));
        $this->assertNotSame([], $hubs, 'Rubriques avec page d\'accueil.');

        return $hubs;
    }

    public function testEachHubSectionLeadsToItsHomePageAndKeepsItsOwnPage(): void
    {
        foreach ($this->hubs() as $section) {
            $key = Sections::key($section);
            $this->assertSame($section, Sections::byKey($key), "$section : clé $key retrouvée.");
            $this->assertNotSame('', $section::description(), "$section : encadré « À quoi sert ».");
            $menu = $section::parent()::getMenuContent();
            $this->assertIsArray($menu, "$section : entrée de menu pour le super-admin.");
            $this->assertSame(Sections::hubUrl($section), $menu['page'], "$section : l'entrée mène à l'accueil.");
            $this->assertStringStartsWith('/plugins/grcmanager/front/', (string) $menu['section_page']);
            $this->assertStringNotContainsString('section.php', (string) $menu['section_page']);
            if ($section::title() !== '') {
                $this->assertSame($section::title(), $menu['title'], "$section : nom parlant de la rubrique.");
                $this->assertNotSame($menu['title'], $menu['section_label'] ?? '', "$section : onglet de la page.");
            }
        }
        $this->assertNull(Sections::byKey('unknown'));
    }

    public function testTodoSourcesAreKnownKindsOfMySpace(): void
    {
        $known = array_keys(MySpace::getSources());
        foreach (Sections::all() as $section) {
            foreach ($section::todoSources() as $source) {
                $this->assertContains($source, $known, "$section : sorte « $source » de Mon espace.");
            }
        }
    }

    public function testNoSectionOfTheGrcMenuNeedsAnExemptionAnyMore(): void
    {
        foreach (Sections::all() as $section) {
            if ($section::overLimit() !== '') {
                $this->assertNotSame('#1038', $section::overLimit(), "$section : découpée par ce lot.");
            }
        }
        $grc = Sections::ordered(Sections::GRC);
        $this->assertGreaterThanOrEqual(11, count($grc), 'Rubriques aux noms parlants plutôt que fourre-tout.');
    }

    public function testHomePageShowsTheScreensInGroupsAndTheParentFirst(): void
    {
        $data = SectionHome::data(RiskSection::class, (int) \Session::getLoginUserID(), date('Y-m-d'));
        $this->assertIsArray($data);
        $this->assertSame('risk', $data['key']);
        $this->assertTrue($data['has_todo']);
        $first = $data['groups'][0]['cards'][0];
        $this->assertStringEndsWith(Risk::getSearchURL(false), $first['url'], 'Le registre en premier.');
        $urls = array_merge(...array_map(
            static fn (array $group): array => array_column($group['cards'], 'url'),
            $data['groups']
        ));
        foreach (['riskheatmap.php', 'riskcatalog.php', 'riskimport.php'] as $page) {
            $found = array_filter($urls, static fn (string $url): bool => str_ends_with($url, $page));
            $this->assertNotSame([], $found, "$page : écran autrefois hors menu, maintenant dans Risques.");
        }
        $this->assertLessThanOrEqual(SectionHome::MAX_TODO, count($data['todo']));
    }

    public function testDelegatedDecisionsAndNationalRegistersAreReachableFromTheMenu(): void
    {
        $this->assertSame(
            [Sections::GRC, \GlpiPlugin\Grcmanager\ContextItem::class, Delegation::class],
            Menu::sectorPath(Delegation::class),
            'Décisions déléguées : Organisation du SMSI, plus le secteur « Outils ».'
        );
        $this->assertSame(IsmsOrganisationSection::class, Sections::sectionOf(\GlpiPlugin\Grcmanager\ContextItem::class));

        foreach (['nis2', 'recyf', 'acn_it'] as $framework) {
            FrameworkConfig::setEnabled($framework, true);
        }
        FrameworkConfig::reset();
        $options = \GlpiPlugin\Grcmanager\Referentials::getMenuContent()['options'] ?? [];
        $this->assertArrayHasKey(RegulationSection::RECYF, $options);
        $this->assertArrayHasKey(RegulationSection::ACN, $options);

        FrameworkConfig::setEnabled('recyf', false);
        FrameworkConfig::reset();
        $options = \GlpiPlugin\Grcmanager\Referentials::getMenuContent()['options'] ?? [];
        $this->assertArrayNotHasKey(RegulationSection::RECYF, $options, 'Référentiel désactivé : absent.');
    }

    public function testHomePageIsRefusedWithoutAnyRightOnTheSection(): void
    {
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            foreach (array_keys($_SESSION['glpiactiveprofile']) as $right) {
                if (str_starts_with((string) $right, 'plugin_grcmanager')) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
            }
            $this->assertNull(SectionHome::data(RiskSection::class, (int) \Session::getLoginUserID(), date('Y-m-d')));
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testGroupsKeepTheOrderOfTheirFirstCard(): void
    {
        $card = static fn (string $title, string $group): array => [
            'title' => $title, 'icon' => '', 'url' => '/' . $title, 'group' => $group,
        ];
        $groups = SectionHome::groups([$card('a', 'G1'), $card('b', ''), $card('c', 'G1'), $card('d', 'G2')]);
        $this->assertSame(['G1', '', 'G2'], array_column($groups, 'label'));
        $this->assertSame(['a', 'c'], array_column($groups[0]['cards'], 'title'));
    }
}
