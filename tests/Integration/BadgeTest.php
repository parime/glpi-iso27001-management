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

use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * Issue #199 : badges partagés rendus en Twig, même HTML qu'avant la migration.
 */
final class BadgeTest extends GrcmanagerIntegrationTestCase
{
    public function testBadgeHtmlIsUnchangedAndEscaped(): void
    {
        $this->assertSame(
            '<span class="badge bg-red-lt"><i class="ti ti-alert-triangle me-1"></i>Critique</span>',
            Badge::html('bg-red-lt', 'ti-alert-triangle', 'Critique')
        );
        $this->assertSame('<span class="badge bg-green-lt">Faible</span>', Badge::html('bg-green-lt', null, 'Faible'));
        $this->assertSame(
            '<span class="badge bg-blue-lt">&lt;script&gt;</span>',
            Badge::html('bg-blue-lt', null, '<script>')
        );
    }

    public function testRiskLevelBadgeKeepsItsIconForHighLevels(): void
    {
        $this->assertStringContainsString('ti-alert-triangle', \PluginGrcmanagerRisk::riskLevelBadge('critical'));
        $this->assertStringNotContainsString('<i ', \PluginGrcmanagerRisk::riskLevelBadge('low'));
    }
}
