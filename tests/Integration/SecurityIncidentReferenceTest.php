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

use PluginGrcmanagerSecurityIncident;

/**
 * Issue #372 : référence SEC-AAAA-NNNN attribuée à la création, numérotée par année, jamais
 * réattribuée.
 */
final class SecurityIncidentReferenceTest extends GrcmanagerIntegrationTestCase
{
    public function testReferencesFollowEachOtherWithinTheYear(): void
    {
        $first = new PluginGrcmanagerSecurityIncident();
        $firstId = (int) $first->add([
            'name' => 'PHPUnit référence 1',
            'date' => '2031-03-01 10:00:00',
            'entities_id' => 0,
        ]);
        $second = new PluginGrcmanagerSecurityIncident();
        $secondId = (int) $second->add([
            'name' => 'PHPUnit référence 2',
            'date' => '2031-04-01 10:00:00',
            'entities_id' => 0,
        ]);

        $first->getFromDB($firstId);
        $second->getFromDB($secondId);
        $this->assertMatchesRegularExpression('/^SEC-2031-\d{4}$/', $first->fields['reference']);
        $this->assertSame(
            (int) substr($first->fields['reference'], 9) + 1,
            (int) substr($second->fields['reference'], 9)
        );

        // Jamais réattribuée.
        PluginGrcmanagerSecurityIncident::assignReference($firstId);
        $first->getFromDB($firstId);
        $this->assertSame('SEC-2031-' . substr($first->fields['reference'], 9), $first->fields['reference']);
    }
}
