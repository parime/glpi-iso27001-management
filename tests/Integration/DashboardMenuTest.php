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
    public function testMenuTitleAndFirstEntryOpenTheDashboard(): void
    {
        global $PLUGIN_HOOKS;

        $url = \PluginGrcmanagerDashboard::getSearchURL(false);
        $this->assertSame('/plugins/grcmanager/front/dashboard.php', $url);
        $this->assertSame($url, \PluginGrcmanagerMenu::getMenuContent()['page']);

        $entries = $PLUGIN_HOOKS['menu_toadd']['grcmanager']['grcmanager'];
        $this->assertSame(\PluginGrcmanagerDashboard::class, $entries[1], 'Juste après le titre du menu.');
    }

    /**
     * Rubriques regroupant plusieurs écrans d'un même travail : une seule entrée de menu, les
     * autres écrans en sous-pages (options GLPI) de la rubrique.
     */
    public function testGroupedScreensAreSubPagesOfASingleEntry(): void
    {
        global $PLUGIN_HOOKS;

        $entries = $PLUGIN_HOOKS['menu_toadd']['grcmanager']['grcmanager'];
        $this->assertSame(count($entries), count(array_unique($entries)), 'Aucune entrée en double.');
        foreach (\PluginGrcmanagerMenu::sections() as $parent => $children) {
            $this->assertContains($parent, $entries, "$parent : la rubrique a son entrée.");
            $options = $parent::getMenuContent()['options'] ?? [];
            foreach ($children as $child) {
                $this->assertNotContains($child, $entries, "$child : pas d'entrée propre.");
                $this->assertArrayHasKey($child, $options, "$child : sous-page de $parent.");
            }
        }
        $this->assertSame(
            __('Automated technical checks', 'grcmanager'),
            \GlpiPlugin\Grcmanager\ComplianceAudit::getTypeName()
        );
    }

    public function testDefaultDashboardIsVisibleToTheAdministrator(): void
    {
        $dashboard = new Dashboard(\PluginGrcmanagerDashboard::getDashboardKey());
        $this->assertTrue($dashboard->canViewCurrent());
    }
}
