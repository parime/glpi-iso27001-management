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

use GlpiPlugin\Grcmanager\Services\Risk\CausalGraph;
use PHPUnit\Framework\TestCase;

final class CausalGraphTest extends TestCase
{
    /** Hameçonnage → compte compromis → fuite de données ; compte compromis → rançongiciel. */
    private const EDGES = [
        ['I:phishing', 'R:account'],
        ['R:account', 'R:leak'],
        ['R:account', 'R:ransomware'],
        ['S:supplier', 'R:leak'],
    ];

    public function testReachableInBothDirectionsWithDepth(): void
    {
        $this->assertSame(
            ['R:account' => 1, 'R:leak' => 2, 'R:ransomware' => 2],
            CausalGraph::reachable(self::EDGES, 'I:phishing', 5, true)
        );
        $this->assertSame(['R:account' => 1], CausalGraph::reachable(self::EDGES, 'I:phishing', 1, true));
        $this->assertSame(
            ['R:account' => 1, 'S:supplier' => 1, 'I:phishing' => 2],
            CausalGraph::reachable(self::EDGES, 'R:leak', 5, false)
        );
    }

    public function testCycleDetection(): void
    {
        $this->assertTrue(CausalGraph::wouldCreateCycle(self::EDGES, 'R:leak', 'I:phishing'));
        $this->assertTrue(CausalGraph::wouldCreateCycle(self::EDGES, 'R:account', 'R:account'));
        $this->assertFalse(CausalGraph::wouldCreateCycle(self::EDGES, 'R:ransomware', 'R:leak'));

        // Un cycle déjà présent en base ne fait pas boucler le parcours.
        $cyclic = array_merge(self::EDGES, [['R:leak', 'I:phishing']]);
        $this->assertArrayHasKey('R:leak', CausalGraph::reachable($cyclic, 'I:phishing', 50, true));
    }

    public function testAroundKeepsOnlyEdgesBetweenKeptNodes(): void
    {
        $graph = CausalGraph::around(self::EDGES, 'R:account', 1);
        sort($graph['nodes']);
        $this->assertSame(['I:phishing', 'R:account', 'R:leak', 'R:ransomware'], $graph['nodes']);
        $this->assertNotContains(['S:supplier', 'R:leak'], $graph['edges'], 'Fournisseur hors du rayon.');
        $this->assertCount(3, $graph['edges']);
    }
}
