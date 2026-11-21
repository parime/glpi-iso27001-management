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
use GlpiPlugin\Grcmanager\AssetClassification;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\Install\ZabbixTables;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixRules as Rules;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;

/**
 * Issue #836 : supervision lue dans un faux serveur Zabbix (aucun appel réseau), sur des actifs
 * fictifs d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class ZabbixMonitoringTest extends GrcmanagerIntegrationTestCase
{
    private const URL = 'https://zabbix.example.test/zabbix/';
    private const TOKEN = 'jeton-fictif-zabbix';

    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $computers = [];

    private int $process;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        ZabbixMonitoring::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Zabbix ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo Zabbix cachée ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$this->entity]);
        $_SESSION['glpiactive_entity'] = $this->entity;

        global $DB;
        $add = function (string $key, string $name, int $entity, string $serial = '', string $availability = '') use ($DB): void {
            $id = (int) (new Computer())->add(['name' => $name, 'entities_id' => $entity, 'serial' => $serial]);
            $this->assertGreaterThan(0, $id);
            if ($availability !== '') {
                $DB->insert(AssetClassification::getTable(), [
                    'itemtype' => Computer::class, 'items_id' => $id, 'availability' => $availability,
                ]);
            }
            $this->computers[$key] = $id;
        };
        // Critique et supervisé (série), critique non supervisé, poste ordinaire, actif caché.
        $add('web', 'zbx-web' . $this->suffix, $this->entity, 'ZBXSN' . $this->suffix, 'high');
        $add('db', 'zbx-db' . $this->suffix, $this->entity, '', 'high');
        $add('pc', 'zbx-pc' . $this->suffix, $this->entity);
        $add('hidden', 'zbx-hidden' . $this->suffix, $this->hiddenEntity, '', 'high');

        $this->process = (int) (new BusinessProcess())->add([
            'name' => 'PhpUnitDemo portail ' . $this->suffix, 'entities_id' => $this->entity, 'rto_hours' => 1,
        ]);
        $this->assertGreaterThan(0, $this->process);
        $DB->insert(BusinessProcessItem::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $this->process,
            'itemtype' => Computer::class, 'items_id' => $this->computers['web'],
        ]);

        $saved = ZabbixConfig::save(self::URL, self::TOKEN, false, $this->entity, false);
        $this->assertTrue($saved['saved']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        ZabbixMonitoring::reset();
        parent::tearDown();
    }

    /**
     * Faux serveur Zabbix 7.0 : un hôte rapproché par le numéro de série, un hôte de l'entité cachée,
     * un hôte inconnu ; un problème haut ouvert depuis deux heures, une saturation résolue.
     */
    private function client(int $status = 200): ZabbixClient
    {
        $now = time();
        $answers = [
            'apiinfo.version' => '7.0.0',
            'host.get'        => [
                [
                    'hostid' => '20001', 'host' => 'web-renamed', 'name' => 'Portal web', 'status' => '0',
                    'interfaces' => [['ip' => '192.0.2.20', 'dns' => '', 'available' => '1']],
                    'inventory' => ['serialno_a' => 'ZBXSN' . $this->suffix],
                ],
                [
                    'hostid' => '20002', 'host' => 'zbx-hidden' . $this->suffix, 'name' => 'Hidden', 'status' => '0',
                    'interfaces' => [], 'inventory' => [],
                ],
                [
                    'hostid' => '20003', 'host' => 'zbx-unknown' . $this->suffix, 'name' => 'Unknown', 'status' => '1',
                    'interfaces' => [], 'inventory' => [],
                ],
            ],
            'event.get'       => [
                [
                    'eventid' => '70001', 'name' => 'Portal is down', 'severity' => '5',
                    'clock' => (string) ($now - 7200), 'r_eventid' => '0', 'acknowledged' => '0',
                    'hosts' => [['hostid' => '20001']], 'tags' => [],
                ],
                [
                    'eventid' => '70002', 'name' => '/var: Disk space is low', 'severity' => '2',
                    'clock' => (string) ($now - 86400), 'r_eventid' => '70003', 'acknowledged' => '1',
                    'hosts' => [['hostid' => '20001']], 'tags' => [['tag' => 'component', 'value' => 'storage']],
                ],
                [
                    'eventid' => '70004', 'name' => 'Hidden problem', 'severity' => '4',
                    'clock' => (string) ($now - 600), 'r_eventid' => '0', 'acknowledged' => '0',
                    'hosts' => [['hostid' => '20002']], 'tags' => [],
                ],
            ],
            'event.get#ids'   => [['eventid' => '70003', 'clock' => (string) ($now - 80000)]],
            'problem.get'     => [['eventid' => '70001'], ['eventid' => '70004']],
            'sla.get'         => [['slaid' => '3', 'name' => 'Portal SLA', 'period' => '2', 'slo' => '99.9', 'status' => '1']],
            'sla.getsli'      => [
                'periods'    => [['period_from' => $now - 86400 * 20, 'period_to' => $now]],
                'serviceids' => [11],
                'sli'        => [[['uptime' => 1, 'downtime' => 7200, 'sli' => 99.5, 'error_budget' => 0]]],
            ],
            'service.get'     => [['serviceid' => '11', 'name' => 'Customer portal']],
        ];

        return new ZabbixClient(
            (string) Rules::normalizeApiUrl(self::URL),
            self::TOKEN,
            static function (string $method, string $url, array $headers, ?string $body) use ($answers, $status): array {
                if ($status !== 200) {
                    return ['status' => $status, 'body' => '', 'retry_after' => 0];
                }
                $request = (array) json_decode((string) $body, true);
                $rpc = (string) ($request['method'] ?? '');
                $answer = $answers[$rpc] ?? [];
                if ($rpc === 'event.get' && isset($request['params']['eventids'])) {
                    $answer = $answers['event.get#ids'];
                }

                return [
                    'status'      => 200,
                    'body'        => (string) json_encode(['jsonrpc' => '2.0', 'result' => $answer, 'id' => $request['id'] ?? 1]),
                    'retry_after' => 0,
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $key): array
    {
        foreach (ZabbixMonitoring::pageData()['rows'] as $row) {
            if ($row['itemtype'] === Computer::class && $row['id'] === $this->computers[$key]) {
                return $row;
            }
        }
        $this->fail('Actif absent : ' . $key);
    }

    public function testReadMatchesHostsAndShowsCoverage(): void
    {
        $result = ZabbixMonitoring::read($this->client());
        $this->assertSame('', $result['error']);
        $this->assertSame(3, $result['hosts']);
        $this->assertSame(3, $result['problems']);
        $this->assertSame(1, $result['services']);
        $this->assertSame(3, countElementsInTable(ZabbixTables::HOSTS, ['entities_id' => $this->entity]));

        $web = $this->asset('web');
        $this->assertSame(Rules::STATUS_MONITORED, $web['status']);
        $this->assertTrue($web['critical']);
        $this->assertSame(1, $web['open_high']);
        $this->assertSame([Rules::CAPACITY_STORAGE => 1], $web['capacity']);
        $this->assertLessThan(100.0, $web['availability']);
        // Arrêt de deux heures, RTO d'une heure : écart.
        $this->assertTrue($web['rto_gap']);
        $this->assertSame($this->process, $web['processes'][0]['id']);
        $this->assertSame(Rules::STATUS_MISSING, $this->asset('db')['status']);

        $keys = array_column(ZabbixMonitoring::pageData()['rows'], 'key');
        $this->assertNotContains(Computer::class . ':' . $this->computers['pc'], $keys, 'Poste ordinaire non concerné.');
        $this->assertNotContains(Computer::class . ':' . $this->computers['hidden'], $keys, 'Actif de l\'entité cachée.');

        $summary = ZabbixMonitoring::summary();
        $this->assertSame(2, $summary['concerned']['total']);
        $this->assertSame(50, $summary['concerned']['rate']);
        $this->assertSame(1, $summary['critical']['uncovered']);
        $this->assertSame(1, $summary['services_below']);
        $this->assertSame(99.5, $summary['lowest_sli']);
        $this->assertSame(1, $summary['capacity_assets']);
        // Hôtes non rapprochés : l'actif caché n'est pas visible, son hôte non plus rapproché.
        $unmatched = array_column(ZabbixMonitoring::pageData()['unmatched'], 'hostid');
        $this->assertContains('20003', $unmatched);
        $this->assertNotContains('20001', $unmatched);
    }

    public function testFailedReadKeepsThePreviousOne(): void
    {
        ZabbixMonitoring::read($this->client());
        $refused = ZabbixMonitoring::read($this->client(401));
        $this->assertSame(ZabbixClient::ERROR_UNAUTHORIZED, $refused['error']);
        $this->assertSame(3, countElementsInTable(ZabbixTables::HOSTS, ['entities_id' => $this->entity]));
        $lastRead = ZabbixConfig::lastRead();
        $this->assertSame(ZabbixClient::ERROR_UNAUTHORIZED, $lastRead['error']);
        $this->assertSame(3, $lastRead['hosts']);
        $this->assertNotSame('', $lastRead['date']);
    }

    public function testIncidentTicketAndServiceLinksSurviveTheNextRead(): void
    {
        ZabbixMonitoring::read($this->client());
        $problems = ZabbixMonitoring::pageData()['problems'];
        $high = array_values(array_filter($problems, static fn (array $row): bool => $row['eventid'] === '70001'))[0];
        $low = array_values(array_filter($problems, static fn (array $row): bool => $row['eventid'] === '70002'))[0];

        $this->assertSame(0, ZabbixMonitoring::proposeIncident($low['id']), 'Gravité trop faible.');
        $incidentId = ZabbixMonitoring::proposeIncident($high['id']);
        $this->assertGreaterThan(0, $incidentId);
        $incident = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($incident->getFromDB($incidentId));
        $this->assertSame('monitoring', $incident->fields['source']);
        $this->assertSame('critical', $incident->fields['severity']);
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype' => Computer::class, 'items_id' => $this->computers['web'],
        ]));
        $this->assertSame(0, ZabbixMonitoring::proposeIncident($high['id']), 'Une seule fois par problème.');

        $ticketId = ZabbixMonitoring::createTicket($high['id']);
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame(1, countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticketId, 'itemtype' => Computer::class, 'items_id' => $this->computers['web'],
        ]));

        $service = ZabbixMonitoring::pageData()['services'][0];
        $this->assertTrue(ZabbixMonitoring::linkService($service['id'], $this->process));

        // Nouvelle lecture : les liens sont gardés.
        ZabbixMonitoring::read($this->client());
        $this->assertSame(1, countElementsInTable(ZabbixTables::PROBLEMS, [
            'eventid' => '70001', 'plugin_grcmanager_securityincidents_id' => $incidentId, 'tickets_id' => $ticketId,
        ]));
        $this->assertSame($this->process, ZabbixMonitoring::pageData()['services'][0]['process_id']);

        // Onglet du processus : actif dépendant et service rattaché.
        $process = new BusinessProcess();
        $process->getFromDB($this->process);
        $tab = ZabbixMonitoring::tabData($process);
        $this->assertTrue($tab['is_process']);
        $this->assertCount(1, $tab['assets']);
        $this->assertCount(1, $tab['services']);
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render('@grcmanager/zabbix_item_tab.html.twig', $tab);
        $this->assertStringContainsString('grc-zabbix-tab', $html);
        $this->assertStringContainsString('Customer portal', $html);
    }

    public function testPageKriEvidenceTabsAndRights(): void
    {
        ZabbixMonitoring::read($this->client());
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/zabbix_monitoring.html.twig',
            ZabbixMonitoring::pageData()
        );
        $this->assertStringContainsString('grc-zabbix-rate', $html);
        $this->assertStringContainsString('zbx-web' . $this->suffix, $html);
        $this->assertStringContainsString('name="zabbix_incident"', $html);
        $this->assertStringContainsString('name="read_zabbix"', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html, 'Le jeton n\'est jamais réaffiché.');
        $this->assertTrue(ZabbixMonitoring::pageData()['connection']['has_token']);

        foreach (['zabbix_month_availability', 'zabbix_open_high_problems', 'zabbix_unmonitored_critical'] as $source) {
            $this->assertArrayHasKey($source, RiskIndicator::getSources());
        }
        $this->assertSame(99.5, ZabbixMonitoring::lowestServiceAvailability([$this->entity]));
        // Problème haut ouvert de l'actif rapproché et de l'hôte non rapproché de l'entité des lectures.
        $this->assertSame(2.0, ZabbixMonitoring::openHighProblems([$this->entity]));
        $this->assertSame(1.0, ZabbixMonitoring::unmonitoredCriticalAssets([$this->entity]));

        foreach (['A.8.16', 'A.8.6', 'A.5.30'] as $code) {
            $this->assertNotNull(ZabbixMonitoring::evidence($code));
        }
        $this->assertNull(ZabbixMonitoring::evidence('A.5.1'));
        $this->assertSame(1, ZabbixMonitoring::evidence('A.8.16')['critical_uncovered']);

        $computer = new Computer();
        $computer->getFromDB($this->computers['web']);
        $this->assertNotSame('', (new ZabbixMonitoring())->getTabNameForItem($computer));
        $tab = ZabbixMonitoring::tabData($computer);
        $this->assertCount(1, $tab['assets']);
        $this->assertCount(2, $tab['problems']);

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        ZabbixMonitoring::reset();
        try {
            $this->assertFalse(ZabbixMonitoring::canManage());
            $this->assertSame('rights', ZabbixMonitoring::read($this->client())['error']);
            $this->assertFalse(ZabbixMonitoring::clear());
            $this->assertFalse(ZabbixMonitoring::linkService(1, $this->process));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        $computerRight = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        ZabbixMonitoring::reset();
        try {
            $this->assertFalse(ZabbixMonitoring::canView());
            $this->assertNull(ZabbixMonitoring::evidence('A.8.16'));
            $this->assertSame('', (new ZabbixMonitoring())->getTabNameForItem($computer));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $computerRight;
        }
    }

    public function testSettingsAndNoTokenColumn(): void
    {
        global $DB;

        $this->assertFalse(ZabbixConfig::save('ftp://zabbix.example.test', '', false, $this->entity, false)['saved']);
        $this->assertSame(
            ['scope' => Rules::SCOPE_ALL, 'period_days' => 7],
            ZabbixConfig::saveSettings(['scope' => 'all', 'period_days' => '7'])
        );
        $saved = ZabbixConfig::save(self::URL, '', true, $this->entity, true);
        $this->assertSame('changed', $saved['changes'][ZabbixConfig::TOKEN_KEY]);
        $this->assertFalse(ZabbixConfig::hasToken());
        $this->assertTrue(ZabbixConfig::isEnabled());
        foreach ([ZabbixTables::HOSTS, ZabbixTables::PROBLEMS, ZabbixTables::SERVICES] as $table) {
            foreach (array_keys($DB->listFields($table)) as $column) {
                $this->assertStringNotContainsString('token', $column);
            }
        }
    }
}
