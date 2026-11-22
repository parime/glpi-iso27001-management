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

namespace GlpiPlugin\Grcmanager\Tests\Unit;

use GlpiPlugin\Grcmanager\Navigation\Sections;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1037 : GLPI ajoute les entrées des plugins à la fin d'un secteur ; une rubrique peut demander
 * à suivre une entrée du cœur (hook redefine_menus, Sections::reorderMenu()).
 */
final class NavigationSectionsTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private const HELPDESK = [
        'ticket'          => ['title' => 'Tickets'],
        'problem'         => ['title' => 'Problems'],
        'change'          => ['title' => 'Changes'],
        'recurrentchange' => ['title' => 'Recurrent changes'],
        'pluginincident'  => ['title' => 'Security incidents'],
    ];

    public function testMovesAPluginEntryRightAfterACoreEntry(): void
    {
        $this->assertSame(
            ['ticket', 'problem', 'change', 'pluginincident', 'recurrentchange'],
            array_keys(Sections::moveAfter(self::HELPDESK, 'pluginincident', 'change'))
        );
    }

    public function testMovesAPluginEntryFirst(): void
    {
        $this->assertSame(
            ['pluginincident', 'ticket', 'problem', 'change', 'recurrentchange'],
            array_keys(Sections::moveAfter(self::HELPDESK, 'pluginincident', Sections::FIRST))
        );
    }

    public function testLeavesTheMenuUnchangedWhenAnEntryIsMissing(): void
    {
        // Entrée cachée faute de droit, ou entrée du cœur absente : rien ne bouge.
        $this->assertSame(self::HELPDESK, Sections::moveAfter(self::HELPDESK, 'absent', 'change'));
        $this->assertSame(self::HELPDESK, Sections::moveAfter(self::HELPDESK, 'pluginincident', 'absent'));
    }

    public function testKeepsEntryContent(): void
    {
        $moved = Sections::moveAfter(self::HELPDESK, 'pluginincident', 'ticket');
        $this->assertSame(self::HELPDESK['pluginincident'], $moved['pluginincident']);
        $this->assertCount(count(self::HELPDESK), $moved);
    }
}
