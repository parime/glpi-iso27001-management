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

use GlpiPlugin\Grcmanager\Install\Installer;

/**
 * Onglet « GRC Manager » des profils : matrice des droits du plugin, protégée par le droit
 * « profile » du cœur et réservée aux profils en interface standard.
 */
final class ProfileRightsTabTest extends GrcmanagerIntegrationTestCase
{
    public function testTabShowsTheThreePluginRightsOnACentralProfile(): void
    {
        $profile = $this->profile('central');
        $tab = new \PluginGrcmanagerProfile();

        $this->assertNotSame('', $tab->getTabNameForItem($profile));

        ob_start();
        $shown = \PluginGrcmanagerProfile::displayTabContentForItem($profile);
        $html = (string) ob_get_clean();

        $this->assertTrue($shown);
        $rights = [Installer::RIGHT_NAME, Installer::SECURITY_INCIDENT_RIGHT, Installer::SECURITY_INCIDENT_RULE_RIGHT];
        foreach ($rights as $right) {
            $this->assertStringContainsString('name="_' . $right . '[', $html);
        }
        // Corbeille des dérogations et des incidents : la colonne doit exister pour le droit partagé.
        $this->assertStringContainsString('name="_' . Installer::RIGHT_NAME . '[' . DELETE . '_0]"', $html);
    }

    public function testTabIsHiddenOnASimplifiedInterfaceProfile(): void
    {
        $profile = $this->profile('helpdesk');

        $this->assertSame('', (new \PluginGrcmanagerProfile())->getTabNameForItem($profile));
        $this->assertFalse(\PluginGrcmanagerProfile::displayTabContentForItem($profile));
    }

    public function testTabIsHiddenWithoutTheCoreProfileRight(): void
    {
        $profile = $this->profile('central');
        $saved = $_SESSION['glpiactiveprofile']['profile'] ?? 0;
        $_SESSION['glpiactiveprofile']['profile'] = 0;
        try {
            $this->assertSame('', (new \PluginGrcmanagerProfile())->getTabNameForItem($profile));
            // ajax/common.tabs.php appelle directement le contenu : il doit refuser lui aussi.
            ob_start();
            $shown = \PluginGrcmanagerProfile::displayTabContentForItem($profile);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
        } finally {
            $_SESSION['glpiactiveprofile']['profile'] = $saved;
        }
    }

    public function testTabIsHiddenWithoutAccessToThePlugin(): void
    {
        $profile = $this->profile('central');
        $saved = $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = 0;
        try {
            $this->assertSame('', (new \PluginGrcmanagerProfile())->getTabNameForItem($profile));
            ob_start();
            $shown = \PluginGrcmanagerProfile::displayTabContentForItem($profile);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $saved;
        }
    }

    private function profile(string $interface): \Profile
    {
        $profile = new \Profile();
        $id = $profile->add(['name' => 'grcmanager-tab-' . $interface . '-' . mt_rand(), 'interface' => $interface]);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($profile->getFromDB($id));

        return $profile;
    }
}
