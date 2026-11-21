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
use GlpiPlugin\Grcmanager\Feature\Security360CoreSourcesFeature;
use PHPUnit\Framework\TestCase;

/**
 * Issue #837 : les sources de la vue « Sécurité 360° » sont découvertes dans le registre des
 * fonctionnalités, comme les preuves : une nouvelle source n'a qu'à ajouter son descripteur.
 */
final class AssetSecuritySourcesTest extends TestCase
{
    public function testTheCoreSourcesAreDiscovered(): void
    {
        $this->assertContains(
            Security360CoreSourcesFeature::class,
            FeatureRegistry::implementing(AssetSecuritySourcesProvider::class)
        );
    }
}
