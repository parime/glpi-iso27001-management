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

use Computer;
use CronTask;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Feature\ServerHealthFeature;
use GlpiPlugin\Grcmanager\Install\ServerHealthTables as Tables;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishClient;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishHealthReader as Reader;
use GlpiPlugin\Grcmanager\Tests\Unit\Services\ServerManagement\RedfishClientTest;
use GlpiPlugin\Grcmanager\Tests\Unit\Services\ServerManagement\RedfishHealthReaderTest;

/**
 * Issue #843, lot 3 : santé détaillée et journal des contrôleurs, lus dans le faux iLO (aucun appel
 * réseau), sur un ordinateur fictif de l'entité de test ; tâche automatique désactivée par défaut.
 */
final class ServerHealthTest extends GrcmanagerIntegrationTestCase
{
    private const BASE = 'https://10.0.99.13';

    private int $entity;

    private int $computerId;

    private string $suffix;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Unit/Services/ServerManagement/RedfishClientTest.php';
        require_once dirname(__DIR__) . '/Unit/Services/ServerManagement/RedfishHealthReaderTest.php';
        ServerManagement::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo BMC santé ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$this->entity]);
        $_SESSION['glpiactive_entity'] = $this->entity;
        Http::setAllowPrivateNetworks(true);
        $this->computerId = (int) (new Computer())->add([
            'name' => 'bmc-health' . $this->suffix, 'entities_id' => $this->entity, 'serial' => 'CZJ0000TEST',
        ]);
        $this->assertGreaterThan(0, $this->computerId);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        ServerManagement::reset();
        parent::tearDown();
    }

    /** @param array<string, array<mixed>|int> $resources */
    private static function fake(array $resources): RedfishClient
    {
        return new RedfishClient(
            self::BASE,
            'audit-grc',
            'mot-de-passe-fictif',
            true,
            static function (string $url) use ($resources): array {
                $resource = $resources[substr($url, strlen(self::BASE))] ?? 404;

                return is_int($resource)
                    ? ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status']
                    : ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
            }
        );
    }

    private function declare(): int
    {
        $saved = ServerManagement::saveController([
            'name' => 'iLO health ' . $this->suffix, 'url' => self::BASE, 'username' => 'audit-grc',
            'password' => 'mot-de-passe-fictif',
        ]);
        $this->assertSame('', $saved['error']);

        return $saved['id'];
    }

    private function rowsOf(string $table, int $controllerId): int
    {
        return (int) countElementsInTable($table, ['plugin_grcmanager_bmccontrollers_id' => $controllerId]);
    }

    public function testHealthIsShownOnTheComputerAndIn360(): void
    {
        $id = $this->declare();
        $read = ServerManagement::read($id, self::fake(RedfishHealthReaderTest::fakeIloWithHealth()));
        $this->assertSame('', $read['error']);
        $this->assertSame(5, $this->rowsOf(Tables::COMPONENTS, $id));
        $this->assertSame(3, $this->rowsOf(Tables::EVENTS, $id));

        $computer = new Computer();
        $this->assertTrue($computer->getFromDB($this->computerId));
        $rows = ServerManagement::tabData($computer)['rows'];
        $this->assertCount(1, $rows);
        $health = $rows[0]['health_detail'];
        $this->assertTrue($health['has_data']);
        $this->assertSame(1, $health['critical']);
        $this->assertSame(2, $health['warning']);
        $this->assertSame(
            [Reader::KIND_POWER, Reader::KIND_DRIVE, Reader::KIND_FAN, Reader::KIND_TEMPERATURE],
            array_column($health['sections'], 'kind')
        );
        // Composant dégradé d'abord.
        $this->assertSame('PSU 2', $health['sections'][0]['items'][0]['name']);
        $this->assertSame('Power supply 2 failed.', $health['events'][0]['message']);

        // Vue 360° : un composant critique.
        $source = ServerHealthFeature::serverHealth($computer);
        $this->assertNotNull($source);
        $this->assertSame(Security360Rules::STATUS_CRITICAL, $source['status']);
        $this->assertNotEmpty($source['facts']);
        $this->assertArrayHasKey('server_health', ServerHealthFeature::assetSecuritySources());

        // Lecture suivante sans santé lisible : la précédente est gardée.
        $this->assertSame('', ServerManagement::read($id, self::fake(RedfishClientTest::fakeIlo()))['error']);
        $this->assertSame(5, $this->rowsOf(Tables::COMPONENTS, $id));

        // Suppression du contrôleur : sa santé part avec lui.
        $this->assertTrue(ServerManagement::deleteController($id));
        $this->assertSame(0, $this->rowsOf(Tables::COMPONENTS, $id));
        $this->assertSame(0, $this->rowsOf(Tables::EVENTS, $id));
        $this->assertNull(ServerHealthFeature::serverHealth($computer));
    }

    public function testControllerWithoutHealthHasNo360Source(): void
    {
        $id = $this->declare();
        $this->assertSame('', ServerManagement::read($id, self::fake(RedfishClientTest::fakeIlo()))['error']);
        $computer = new Computer();
        $this->assertTrue($computer->getFromDB($this->computerId));
        $this->assertFalse(ServerManagement::tabData($computer)['rows'][0]['health_detail']['has_data']);
        $this->assertNull(ServerHealthFeature::serverHealth($computer));
    }

    public function testScheduledReadingIsDisabledByDefault(): void
    {
        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(ServerManagement::class, ServerManagement::CRON_NAME));
        $this->assertSame(CronTask::STATE_DISABLE, (int) $task->fields['state']);
        $this->assertNotSame([], ServerManagement::cronInfo(ServerManagement::CRON_NAME));
        $this->assertSame([], ServerManagement::cronInfo('other'));
        $this->assertFalse(ServerManagement::pageData()['cron']['enabled']);
    }
}
