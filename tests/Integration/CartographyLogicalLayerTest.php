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

use GlpiPlugin\Grcmanager\Cartography;
use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;
use GlpiPlugin\Grcmanager\Services\Cartography\Source\LogicalNetworkSource;

/**
 * Issue #1082, lot 3 : couche du réseau logique (VLAN, réseaux IP, réseaux GLPI) et environnements des
 * applications, sur des objets GLPI fictifs créés par le test (transaction annulée à la fin).
 *
 * Le switch porte le VLAN, le VLAN porte le serveur ; le serveur a une adresse dans un réseau IP et
 * porte deux applications, l'une en production, l'autre en recette.
 */
final class CartographyLogicalLayerTest extends GrcmanagerIntegrationTestCase
{
    private string $suffix;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $DB;

        $this->suffix = (string) random_int(1000, 9999);
        $name = fn (string $label): string => 'PhpUnitDemo ' . $label . ' ' . $this->suffix;
        $this->ids['switch'] = (int) (new \NetworkEquipment())->add(['name' => $name('sw'), 'entities_id' => 0]);
        $this->ids['server'] = (int) (new \Computer())->add(['name' => $name('srv'), 'entities_id' => 0]);
        $this->ids['vlan'] = (int) (new \Vlan())->add([
            'name' => $name('vlan'), 'tag' => 3210, 'entities_id' => 0,
        ]);
        $this->ids['network'] = (int) (new \Network())->add(['name' => $name('net')]);
        $DB->update('glpi_computers', ['networks_id' => $this->ids['network']], ['id' => $this->ids['server']]);

        $port = function (string $itemtype, int $itemsId) use ($DB): int {
            $DB->insert('glpi_networkports', [
                'itemtype' => $itemtype, 'items_id' => $itemsId, 'entities_id' => 0,
                'name' => 'phpunitdemo-port', 'logical_number' => 1, 'instantiation_type' => 'NetworkPortEthernet',
            ]);

            return (int) $DB->insertId();
        };
        foreach (['NetworkEquipment' => $this->ids['switch'], 'Computer' => $this->ids['server']] as $type => $id) {
            $DB->insert('glpi_networkports_vlans', [
                'networkports_id' => $port($type, $id), 'vlans_id' => $this->ids['vlan'],
            ]);
        }

        $DB->insert('glpi_ipnetworks', [
            'name' => $name('subnet'), 'entities_id' => 0, 'is_recursive' => 1, 'version' => 4,
            'address' => '192.0.2.0', 'netmask' => '255.255.255.0', 'completename' => $name('subnet'),
        ]);
        $this->ids['ipnetwork'] = (int) $DB->insertId();
        $DB->insert('glpi_ipaddresses', [
            'entities_id' => 0, 'itemtype' => 'NetworkName', 'items_id' => 0, 'version' => 4, 'name' => '192.0.2.10',
            'mainitemtype' => 'Computer', 'mainitems_id' => $this->ids['server'],
        ]);
        $DB->insert('glpi_ipaddresses_ipnetworks', [
            'ipaddresses_id' => (int) $DB->insertId(), 'ipnetworks_id' => $this->ids['ipnetwork'],
        ]);

        $this->ids['production'] = (int) (new \ApplianceEnvironment())->add(['name' => $name('prod')]);
        $this->ids['test'] = (int) (new \ApplianceEnvironment())->add(['name' => $name('rec')]);
        foreach (['production', 'test'] as $environment) {
            $this->ids['app_' . $environment] = (int) (new \Appliance())->add([
                'name' => $name('app ' . $environment), 'entities_id' => 0,
                'applianceenvironments_id' => $this->ids[$environment],
            ]);
            $DB->insert('glpi_appliances_items', [
                'appliances_id' => $this->ids['app_' . $environment], 'itemtype' => 'Computer',
                'items_id' => $this->ids['server'],
            ]);
        }
        $DB->insert('glpi_appliances_items_relations', [
            'appliances_items_id' => (int) $DB->insertId(), 'itemtype' => 'Network',
            'items_id'            => $this->ids['network'],
        ]);
    }

    public function testLogicalObjectsBelongToTheLogicalLayer(): void
    {
        foreach (Layers::LOGICAL_ITEMTYPES as $itemtype) {
            $this->assertSame(Layers::LOGICAL_NETWORK, Layers::of($itemtype));
            $this->assertContains($itemtype, Cartography::startItemtypes());
        }
        $this->assertContains(LogicalNetworkSource::class, Cartography::sources());
    }

    public function testVlanIsCarriedByTheSwitchAndCarriesTheServer(): void
    {
        $vlan = 'Vlan:' . $this->ids['vlan'];
        $vlanId = $this->ids['vlan'];
        $switch = 'NetworkEquipment:' . $this->ids['switch'];
        $this->assertSame([$switch], LogicalNetworkSource::supporters('Vlan', $vlanId));
        $this->assertSame(['Computer:' . $this->ids['server']], LogicalNetworkSource::dependents('Vlan', $vlanId));
        $this->assertContains($vlan, LogicalNetworkSource::dependents('NetworkEquipment', $this->ids['switch']));
        $this->assertContains($vlan, LogicalNetworkSource::supporters('Computer', $this->ids['server']));
    }

    public function testServerDependsOnItsIpNetworkAndGlpiNetwork(): void
    {
        $supporters = LogicalNetworkSource::supporters('Computer', $this->ids['server']);

        $this->assertContains('IPNetwork:' . $this->ids['ipnetwork'], $supporters);
        $this->assertContains('Network:' . $this->ids['network'], $supporters);
        $server = 'Computer:' . $this->ids['server'];
        $app = 'Appliance:' . $this->ids['app_test'];
        $network = 'Network:' . $this->ids['network'];
        $this->assertContains($server, LogicalNetworkSource::dependents('IPNetwork', $this->ids['ipnetwork']));
        $this->assertContains($app, LogicalNetworkSource::dependents('Network', $this->ids['network']));
        $this->assertContains($network, LogicalNetworkSource::supporters('Appliance', $this->ids['app_test']));
    }

    public function testSwitchFailureReachesTheApplicationsThroughTheVlan(): void
    {
        $switch = new \NetworkEquipment();
        $this->assertTrue($switch->getFromDB($this->ids['switch']));

        $map = Cartography::map($switch, DependencyWalker::UP);
        $keys = $this->keys($map);

        $this->assertContains('Vlan:' . $this->ids['vlan'], $keys);
        $this->assertContains('Computer:' . $this->ids['server'], $keys);
        $this->assertContains('Appliance:' . $this->ids['app_production'], $keys);
        $this->assertContains('Appliance:' . $this->ids['app_test'], $keys);
        $logical = array_values(array_filter(
            $map['layers'],
            static fn (array $l): bool => $l['layer'] === Layers::LOGICAL_NETWORK
        ));
        $this->assertSame(['Vlan:' . $this->ids['vlan']], array_column($logical[0]['nodes'], 'key'));
    }

    public function testEnvironmentFilterKeepsOnlyTheChosenEnvironment(): void
    {
        $switch = new \NetworkEquipment();
        $this->assertTrue($switch->getFromDB($this->ids['switch']));

        $map = Cartography::map($switch, DependencyWalker::UP, ['environment' => $this->ids['production']]);
        $keys = $this->keys($map);

        $this->assertContains('Appliance:' . $this->ids['app_production'], $keys);
        $this->assertNotContains('Appliance:' . $this->ids['app_test'], $keys);
        $this->assertSame($this->ids['production'], $map['environment']);
        $this->assertStringContainsString('environment=' . $this->ids['production'], $map['url']);
        $this->assertCount(1, $map['applications']);
        $this->assertSame('PhpUnitDemo prod ' . $this->suffix, $map['applications'][0]['environment']);
    }

    public function testApplicationMapListsItsEnvironmentAndServers(): void
    {
        $app = new \Appliance();
        $this->assertTrue($app->getFromDB($this->ids['app_test']));
        $this->assertSame(DependencyWalker::DOWN, Cartography::defaultDirection('Appliance'));
        $this->assertContains('Appliance', Cartography::tabItemtypes());

        $map = Cartography::map($app, DependencyWalker::DOWN);

        $this->assertSame('PhpUnitDemo rec ' . $this->suffix, $map['start']['environment']);
        $this->assertContains('Computer:' . $this->ids['server'], $this->keys($map));
        $this->assertContains('Vlan:' . $this->ids['vlan'], $this->keys($map));
        $this->assertContains('NetworkEquipment:' . $this->ids['switch'], $this->keys($map));
        $this->assertSame(
            ['Computer:' . $this->ids['server']],
            array_column($map['applications'][0]['applications'][0]['servers'], 'key')
        );
        $rows = Cartography::exportRows($map);
        $this->assertCount(6, $rows[0]);
        $this->assertContains('PhpUnitDemo rec ' . $this->suffix, array_column($rows, 5));
    }

    /**
     * @param array<string, mixed> $map
     * @return list<string>
     */
    private function keys(array $map): array
    {
        $keys = [];
        foreach ($map['layers'] as $layer) {
            $keys = array_merge($keys, array_column($layer['nodes'], 'key'));
        }

        return $keys;
    }
}
