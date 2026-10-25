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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Impact;

use GlpiPlugin\Grcmanager\Services\Impact\RecoveryObjectives;
use PHPUnit\Framework\TestCase;

final class RecoveryObjectivesTest extends TestCase
{
    public function testSanitizeKeepsOnlyProposedDurations(): void
    {
        $this->assertSame(4, RecoveryObjectives::sanitize('4'));
        $this->assertSame(168, RecoveryObjectives::sanitize(168));
        $this->assertSame(0, RecoveryObjectives::sanitize('5'));
        $this->assertSame(0, RecoveryObjectives::sanitize('<script>'));
        $this->assertSame(0, RecoveryObjectives::sanitize(null));
    }

    public function testDependentCannotRecoverFasterThanItsSource(): void
    {
        // Stockage → hyperviseur → application de paie.
        $edges = [['storage', 'hypervisor'], ['hypervisor', 'payroll']];

        $consistent = ['storage' => 4, 'hypervisor' => 4, 'payroll' => 8];
        $this->assertSame([], RecoveryObjectives::inconsistencies($edges, $consistent));

        $issues = RecoveryObjectives::inconsistencies($edges, ['storage' => 24, 'hypervisor' => 4, 'payroll' => 8]);
        $this->assertSame(
            [['source' => 'storage', 'impacted' => 'hypervisor', 'required' => 4, 'actual' => 24]],
            $issues
        );

        $undefined = RecoveryObjectives::inconsistencies($edges, ['payroll' => 4]);
        $this->assertSame(['hypervisor'], array_column($undefined, 'source'), 'Source sans objectif.');
        $this->assertSame(0, $undefined[0]['actual']);
    }
}
