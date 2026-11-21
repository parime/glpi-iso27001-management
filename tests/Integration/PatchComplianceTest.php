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

use GlpiPlugin\Grcmanager\PatchCompliance;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules as Rules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #854 : retard des correctifs de sécurité, sur des ordinateurs, systèmes et mises à jour
 * fictifs d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class PatchComplianceTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    /** @var array<string, int> */
    private array $pc = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        PatchCompliance::reset();
        PatchCompliance::setTargets([]);
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Patch ' . $suffix);
        $hidden = $this->createTestEntity(0, 'PhpUnitDemo Patch hidden ' . $suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        $serverType = (int) (new \ComputerType())->import(['name' => 'Server']);
        $computer = fn (string $name, int $type = 0, int $entity = 0): int => (int) (new \Computer())->add([
            'name'             => 'PhpUnitDemo ' . $name,
            'entities_id'      => $entity > 0 ? $entity : $this->entity,
            'computertypes_id' => $type,
        ]);
        $this->pc = [
            'server_late'  => $computer('srv late', $serverType),
            'desktop_ok'   => $computer('desktop ok'),
            'linux_ok'     => $computer('linux ok'),
            'no_agent'     => $computer('no agent'),
            'hidden'       => $computer('hidden', 0, $hidden),
        ];
        $today = date('Y-m-d');
        $this->os('server_late', 'Microsoft Windows Server 2022 Standard');
        $this->os('desktop_ok', 'Microsoft Windows 11 Pro');
        $this->os('linux_ok', 'Debian GNU/Linux 12 (bookworm)');
        $this->install('server_late', 'KB5031356', date('Y-m-d', strtotime('-40 days')));
        $this->install('desktop_ok', 'Security Update for Windows (KB5034441)', date('Y-m-d', strtotime('-5 days')));
        $this->install('desktop_ok', 'PhpUnitDemo Editor ' . $suffix, $today);
        $this->install('linux_ok', 'PhpUnitDemo openssl ' . $suffix, date('Y-m-d', strtotime('-3 days')));
        $this->install('hidden', 'KB5031357', date('Y-m-d', strtotime('-90 days')));
        global $DB;
        $DB->insert('glpi_plugin_grcmanager_assetclassifications', [
            'itemtype' => \Computer::class, 'items_id' => $this->pc['server_late'], 'availability' => 'high',
        ]);
        $DB->insert('glpi_plugin_grcmanager_networkflows', [
            'name' => 'PhpUnitDemo web ' . $suffix, 'flow_type' => 'internet_publication', 'is_active' => 1,
            'destination_itemtype' => \Computer::class, 'destination_items_id' => $this->pc['desktop_ok'],
            'ports' => '443',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        PatchCompliance::setTargets([]);
        PatchCompliance::reset();
        parent::tearDown();
    }

    private function os(string $pc, string $name): void
    {
        global $DB;

        $osId = (int) (new \OperatingSystem())->import(['name' => $name]);
        $DB->insert('glpi_items_operatingsystems', [
            'itemtype' => \Computer::class, 'items_id' => $this->pc[$pc], 'operatingsystems_id' => $osId,
            'entities_id' => $this->entity,
        ]);
    }

    private function install(string $pc, string $software, string $date): void
    {
        $softwareId = (int) (new \Software())->add(['name' => $software, 'entities_id' => $this->entity]);
        $versionId = (int) (new \SoftwareVersion())->add([
            'name' => '1.0', 'softwares_id' => $softwareId, 'entities_id' => $this->entity,
        ]);
        $this->assertGreaterThan(0, (int) (new \Item_SoftwareVersion())->add([
            'itemtype' => \Computer::class, 'items_id' => $this->pc[$pc], 'softwareversions_id' => $versionId,
            'date_install' => $date,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $rows, string $pc): array
    {
        $found = array_values(array_filter($rows, fn (array $r): bool => $r['id'] === $this->pc[$pc]));
        $this->assertCount(1, $found, $pc);

        return $found[0];
    }

    public function testRowsFromTheInventory(): void
    {
        $data = PatchCompliance::pageData();
        $rows = $data['rows'];
        $this->assertNotContains($this->pc['hidden'], array_column($rows, 'id'), 'Entity outside the session.');

        $server = $this->row($rows, 'server_late');
        $this->assertSame(Rules::STATUS_LATE, $server['status']);
        $this->assertSame(Rules::TIER_CRITICAL, $server['tier'], 'High availability.');
        $this->assertTrue($server['is_server']);
        $this->assertSame('KB5031356', $server['last_update']);
        $this->assertSame(25, $server['overdue']);

        $desktop = $this->row($rows, 'desktop_ok');
        $this->assertSame(Rules::STATUS_UP_TO_DATE, $desktop['status']);
        $this->assertTrue($desktop['exposed']);
        $this->assertSame(Rules::TIER_CRITICAL, $desktop['tier'], 'Published on the Internet.');
        $this->assertSame(5, $desktop['age'], 'The editor install is not a patch.');

        $linux = $this->row($rows, 'linux_ok');
        $this->assertSame(Rules::SOURCE_PACKAGE, $linux['source']);
        $this->assertSame(Rules::STATUS_UP_TO_DATE, $linux['status']);

        $this->assertSame(Rules::STATUS_UNKNOWN, $this->row($rows, 'no_agent')['status']);
        $this->assertSame($this->pc['server_late'], $rows[0]['id'], 'Late first.');
        $this->assertSame(1, $data['summary']['late_critical_servers']);
        $this->assertSame(66, $data['summary']['rate']);
    }

    public function testConfigurableTargets(): void
    {
        $targets = PatchCompliance::setTargets(['critical' => '60', 'important' => '-3']);
        $this->assertSame(60, $targets[Rules::TIER_CRITICAL]);
        $this->assertSame(Rules::DEFAULT_TARGETS[Rules::TIER_IMPORTANT], $targets[Rules::TIER_IMPORTANT]);
        $this->assertSame($targets, PatchCompliance::getTargets());
        $this->assertSame(
            Rules::STATUS_UP_TO_DATE,
            $this->row(PatchCompliance::pageData()['rows'], 'server_late')['status']
        );
    }

    public function testEvidenceKriDashboardAndRights(): void
    {
        $evidence = PatchCompliance::evidence('A.8.8');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['late']);
        $this->assertSame(1, $evidence['unknown']);
        $this->assertNotNull(PatchCompliance::evidence('A.8.19'));
        $this->assertNull(PatchCompliance::evidence('A.5.1'));
        $this->assertArrayHasKey('late_patch_critical_servers', RiskIndicator::getSources());
        $this->assertSame(1.0, PatchCompliance::lateCriticalServers([$this->entity]));
        $this->assertNull(PatchCompliance::lateCriticalServers([]));
        $this->assertTrue(SecuredDashboardCards::canShow('patchComplianceRate'));
        $this->assertSame($evidence['rate'], SecuredDashboardCards::patchComplianceRate()['number']);

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        PatchCompliance::reset();
        try {
            $this->assertFalse(PatchCompliance::canView());
            $this->assertNull(PatchCompliance::evidence('A.8.8'));
            $this->assertFalse(SecuredDashboardCards::canShow('patchComplianceRate'));
            $this->assertSame([], PatchCompliance::pageData()['rows']);
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }
    }
}
