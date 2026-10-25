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

use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use PHPUnit\Framework\TestCase;

/**
 * Issue #177 : parcours du graphe d'impact (hyperviseur → machines virtuelles → applications).
 */
final class ImpactGraphTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private const EDGES = [
        ['Computer:1', 'Computer:10'],  // hyperviseur -> VM A
        ['Computer:1', 'Computer:11'],  // hyperviseur -> VM B
        ['Computer:10', 'Appliance:5'], // VM A -> application
        ['Computer:11', 'Appliance:5'], // VM B -> même application (redondance)
        ['Computer:11', 'Appliance:6'], // VM B -> application sans redondance
        ['Appliance:6', 'Computer:11'], // cycle
    ];

    public function testDownstreamWithDepthAndCycles(): void
    {
        $this->assertSame(
            ['Computer:10' => 1, 'Computer:11' => 1, 'Appliance:5' => 2, 'Appliance:6' => 2],
            ImpactGraph::downstream(self::EDGES, 'Computer:1')
        );
        $this->assertSame([], ImpactGraph::downstream(self::EDGES, 'Computer:99'));
    }

    public function testSinglePointsOfFailure(): void
    {
        $points = ImpactGraph::singlePointsOfFailure(self::EDGES, ['Appliance:5', 'Appliance:6', 'Computer:10']);

        $this->assertSame([
            ['source' => 'Computer:11', 'critical' => 'Appliance:6'],
            ['source' => 'Computer:1', 'critical' => 'Computer:10'],
        ], $points, 'Appliance:5 a deux sources : pas un point unique.');
    }

    public function testKeys(): void
    {
        $key = ImpactGraph::key('Glpi\\CustomAsset\\ServerAsset', 42);
        $this->assertSame(['Glpi\\CustomAsset\\ServerAsset', 42], ImpactGraph::split($key));
    }
}
