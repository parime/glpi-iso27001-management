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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Continuity;

use GlpiPlugin\Grcmanager\Services\Continuity\DependencyMapExport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #701 : formats et nom du fichier de l'export de la carte des dépendances. Processus fictifs.
 */
final class DependencyMapExportTest extends TestCase
{
    public function testFormatsArePngJpegAndSvgWithTheirMimeType(): void
    {
        $this->assertSame([
            ['format' => 'png', 'mime' => 'image/png', 'vector' => false],
            ['format' => 'jpg', 'mime' => 'image/jpeg', 'vector' => false],
            ['format' => 'svg', 'mime' => 'image/svg+xml', 'vector' => true],
        ], DependencyMapExport::formats());
    }

    public function testFileNameIsLowercaseWithDashesAndKeepsAccents(): void
    {
        $this->assertSame(
            'carte-des-dépendances-paie-des-salariés-2026-11-18',
            DependencyMapExport::fileName('Carte des dépendances', 'Paie des salariés', '2026-11-18')
        );
        $this->assertSame(
            'dependency-map-billing-2026-01-05',
            DependencyMapExport::fileName('Dependency map', '  Billing  ', '2026-01-05')
        );
    }

    public function testFileNameNeverContainsPathSeparatorsOrCharactersForbiddenByWindows(): void
    {
        $name = DependencyMapExport::fileName(
            'Dependency map',
            '../..\\Paie <RH> : "test" | 2026 * ? <script>alert(1)</script>' . "\n\0",
            '2026-11-18'
        );
        $this->assertSame('dependency-map-paie-rh-test-2026-script-alert-1-script-2026-11-18', $name);
        $this->assertDoesNotMatchRegularExpression('#[/\\\\<>:"|?*\x00-\x1f.]#', $name);
    }

    public function testEmptyNamesFallBackAndInvalidDateIsDropped(): void
    {
        $this->assertSame('dependency-map-2026-11-18', DependencyMapExport::fileName('', '***', '2026-11-18'));
        $this->assertSame('dependency-map-paie', DependencyMapExport::fileName('---', 'Paie', '18/11/2026'));
    }

    public function testFileNameIsBoundedButKeepsTheDate(): void
    {
        $name = DependencyMapExport::fileName('Carte', str_repeat('Processus très long ', 20), '2026-11-18');
        $this->assertLessThanOrEqual(DependencyMapExport::MAX_NAME_LENGTH, mb_strlen($name, 'UTF-8'));
        $this->assertStringEndsWith('-2026-11-18', $name);
        $this->assertStringNotContainsString('--', $name);
        $this->assertStringStartsWith('carte-processus-très-long-', $name);
    }
}
