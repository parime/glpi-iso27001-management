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

use GlpiPlugin\Grcmanager\Services\Continuity\ProcessDependencyRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #377 : dépendances entre processus fictifs. La paie (1) a besoin de la gestion des temps
 * (2), elle-même alimentée par l'annuaire RH (3) ; la facturation (4) a aussi besoin de l'annuaire.
 */
final class ProcessDependencyRulesTest extends TestCase
{
    /** @var list<array{0: int, 1: int}> [amont, aval] */
    private const EDGES = [[2, 1], [3, 2], [3, 4]];

    public function testCycleIsDetectedAtAnyDepth(): void
    {
        // L'annuaire ne peut pas dépendre de la paie : la paie en dépend déjà, indirectement.
        $this->assertTrue(ProcessDependencyRules::wouldCreateCycle(self::EDGES, 3, 1));
        $this->assertTrue(ProcessDependencyRules::wouldCreateCycle(self::EDGES, 2, 1));
        // Un processus ne dépend pas de lui-même.
        $this->assertTrue(ProcessDependencyRules::wouldCreateCycle(self::EDGES, 4, 4));
        // La paie peut dépendre de la facturation, et la facturation de la gestion des temps.
        $this->assertFalse(ProcessDependencyRules::wouldCreateCycle(self::EDGES, 1, 4));
        $this->assertFalse(ProcessDependencyRules::wouldCreateCycle(self::EDGES, 4, 2));
        $this->assertFalse(ProcessDependencyRules::wouldCreateCycle([], 1, 2));
    }

    public function testDeepChainBeyondTheImpactGraphDepthIsStillACycle(): void
    {
        $edges = [];
        for ($i = 1; $i < 30; $i++) {
            $edges[] = [$i + 1, $i];
        }
        $this->assertTrue(ProcessDependencyRules::wouldCreateCycle($edges, 30, 1));
    }

    public function testRequiredObjectivesAreTheShortestOfTheDependents(): void
    {
        $this->assertSame(
            ['rto' => 4, 'rto_from' => 1, 'rpo' => 1, 'rpo_from' => 4],
            ProcessDependencyRules::requiredObjectives([
                1 => ['rto' => 4, 'rpo' => 24],
                4 => ['rto' => 24, 'rpo' => 1],
                5 => ['rto' => 0, 'rpo' => 0],
            ])
        );
        $this->assertSame(
            ['rto' => 0, 'rto_from' => 0, 'rpo' => 0, 'rpo_from' => 0],
            ProcessDependencyRules::requiredObjectives([5 => ['rto' => 0, 'rpo' => 0]])
        );
    }

    public function testUpstreamIssues(): void
    {
        // Paie : RTO 24 h, RPO 4 h.
        $this->assertSame(
            [
                2 => ['rto_slower', 'rpo_larger'],
                3 => ['process_rto_missing'],
                6 => [],
            ],
            ProcessDependencyRules::upstreamIssues(24, 4, [
                2 => ['rto' => 72, 'rpo' => 24],
                3 => ['rto' => 0, 'rpo' => 0],
                6 => ['rto' => 8, 'rpo' => 4],
            ])
        );
        // Sans objectif sur le processus, rien n'est exigé de ceux dont il dépend.
        $this->assertSame([2 => []], ProcessDependencyRules::upstreamIssues(0, 0, [2 => ['rto' => 72, 'rpo' => 24]]));
    }
}
