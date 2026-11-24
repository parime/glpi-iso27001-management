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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Cartography;

use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\LayeredLayout;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1082, lot 3 : couche du réseau logique (VLAN, réseaux IP, réseaux GLPI) entre le réseau
 * physique et les serveurs, sans changer les numéros des couches des lots 1 et 2.
 */
final class LayersTest extends TestCase
{
    public function testLogicalLayerSitsBetweenPhysicalNetworkAndServers(): void
    {
        $this->assertSame(
            [Layers::NETWORK, Layers::LOGICAL_NETWORK, Layers::INFRASTRUCTURE, Layers::APPLICATION, Layers::BUSINESS],
            Layers::ALL
        );
        $this->assertSame(
            [1, 2, 3, 4],
            [Layers::NETWORK, Layers::INFRASTRUCTURE, Layers::APPLICATION, Layers::BUSINESS]
        );
        $this->assertSame(0, Layers::rankOf('NetworkEquipment'));
        $this->assertSame(1, Layers::rankOf('Vlan'));
        $this->assertSame(1, Layers::rankOf('IPNetwork'));
        $this->assertSame(1, Layers::rankOf('Network'));
        $this->assertSame(2, Layers::rankOf('Computer'));
        $this->assertSame(4, Layers::rankOf('GlpiPlugin\\Grcmanager\\BusinessProcess'));
    }

    public function testDirectionFollowsTheRankNotTheNumber(): void
    {
        // Le switch porte le VLAN, le VLAN porte le serveur ; deux VLAN n'ont pas de sens.
        $this->assertSame(-1, Layers::direction('NetworkEquipment', 'Vlan'));
        $this->assertSame(-1, Layers::direction('Vlan', 'Computer'));
        $this->assertSame(1, Layers::direction('Computer', 'IPNetwork'));
        $this->assertSame(0, Layers::direction('Vlan', 'Network'));
        $this->assertSame(-1, Layers::direction('NetworkEquipment', 'Computer'));
    }

    public function testLayoutDrawsTheLogicalBandBetweenServersAndNetwork(): void
    {
        $layers = array_fill_keys(Layers::ALL, []);
        $layers[Layers::NETWORK] = ['NetworkEquipment:1'];
        $layers[Layers::LOGICAL_NETWORK] = ['Vlan:2'];
        $layers[Layers::INFRASTRUCTURE] = ['Computer:3'];

        $layout = LayeredLayout::place($layers);

        $this->assertSame(
            [Layers::BUSINESS, Layers::APPLICATION, Layers::INFRASTRUCTURE, Layers::LOGICAL_NETWORK, Layers::NETWORK],
            array_column($layout['bands'], 'layer')
        );
        $this->assertLessThan($layout['nodes']['Vlan:2']['y'], $layout['nodes']['Computer:3']['y']);
        $this->assertLessThan($layout['nodes']['NetworkEquipment:1']['y'], $layout['nodes']['Vlan:2']['y']);
        $byLayer = DependencyWalker::byLayer([], static fn (string $k): int => Layers::NETWORK);
        $this->assertCount(count(Layers::ALL), $byLayer);
    }
}
