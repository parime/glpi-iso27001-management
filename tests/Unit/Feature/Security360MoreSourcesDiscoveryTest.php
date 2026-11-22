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

namespace GlpiPlugin\Grcmanager\Tests\Feature;

use GlpiPlugin\Grcmanager\Feature\AssetSecuritySourcesProvider;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Feature\Security360MoreSourcesFeature;
use PHPUnit\Framework\TestCase;

/**
 * Issue #837 : les sources restantes (Zabbix, sauvegardes, journalisation, correctifs, fin de vie,
 * EDR, classification) sont découvertes dans le registre, sans toucher à l'onglet.
 */
final class Security360MoreSourcesDiscoveryTest extends TestCase
{
    public function testTheRemainingSourcesAreDiscovered(): void
    {
        $this->assertContains(
            Security360MoreSourcesFeature::class,
            FeatureRegistry::implementing(AssetSecuritySourcesProvider::class)
        );
    }
}
