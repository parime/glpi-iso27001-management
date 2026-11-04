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

use GlpiPlugin\Grcmanager\Services\Continuity\BusinessImpactRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #377 : BIA d'un processus fictif « Paie » (impacts dans le temps, DMIA, RTO, dépendances).
 */
final class BusinessImpactRulesTest extends TestCase
{
    /**
     * @return array<int, array<string, int>>
     */
    private static function payrollGrid(): array
    {
        return BusinessImpactRules::grid([
            ['horizon_hours' => 4, 'financial' => 1, 'legal' => 1, 'reputation' => 1, 'operational' => 2],
            ['horizon_hours' => 24, 'financial' => 2, 'legal' => 2, 'reputation' => 2, 'operational' => 2],
            ['horizon_hours' => 72, 'financial' => 3, 'legal' => 4, 'reputation' => 3, 'operational' => 3],
            // Ligne hors grille et niveau hors échelle : ignorés.
            ['horizon_hours' => 5, 'financial' => 4],
            ['horizon_hours' => 168, 'financial' => 9, 'legal' => 4, 'reputation' => 4, 'operational' => 4],
        ]);
    }

    public function testGridIsCompleteAndSanitized(): void
    {
        $grid = self::payrollGrid();

        $this->assertSame(BusinessImpactRules::HORIZONS, array_keys($grid));
        $this->assertSame(0, $grid[168]['financial'], 'Niveau hors échelle ramené à « non évalué ».');
        $this->assertSame(['financial' => 0, 'legal' => 0, 'reputation' => 0, 'operational' => 0], $grid[720]);
        $this->assertTrue(BusinessImpactRules::isAssessed($grid));
        $this->assertFalse(BusinessImpactRules::isAssessed(BusinessImpactRules::grid([])));
    }

    public function testFirstUnacceptableHorizonAndWorst(): void
    {
        $grid = self::payrollGrid();

        $this->assertSame(72, BusinessImpactRules::firstUnacceptableHorizon($grid));
        $this->assertSame(4, BusinessImpactRules::firstUnacceptableHorizon($grid, 2));
        $this->assertSame(0, BusinessImpactRules::firstUnacceptableHorizon(BusinessImpactRules::grid([])));
        $this->assertSame([4 => 2, 24 => 2, 72 => 4, 168 => 4, 720 => 0], BusinessImpactRules::worstByHorizon($grid));
    }

    public function testObjectiveIssues(): void
    {
        $grid = self::payrollGrid();

        $this->assertSame([], BusinessImpactRules::objectiveIssues(72, 24, 4, $grid));
        $this->assertSame(
            ['rto_above_mtpd', 'mtpd_above_impact'],
            BusinessImpactRules::objectiveIssues(168, 336, 0, $grid)
        );
        $this->assertSame(
            ['bia_missing', 'mtpd_missing', 'rto_missing'],
            BusinessImpactRules::objectiveIssues(0, 0, 0, BusinessImpactRules::grid([]))
        );
        $this->assertSame(['rpo_above_mtpd'], BusinessImpactRules::objectiveIssues(24, 8, 48, $grid));
    }

    public function testDecreasingImpactIsReported(): void
    {
        $grid = BusinessImpactRules::grid([
            ['horizon_hours' => 4, 'financial' => 3],
            ['horizon_hours' => 24, 'financial' => 2],
        ]);

        $this->assertTrue(BusinessImpactRules::isDecreasing($grid));
        $this->assertFalse(BusinessImpactRules::isDecreasing(self::payrollGrid()));
        $this->assertContains('impact_decreasing', BusinessImpactRules::objectiveIssues(4, 4, 0, $grid));
    }

    public function testDependencyIssues(): void
    {
        $issues = BusinessImpactRules::dependencyIssues(24, 4, [
            'Computer:1'  => ['rto' => 8, 'rpo' => 1],
            'Computer:2'  => ['rto' => 72, 'rpo' => 24],
            'Appliance:3' => ['rto' => 0, 'rpo' => 0],
        ]);

        $this->assertSame([
            ['key' => 'Computer:2', 'issue' => 'rto_slower', 'required' => 24, 'actual' => 72],
            ['key' => 'Computer:2', 'issue' => 'rpo_larger', 'required' => 4, 'actual' => 24],
            ['key' => 'Appliance:3', 'issue' => 'rto_missing', 'required' => 24, 'actual' => 0],
        ], $issues);
        $this->assertSame(
            [],
            BusinessImpactRules::dependencyIssues(0, 0, ['Computer:2' => ['rto' => 72, 'rpo' => 24]])
        );
    }

    public function testSanitize(): void
    {
        $this->assertSame(336, BusinessImpactRules::sanitizeDuration('336'));
        $this->assertSame(0, BusinessImpactRules::sanitizeDuration(5));
        $this->assertSame(0, BusinessImpactRules::sanitizeDuration('abc'));
        $this->assertSame(4, BusinessImpactRules::sanitizeLevel('4'));
        $this->assertSame(0, BusinessImpactRules::sanitizeLevel(-1));
    }
}
