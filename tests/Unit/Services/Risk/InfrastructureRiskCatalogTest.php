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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Issue #178 : catalogue de risques types d'infrastructure.
 */
final class InfrastructureRiskCatalogTest extends TestCase
{
    public function testEveryEntryUsesARegisterCategoryAndAnnexAControls(): void
    {
        $this->assertGreaterThanOrEqual(10, count(InfrastructureRiskCatalog::keys()));
        foreach (InfrastructureRiskCatalog::keys() as $key) {
            $this->assertContains(
                InfrastructureRiskCatalog::category($key),
                ['people', 'process', 'physical', 'third_party', 'technical'],
                $key
            );
            $this->assertNotEmpty(InfrastructureRiskCatalog::controls($key), $key);
            foreach (InfrastructureRiskCatalog::controls($key) as $code) {
                $this->assertMatchesRegularExpression('/^A\.[5-8]\.\d{1,2}$/', $code, $key);
            }
        }
    }

    public function testToImportKeepsValidNewKeysOnce(): void
    {
        $this->assertSame(
            ['spof_power', 'guest_network'],
            InfrastructureRiskCatalog::toImport(
                ['spof_power', 'inconnu', 'admin_exposed', 'spof_power', 'guest_network'],
                ['admin_exposed']
            )
        );
        $this->assertSame([], InfrastructureRiskCatalog::toImport([], []));
    }
}
