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

use ComputerModel;
use GlpiPlugin\Grcmanager\AssetSecurity;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\HardwareModelEol;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Section\AssetSecuritySection;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareModelEolStore;

/**
 * Issue #1039 : Parc > Sécurité du parc. Rubrique du secteur Parc, page d'accueil en cartes groupées,
 * barre à onglets repliés, onglet « Fin de vie constructeur » des modèles et actions sorties de la
 * configuration.
 */
final class AssetSecurityTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sections::reset();
    }

    protected function tearDown(): void
    {
        Sections::reset();
        HardwareModelEolStore::reset();
        parent::tearDown();
    }

    public function testTheSectionIsInTheAssetsMenuWithTheTechnicalScreens(): void
    {
        global $PLUGIN_HOOKS;

        $this->assertSame('assets', AssetSecuritySection::sector());
        $this->assertContains(AssetSecurity::class, $PLUGIN_HOOKS['menu_toadd']['grcmanager']['assets'] ?? []);
        $this->assertNotContains(ComplianceAudit::class, $PLUGIN_HOOKS['menu_toadd']['grcmanager']['grcmanager']);
        $pages = Sections::pages(AssetSecurity::class);
        $moved = [ComplianceAudit::class, ParkVulnerability::class, PhysicalSecurity::class, HardwareModelEol::class];
        foreach ($moved as $page) {
            $this->assertArrayHasKey($page, $pages, $page);
            $path = Menu::sectorPath($page);
            $this->assertSame(['assets', AssetSecurity::class, $page], $path, "$page : fil d'Ariane.");
        }
        // Sécurité physique, développement et code ont quitté la rubrique des mesures.
        $this->assertArrayNotHasKey(PhysicalSecurity::class, Sections::pages(Control::class));
        $this->assertSame(['assets', AssetSecurity::class], Menu::sectorPath(AssetSecurity::class));
    }

    public function testTheTabBarShowsAtMostSevenTabsThanksToFoldedGroups(): void
    {
        $this->assertGreaterThan(Sections::MAX_PAGES, count(Sections::pages(AssetSecurity::class)));
        $this->assertLessThanOrEqual(Sections::MAX_PAGES, Sections::tabCount(AssetSecurity::class));
        $this->assertCount(count(AssetSecuritySection::groups()), Sections::tabGroups(AssetSecurity::class));
        foreach (Sections::tabGroups(AssetSecurity::class) as $keys) {
            $this->assertLessThanOrEqual(Sections::MAX_PAGES, count($keys));
        }
    }

    public function testFoldTabGroupsKeepsOneTabPerGroupAndShowsTheOpenGroup(): void
    {
        $tab = static fn (string $label, string $group, bool $active = false): array => [
            'label'          => $label,
            'icon'           => 'ti ti-x',
            'url'            => '/' . $label,
            'active'         => $active,
            'group'          => '',
            'tab_group'      => $group,
            'tab_group_icon' => $group === '' ? '' : 'ti ti-' . $group,
        ];
        [$tabs, $sub] = Menu::foldTabGroups([
            $tab('home', ''), $tab('a1', 'A'), $tab('a2', 'A', true), $tab('b1', 'B'), $tab('b2', 'B'),
        ]);
        $this->assertSame(['home', 'A', 'B'], array_column($tabs, 'label'));
        $this->assertSame([false, true, false], array_column($tabs, 'active'));
        $this->assertSame('/a1', $tabs[1]['url'], 'Le groupe mène à sa première page.');
        $this->assertSame('ti ti-A', $tabs[1]['icon']);
        $this->assertSame(['a1', 'a2'], array_column($sub, 'label'));

        [, $none] = Menu::foldTabGroups([$tab('home', '', true), $tab('a1', 'A'), $tab('a2', 'A')]);
        $this->assertSame([], $none, 'Sur la page parente, pas de seconde barre.');
    }

    public function testTheHomePageGroupsTheVisibleScreensAndHidesForbiddenOnes(): void
    {
        $this->assertTrue(AssetSecurity::canView());
        $groups = AssetSecurity::homeGroups();
        $this->assertNotSame([], $groups);
        $urls = array_merge(...array_map(
            static fn (array $group): array => array_column($group['cards'], 'url'),
            $groups
        ));
        $this->assertContains(HardwareModelEol::getSearchURL(), $urls);
        $menu = AssetSecurity::getMenuContent();
        $this->assertIsArray($menu);
        $this->assertArrayHasKey(HardwareModelEol::class, $menu['options']);

        $saved = $_SESSION['glpiactiveprofile'];
        try {
            foreach (array_keys($_SESSION['glpiactiveprofile']) as $right) {
                if (str_starts_with((string) $right, 'plugin_grcmanager')) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
            }
            $this->assertFalse(HardwareModelEol::canView());
            $this->assertNull(Menu::pageMenu(HardwareModelEol::class));
            foreach (AssetSecurity::homeGroups() as $group) {
                $this->assertNotContains(HardwareModelEol::getSearchURL(), array_column($group['cards'], 'url'));
            }
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testModelFormsHaveTheEndOfLifeTab(): void
    {
        $this->assertSame('Computer', HardwareModelEol::modelItemtypes()[ComputerModel::class] ?? '');
        $model = new ComputerModel();
        $id = (int) $model->add(['name' => 'Model end of life test']);
        $this->assertGreaterThan(0, $id);
        $model->getFromDB($id);
        $this->assertNotSame('', (new HardwareModelEol())->getTabNameForItem($model));

        $this->assertTrue(HardwareModelEolStore::save(ComputerModel::class, $id, '2020-01-31', 2));
        $data = HardwareModelEol::tabData($model);
        $this->assertSame('2020-01-31', $data['date']);
        $this->assertSame(ComputerModel::class . ':' . $id, $data['field']);
        $this->assertIsArray($data['status']);
        $this->assertSame('danger', $data['status']['color'], 'Fin de vie atteinte.');
        $this->assertSame(0, $data['assets']);

        ob_start();
        $shown = HardwareModelEol::displayTabContentForItem($model);
        $html = (string) ob_get_clean();
        $this->assertTrue($shown);
        $this->assertStringContainsString('name="hardware_eol[' . ComputerModel::class . ':' . $id . ']"', $html);
        $this->assertStringContainsString('value="2020-01-31"', $html);
    }

    public function testMovedActionsLeftTheConfiguration(): void
    {
        $this->assertSame(
            '/plugins/grcmanager/front/hardwaremodeleol.php',
            ConfigTabs::anchorUrl(HardwareModelEol::OLD_ANCHOR, false)
        );
        $this->assertNotContains('HardwareEol', array_map(
            static fn (string $block): string => $block::key(),
            ConfigTabs::blocksOf('assets')
        ));
        $root = dirname(__DIR__, 2);
        $eol = (string) file_get_contents($root . '/templates/config/blocks/_endoflife.html.twig');
        foreach (['refresh_endoflife', 'add_endoflife_mapping', 'delete_endoflife_mapping'] as $button) {
            $this->assertStringNotContainsString('name="' . $button . '"', $eol, $button);
        }
        $digest = (string) file_get_contents($root . '/templates/config/blocks/_fleet_vulnerabilities.html.twig');
        $this->assertStringNotContainsString('name="send_vulnerability_digest"', $digest);
        $this->assertStringContainsString(
            'name="send_vulnerability_digest"',
            (string) file_get_contents($root . '/templates/park_vulnerabilities.html.twig')
        );
        $this->assertStringContainsString(
            'name="refresh_endoflife"',
            (string) file_get_contents($root . '/templates/_endoflife_links.html.twig')
        );
    }
}
