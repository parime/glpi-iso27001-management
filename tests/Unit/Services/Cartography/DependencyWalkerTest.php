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
 * Issue #1082 : calcul des dépendances de la cartographie (switch → serveur → VM → logiciel →
 * processus), dans les deux sens, avec cycles, borne et placement par couches.
 */
final class DependencyWalkerTest extends TestCase
{
    private const PROCESS = 'GlpiPlugin\\Grcmanager\\BusinessProcess';

    /** Support → dépendants. */
    private const EDGES = [
        'NetworkEquipment:1' => ['Computer:10'],
        'Computer:10'        => ['Computer:20', 'Software:5'],
        'Computer:20'        => ['Appliance:7'],
        'Software:5'         => [self::PROCESS . ':3'],
        'Appliance:7'        => [self::PROCESS . ':3', self::PROCESS . ':4'],
        self::PROCESS . ':4' => [self::PROCESS . ':6', 'Appliance:7'],
    ];

    /**
     * @return callable(string): list<string>
     */
    private static function up(): callable
    {
        return static fn (string $key): array => self::EDGES[$key] ?? [];
    }

    /**
     * @return callable(string): list<string>
     */
    private static function down(): callable
    {
        return static function (string $key): array {
            $out = [];
            foreach (self::EDGES as $supporter => $dependents) {
                if (in_array($key, $dependents, true)) {
                    $out[] = $supporter;
                }
            }

            return $out;
        };
    }

    public function testUpwardReachesProcessesWithDepth(): void
    {
        $walk = DependencyWalker::walk('NetworkEquipment:1', DependencyWalker::UP, self::up());

        $this->assertSame([
            'NetworkEquipment:1' => 0,
            'Computer:10'        => 1,
            'Computer:20'        => 2,
            'Software:5'         => 2,
            'Appliance:7'        => 3,
            self::PROCESS . ':3' => 3,
            self::PROCESS . ':4' => 4,
            self::PROCESS . ':6' => 5,
        ], $walk['nodes']);
        $this->assertFalse($walk['truncated']);
        // Le cycle processus 4 → application 7 est rendu comme un lien, sans boucler.
        $this->assertContains([self::PROCESS . ':4', 'Appliance:7'], $walk['edges']);
        $this->assertContains(['NetworkEquipment:1', 'Computer:10'], $walk['edges']);
    }

    public function testDownwardEdgesKeepSupporterFirst(): void
    {
        $walk = DependencyWalker::walk(self::PROCESS . ':3', DependencyWalker::DOWN, self::down());

        $this->assertArrayHasKey('NetworkEquipment:1', $walk['nodes']);
        $this->assertSame(3, $walk['nodes']['NetworkEquipment:1'] ?? null);
        $this->assertContains(['Software:5', self::PROCESS . ':3'], $walk['edges']);
        $this->assertContains(['NetworkEquipment:1', 'Computer:10'], $walk['edges']);
        $this->assertArrayNotHasKey(self::PROCESS . ':6', $walk['nodes']);
    }

    public function testLimitStopsAndFlags(): void
    {
        $walk = DependencyWalker::walk('NetworkEquipment:1', DependencyWalker::UP, self::up(), 3);

        $this->assertCount(3, $walk['nodes']);
        $this->assertTrue($walk['truncated']);
    }

    public function testSelfLinkAndIsolatedItem(): void
    {
        $walk = DependencyWalker::walk('Computer:99', DependencyWalker::UP, static fn (string $key): array => [$key]);

        $this->assertSame(['Computer:99' => 0], $walk['nodes']);
        $this->assertSame([], $walk['edges']);
    }

    public function testProcessesSortedByRecoveryTimeUndefinedLast(): void
    {
        $p = self::PROCESS;
        $nodes = [$p . ':3' => 3, $p . ':4' => 4, $p . ':6' => 5, $p . ':8' => 1];
        $rto = [$p . ':3' => 24, $p . ':4' => 4, $p . ':6' => 0, $p . ':8' => 24];

        $this->assertSame(
            [self::PROCESS . ':4', self::PROCESS . ':8', self::PROCESS . ':3', self::PROCESS . ':6'],
            DependencyWalker::byRecoveryTime($nodes, $rto)
        );
    }

    public function testByLayer(): void
    {
        $walk = DependencyWalker::walk('NetworkEquipment:1', DependencyWalker::UP, self::up());
        $layers = DependencyWalker::byLayer(
            $walk['nodes'],
            static fn (string $key): int => Layers::of(substr($key, 0, (int) strrpos($key, ':')))
        );

        $this->assertSame(['NetworkEquipment:1'], $layers[Layers::NETWORK]);
        $this->assertSame(['Computer:10', 'Computer:20'], $layers[Layers::INFRASTRUCTURE]);
        $this->assertSame(['Software:5', 'Appliance:7'], $layers[Layers::APPLICATION]);
        $this->assertSame(
            [self::PROCESS . ':3', self::PROCESS . ':4', self::PROCESS . ':6'],
            $layers[Layers::BUSINESS]
        );
    }

    public function testLayersAndNetworkDirection(): void
    {
        $this->assertSame(Layers::NETWORK, Layers::of('NetworkEquipment'));
        $this->assertSame(Layers::INFRASTRUCTURE, Layers::of('Computer'));
        $this->assertSame(Layers::INFRASTRUCTURE, Layers::of('Glpi\\CustomAsset\\RobotAsset'));
        $this->assertSame(Layers::APPLICATION, Layers::of(Layers::DOCKER_SERVICE));
        $this->assertSame(Layers::BUSINESS, Layers::of(self::PROCESS));
        // Le switch porte l'ordinateur ; deux switchs reliés ne propagent rien.
        $this->assertSame(-1, Layers::direction('NetworkEquipment', 'Computer'));
        $this->assertSame(1, Layers::direction('Computer', 'NetworkEquipment'));
        $this->assertSame(0, Layers::direction('NetworkEquipment', 'NetworkEquipment'));
    }

    public function testLayoutPutsBusinessOnTopAndCapsEachLayer(): void
    {
        $many = array_map(static fn (int $i): string => 'Computer:' . $i, range(1, 15));
        $layout = LayeredLayout::place([
            Layers::NETWORK        => ['NetworkEquipment:1'],
            Layers::INFRASTRUCTURE => $many,
            Layers::APPLICATION    => [],
            Layers::BUSINESS       => [self::PROCESS . ':3'],
        ]);

        $this->assertSame(Layers::BUSINESS, $layout['bands'][0]['layer']);
        $this->assertSame(Layers::NETWORK, $layout['bands'][3]['layer']);
        $this->assertSame(15 - LayeredLayout::MAX_DRAWN, $layout['bands'][2]['hidden']);
        $this->assertArrayNotHasKey('Computer:15', $layout['nodes']);
        $this->assertLessThan(
            $layout['nodes']['NetworkEquipment:1']['y'],
            $layout['nodes'][self::PROCESS . ':3']['y']
        );
        foreach ($layout['nodes'] as $position) {
            $this->assertLessThanOrEqual(LayeredLayout::WIDTH, $position['x'] + LayeredLayout::NODE_WIDTH);
            $this->assertLessThanOrEqual($layout['height'], $position['y'] + LayeredLayout::NODE_HEIGHT);
        }
        $this->assertSame('Serveur', LayeredLayout::shortLabel('Serveur'));
        $this->assertSame(10, mb_strlen(LayeredLayout::shortLabel('Serveur de fichiers principal', 10)));
    }
}
