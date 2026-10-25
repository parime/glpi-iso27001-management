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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Capa;

use GlpiPlugin\Grcmanager\Services\Capa\CveCapaRules;
use PHPUnit\Framework\TestCase;

final class CveCapaRulesTest extends TestCase
{
    public function testSeverity(): void
    {
        $this->assertSame('critical', CveCapaRules::severity(4.0, true));
        $this->assertSame('critical', CveCapaRules::severity(9.8, false));
        $this->assertSame('major', CveCapaRules::severity(7.5, false));
        $this->assertSame('minor', CveCapaRules::severity(5.0, false));
        $this->assertSame('minor', CveCapaRules::severity(null, false));
    }

    public function testDueDateUsesTheCloserCisaDeadline(): void
    {
        $this->assertSame('2099-01-08', CveCapaRules::dueDate('critical', '', '2099-01-01'));
        $this->assertSame('2099-01-31', CveCapaRules::dueDate('major', '', '2099-01-01'));
        $this->assertSame('2099-04-01', CveCapaRules::dueDate('minor', '', '2099-01-01'));
        $closer = CveCapaRules::dueDate('critical', '2099-01-05', '2099-01-01');
        $this->assertSame('2099-01-05', $closer, 'CISA plus proche.');
        $this->assertSame('2099-01-08', CveCapaRules::dueDate('critical', '2098-12-01', '2099-01-01'), 'CISA passée.');
        $further = CveCapaRules::dueDate('major', '2099-03-01', '2099-01-01');
        $this->assertSame('2099-01-31', $further, 'CISA plus lointaine.');
    }

    public function testGroupLinks(): void
    {
        $links = [['url' => 'https://editeur.example/patch', 'tag' => 'Patch']];
        $links[] = ['url' => 'https://editeur.example/avis', 'tag' => 'Vendor Advisory'];
        $links[] = ['url' => 'https://editeur.example/patch', 'tag' => 'Patch'];
        for ($i = 0; $i < 8; $i++) {
            $links[] = ['url' => 'https://editeur.example/avis' . $i, 'tag' => 'Vendor Advisory'];
        }
        $grouped = CveCapaRules::groupLinks($links);
        $this->assertSame(['https://editeur.example/patch'], $grouped['patch'], 'Sans doublon.');
        $this->assertCount(5, $grouped['advisory'], 'Cinq liens au plus.');
    }
}
