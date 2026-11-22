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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\ServerManagement;

use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishClient;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishHealthReader as Reader;
use PHPUnit\Framework\TestCase;

/**
 * Issue #843, lot 3 : santé détaillée et journal lus dans le faux iLO (en mémoire, aucun appel réseau).
 */
final class RedfishHealthReaderTest extends TestCase
{
    private const BASE = 'https://ilo-srv01.example.test';

    /** @var list<string> */
    private array $urls = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/RedfishClientTest.php';
    }

    /**
     * Faux iLO du lot 1, avec un châssis (deux ventilateurs dont un emplacement vide, une température
     * en alerte, deux alimentations dont une en panne), un stockage (un disque sain, un disque qui
     * annonce une panne) et un journal IML de trois événements.
     *
     * @return array<string, array<mixed>>
     */
    public static function fakeIloWithHealth(): array
    {
        $ok = ['Health' => 'OK', 'State' => 'Enabled'];
        $resources = RedfishClientTest::fakeIlo();
        $resources['/redfish/v1/']['Chassis'] = ['@odata.id' => '/redfish/v1/Chassis'];
        $resources['/redfish/v1/Systems/1']['Links'] = ['Chassis' => [['@odata.id' => '/redfish/v1/Chassis/1']]];
        $resources['/redfish/v1/Systems/1']['Storage'] = ['@odata.id' => '/redfish/v1/Systems/1/Storage'];
        $resources['/redfish/v1/Managers/1']['LogServices'] = [
            '@odata.id' => '/redfish/v1/Managers/1/LogServices',
        ];
        $iml = '/redfish/v1/Managers/1/LogServices/IML';

        return $resources + [
            '/redfish/v1/Chassis/1' => [
                'Thermal' => ['@odata.id' => '/redfish/v1/Chassis/1/Thermal'],
                'Power'   => ['@odata.id' => '/redfish/v1/Chassis/1/Power'],
            ],
            '/redfish/v1/Chassis/1/Thermal' => [
                'Fans' => [
                    ['Name' => 'Fan 1', 'Reading' => 34, 'ReadingUnits' => 'Percent', 'Status' => $ok],
                    ['Name' => 'Fan 2', 'Status' => ['State' => 'Absent']],
                ],
                'Temperatures' => [[
                    'Name' => 'CPU 1', 'ReadingCelsius' => 71, 'UpperThresholdCritical' => 70,
                    'Status' => ['Health' => 'Warning', 'State' => 'Enabled'],
                ]],
            ],
            '/redfish/v1/Chassis/1/Power' => [
                'PowerSupplies' => [
                    ['Name' => 'PSU 1', 'Model' => 'HPE 800W', 'PowerCapacityWatts' => 800, 'Status' => $ok],
                    [
                        'Name' => 'PSU 2', 'Model' => 'HPE 800W', 'PowerCapacityWatts' => 800,
                        'Status' => ['Health' => 'Critical', 'State' => 'Enabled'],
                    ],
                ],
            ],
            '/redfish/v1/Systems/1/Storage' => ['Members' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/1']]],
            '/redfish/v1/Systems/1/Storage/1' => ['Drives' => [
                ['@odata.id' => '/redfish/v1/Systems/1/Storage/1/Drives/1'],
                ['@odata.id' => '/redfish/v1/Systems/1/Storage/1/Drives/2'],
                // Lien vers un autre hôte : jamais suivi.
                ['@odata.id' => 'https://piege.example.test/redfish/v1/Systems/1/Storage/1/Drives/3'],
            ]],
            '/redfish/v1/Systems/1/Storage/1/Drives/1' => [
                'Name' => 'Bay 1', 'Model' => 'MZ7LH960', 'CapacityBytes' => 960000000000, 'MediaType' => 'SSD',
                'Status' => $ok,
            ],
            '/redfish/v1/Systems/1/Storage/1/Drives/2' => [
                'Name' => 'Bay 2', 'CapacityBytes' => 2400000000000, 'MediaType' => 'HDD',
                'FailurePredicted' => true, 'Status' => $ok,
            ],
            '/redfish/v1/Managers/1/LogServices' => ['Members' => [['@odata.id' => $iml]]],
            $iml => ['Entries' => ['@odata.id' => $iml . '/Entries']],
            $iml . '/Entries' => ['Members' => [
                [
                    'Created' => '2026-01-10T08:00:00Z', 'Severity' => 'OK',
                    'Message' => 'Server power restored.', 'MessageId' => 'Event.1',
                ],
                [
                    'Created' => '2026-01-12T09:30:00Z', 'Severity' => 'Critical',
                    'Message' => 'Power supply 2 failed.', 'MessageId' => 'Event.2',
                ],
                ['Created' => '2026-01-11T10:00:00Z', 'Severity' => 'Warning', 'Message' => 'CPU 1 temperature high.'],
            ]],
        ];
    }

    /** @param array<string, array<mixed>|int> $resources */
    private function reader(array $resources): Reader
    {
        $client = new RedfishClient(
            self::BASE,
            'audit-grc',
            'mot-de-passe-fictif',
            true,
            function (string $url) use ($resources): array {
                $this->urls[] = $url;
                $resource = $resources[substr($url, strlen(self::BASE))] ?? 404;

                return is_int($resource)
                    ? ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status']
                    : ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
            }
        );

        return new Reader($client);
    }

    public function testReadsComponentsAndRecentEvents(): void
    {
        $health = $this->reader(self::fakeIloWithHealth())->read();

        $this->assertTrue($health['read']);
        $byName = [];
        foreach ($health['components'] as $component) {
            $byName[$component['name']] = $component;
        }
        // Emplacement vide ignoré.
        $this->assertArrayNotHasKey('Fan 2', $byName);
        $this->assertSame(Reader::KIND_FAN, $byName['Fan 1']['kind']);
        $this->assertSame('34 %', $byName['Fan 1']['reading']);
        $this->assertSame(Rules::HEALTH_WARNING, $byName['CPU 1']['health']);
        $this->assertSame('71 °C / 70 °C', $byName['CPU 1']['reading']);
        $this->assertSame(Rules::HEALTH_CRITICAL, $byName['PSU 2']['health']);
        $this->assertSame('HPE 800W · 800 W', $byName['PSU 2']['reading']);
        $this->assertSame('MZ7LH960 · 960 GB · SSD', $byName['Bay 1']['reading']);
        // Panne annoncée : au moins un avertissement.
        $this->assertSame(Rules::HEALTH_WARNING, $byName['Bay 2']['health']);
        $this->assertCount(5, $health['components']);

        $this->assertCount(3, $health['events']);
        $this->assertSame('2026-01-12 09:30:00', $health['events'][0]['date']);
        $this->assertSame(Rules::HEALTH_CRITICAL, $health['events'][0]['severity']);
        $this->assertSame('Event.2', $health['events'][0]['code']);

        // Seulement l'adresse du contrôleur.
        foreach ($this->urls as $url) {
            $this->assertStringStartsWith(self::BASE . '/redfish/v1', $url);
        }
    }

    public function testMissingSectionsAreOnlyAbsent(): void
    {
        $health = $this->reader(RedfishClientTest::fakeIlo())->read();

        // Faux iLO du lot 1 : ni châssis, ni stockage, ni journal.
        $this->assertFalse($health['read']);
        $this->assertSame([], $health['components']);
        $this->assertSame([], $health['events']);
    }

    public function testUnreachableControllerReadsNothing(): void
    {
        $health = $this->reader([])->read();

        $this->assertFalse($health['read']);
        $this->assertCount(1, $this->urls);
    }

    public function testDateTimeOf(): void
    {
        $this->assertSame('2026-01-12 09:30:00', Reader::dateTimeOf('2026-01-12T10:30:00+01:00'));
        $this->assertNull(Reader::dateTimeOf(''));
        $this->assertNull(Reader::dateTimeOf(null));
    }
}
