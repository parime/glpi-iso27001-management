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

use Glpi\Dashboard\Dashboard;

/**
 * Issue #247 : entrée « Tableau de bord » du menu GRC & Conformité.
 */
final class DashboardMenuTest extends GrcmanagerIntegrationTestCase
{
    use ForcesEnglishLanguage;

    public function testMenuTitleAndFirstEntryOpenTheDashboard(): void
    {
        global $PLUGIN_HOOKS;

        $url = \GlpiPlugin\Grcmanager\Dashboard::getSearchURL(false);
        $this->assertSame('/plugins/grcmanager/front/dashboard.php', $url);
        $this->assertSame($url, \GlpiPlugin\Grcmanager\Menu::getMenuContent()['page']);

        $entries = $PLUGIN_HOOKS['menu_toadd']['grcmanager']['grcmanager'];
        $this->assertSame(\GlpiPlugin\Grcmanager\Dashboard::class, $entries[1], 'Juste après le titre du menu.');
    }

    /**
     * Rubriques regroupant plusieurs écrans d'un même travail : une seule entrée de menu, les
     * autres écrans en sous-pages (options GLPI) de la rubrique.
     */
    public function testGroupedScreensAreSubPagesOfASingleEntry(): void
    {
        global $PLUGIN_HOOKS;

        // Issue #540 : le registre DORA n'apparaît qu'avec son référentiel, optionnel (annulé avec la transaction).
        \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('dora', true);
        // Issue #1038 : registres ReCyF et ACN, sous-pages de « Réglementations » avec NIS2 et leur déclinaison.
        foreach (['nis2', 'recyf', 'acn_it'] as $framework) {
            \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled($framework, true);
        }
        \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::reset();
        $entries = array_merge(...array_values($PLUGIN_HOOKS['menu_toadd']['grcmanager']));
        $this->assertSame(count($entries), count(array_unique($entries)), 'Aucune entrée en double.');
        foreach (\GlpiPlugin\Grcmanager\Menu::sections() as $parent => $children) {
            // Issues #1039 et #1041 : une rubrique d'un secteur du cœur (Parc, Assistance...) a son entrée
            // dans ce secteur ; aucune sous-page n'a d'entrée propre, quel que soit le secteur.
            $sector = \GlpiPlugin\Grcmanager\Navigation\Sections::locate($parent)['sector'] ?? 'grcmanager';
            $sectorEntries = $PLUGIN_HOOKS['menu_toadd']['grcmanager'][$sector] ?? [];
            $this->assertContains($parent, $sectorEntries, "$parent : la rubrique a son entrée.");
            $options = $parent::getMenuContent()['options'] ?? [];
            foreach ($children as $child) {
                $this->assertNotContains($child, $entries, "$child : pas d'entrée propre.");
                $this->assertArrayHasKey($child, $options, "$child : sous-page de $parent.");
            }
        }
        $this->assertSame(
            'Automated technical checks',
            \GlpiPlugin\Grcmanager\ComplianceAudit::getTypeName()
        );
        \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::reset();
    }

    /**
     * Issue #375 : un droit propre à une sous-page suffit à la trouver dans le menu (DPO avec le seul
     * droit RGPD), sans rien montrer d'autre de la rubrique.
     */
    public function testSubPageRightAloneKeepsItInTheMenuWithoutOpeningTheRest(): void
    {
        $control = \GlpiPlugin\Grcmanager\Control::$rightname;
        $gdpr = \GlpiPlugin\Grcmanager\ProcessingActivity::RIGHTNAME;
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            foreach (array_keys($_SESSION['glpiactiveprofile']) as $right) {
                if (str_starts_with((string) $right, 'plugin_grcmanager')) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
            }
            $this->assertFalse(
                \GlpiPlugin\Grcmanager\Control::getMenuContent(),
                'Sans aucun droit du plugin : aucune entrée.'
            );

            // Issue #1038 : le registre RGPD a sa propre rubrique « Vie privée (RGPD) ».
            $_SESSION['glpiactiveprofile'][$gdpr] = READ;
            $this->assertFalse(
                \GlpiPlugin\Grcmanager\Control::getMenuContent(),
                'Le droit RGPD seul ne montre pas la SoA.'
            );
            $menu = \GlpiPlugin\Grcmanager\ProcessingActivity::getMenuContent();
            $this->assertIsArray($menu);
            $this->assertSame(
                \GlpiPlugin\Grcmanager\ProcessingActivity::getSearchURL(false),
                $menu['section_page'],
                'Le registre des traitements garde son onglet.'
            );
            $this->assertSame(
                [
                    \GlpiPlugin\Grcmanager\DataSubjectRequest::class,
                    // Issue #620 : registre des violations, même droit RGPD.
                    \GlpiPlugin\Grcmanager\DataBreach::class,
                ],
                array_keys($menu['options']),
                'Les seules sous-pages couvertes par le droit RGPD.'
            );

            $_SESSION['glpiactiveprofile'][$gdpr] = 0;
            $_SESSION['glpiactiveprofile'][$control] = READ;
            $menu = \GlpiPlugin\Grcmanager\Control::getMenuContent();
            $this->assertArrayNotHasKey('parent_hidden', $menu);
            $this->assertFalse(
                \GlpiPlugin\Grcmanager\ProcessingActivity::getMenuContent(),
                'Le droit général ne donne pas accès au registre RGPD.'
            );
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testDefaultDashboardIsVisibleToTheAdministrator(): void
    {
        $dashboard = new Dashboard(\GlpiPlugin\Grcmanager\Dashboard::getDashboardKey());
        $this->assertTrue($dashboard->canViewCurrent());
    }
}
