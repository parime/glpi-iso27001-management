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

use GlpiPlugin\Grcmanager\Services\Ui\Fragment;

/**
 * Issue #199 : liens et contenus de tickets rendus en Twig, même HTML qu'avant, texte échappé.
 */
final class FragmentTest extends GrcmanagerIntegrationTestCase
{
    public function testLinkWithAndWithoutCode(): void
    {
        $this->assertSame(
            '<a href="/front/x.php?id=1"><strong>A.5.1</strong> - Politiques &amp; procédures</a>',
            Fragment::link('/front/x.php?id=1', 'Politiques & procédures', 'A.5.1')
        );
        $this->assertSame('<a href="/f?id=2">Risque</a>', Fragment::link('/f?id=2', 'Risque'));
        $this->assertSame('<strong>4.1</strong> - Contexte', Fragment::link('', 'Contexte', '4.1'));
        $this->assertSame(
            '<a href="/c"><i class="ti ti-shield-check me-1"></i>Pentest</a>',
            Fragment::link('/c', 'Pentest', '', 'ti-shield-check')
        );
    }

    public function testText(): void
    {
        $this->assertSame('<i class="ti ti-shield-plus"></i>Qualifier', Fragment::text('Qualifier', 'ti-shield-plus'));
        $this->assertSame(
            '<span class="text-muted">Non classifié</span>',
            Fragment::text('Non classifié', null, 'text-muted')
        );
    }

    public function testTicketContent(): void
    {
        $this->assertSame(
            '<p>Première &lt;ligne&gt;</p><p>Seconde</p><p><a href="https://glpi/x">Ouvrir</a></p>',
            Fragment::ticketContent(['Première <ligne>', 'Seconde'], 'https://glpi/x', 'Ouvrir')
        );
        $this->assertSame('<p>Seule</p>', Fragment::ticketContent(['Seule']));
    }

    public function testControlListShowsTheCodeAsALink(): void
    {
        $html = \PluginGrcmanagerControl::getSpecificValueToDisplay('code', ['code' => 'A.5.1', 'id' => 7]);
        $this->assertStringContainsString('<strong>A.5.1</strong> - ', $html);
        $this->assertStringContainsString(\PluginGrcmanagerControl::getFormURLWithID(7), $html);
    }
}
