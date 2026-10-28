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

/**
 * Encadré « À quoi sert cette page ? » : chaque texte correspond à une page qui l'affiche.
 */
final class PageHelpTest extends GrcmanagerIntegrationTestCase
{
    public function testEveryHelpTextIsShownByItsPage(): void
    {
        $front = dirname(__DIR__, 2) . '/front/';
        foreach (\GlpiPlugin\Grcmanager\PageHelp::texts() as $page => $text) {
            $this->assertFileExists($front . $page . '.php');
            $this->assertStringContainsString(
                "GlpiPlugin\\Grcmanager\\PageHelp::show('" . $page . "')",
                (string) file_get_contents($front . $page . '.php'),
                $page
            );
            $this->assertNotSame('', $text['purpose']);
            $this->assertNotSame('', $text['expected']);
        }
    }

    public function testBoxRendersWithTranslatedTitle(): void
    {
        ob_start();
        \GlpiPlugin\Grcmanager\PageHelp::show('risk');
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(__('What is this page for?', 'grcmanager'), $html);
        $this->assertStringContainsString('grc-help-risk', $html);

        ob_start();
        \GlpiPlugin\Grcmanager\PageHelp::show('page-inconnue');
        $this->assertSame('', (string) ob_get_clean());
    }
}
