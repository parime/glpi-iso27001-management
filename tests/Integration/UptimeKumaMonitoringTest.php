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

use Appliance;
use Computer;
use GlpiPlugin\Grcmanager\AssetClassification;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\Feature\UptimeKumaMonitoringFeature;
use GlpiPlugin\Grcmanager\Install\UptimeKumaTables;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;

/**
 * Issue #842 : services exposés lus dans un faux serveur Uptime Kuma (aucun appel réseau), sur des
 * actifs fictifs d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class UptimeKumaMonitoringTest extends GrcmanagerIntegrationTestCase
{
    private const URL = 'https://status.example.test/';
    private const KEY = 'cle-fictive-kuma';

    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $computers = [];

    private int $appliance;

    private int $process;

    private int $now;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        UptimeKumaMonitoring::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->now = (int) strtotime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Kuma ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo Kuma cachée ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$this->entity]);
        $_SESSION['glpiactive_entity'] = $this->entity;

        global $DB;
        $add = function (string $key, string $name, int $entity, bool $critical = false) use ($DB): void {
            $id = (int) (new Computer())->add(['name' => $name, 'entities_id' => $entity]);
            $this->assertGreaterThan(0, $id);
            if ($critical) {
                $DB->insert(AssetClassification::getTable(), [
                    'itemtype' => Computer::class, 'items_id' => $id, 'availability' => 'high',
                ]);
            }
            $this->computers[$key] = $id;
        };
        // Portail critique (rapproché par le nom d'hôte de l'adresse), serveur publié sans sonde, actif caché.
        $add('web', 'kuma-web' . $this->suffix, $this->entity, true);
        $add('exposed', 'kuma-exposed' . $this->suffix, $this->entity);
        $add('hidden', 'kuma-hidden' . $this->suffix, $this->hiddenEntity, true);
        $DB->insert('glpi_plugin_grcmanager_networkflows', [
            'name' => 'PhpUnitDemo publication ' . $this->suffix, 'flow_type' => 'internet_publication',
            'is_active' => 1, 'destination_itemtype' => Computer::class,
            'destination_items_id' => $this->computers['exposed'], 'ports' => '443',
        ]);
        $this->appliance = (int) (new Appliance())->add([
            'name' => 'kuma-app' . $this->suffix, 'entities_id' => $this->entity,
        ]);
        $this->assertGreaterThan(0, $this->appliance);

        $this->process = (int) (new BusinessProcess())->add([
            'name' => 'PhpUnitDemo vente en ligne ' . $this->suffix, 'entities_id' => $this->entity, 'rto_hours' => 1,
        ]);
        $this->assertGreaterThan(0, $this->process);
        $DB->insert(BusinessProcessItem::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $this->process,
            'itemtype' => Computer::class, 'items_id' => $this->computers['web'],
        ]);

        UptimeKumaConfig::saveSettings([]);
        $saved = UptimeKumaConfig::save(self::URL, self::KEY, false, 'customers', $this->entity, false);
        $this->assertTrue($saved['saved']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        UptimeKumaMonitoring::reset();
        parent::tearDown();
    }

    /**
     * Faux serveur Uptime Kuma 2.0 : le portail en panne depuis trois heures (certificat à neuf jours),
     * l'application en service, une sonde de l'actif caché et une sonde inconnue.
     */
    private function client(int $status = 200): UptimeKumaClient
    {
        $s = $this->suffix;
        $labels = static fn (string $name, string $url): string => sprintf(
            '{monitor_id="%s",monitor_name="%s",monitor_type="http",monitor_url="%s",monitor_hostname="null",'
                . 'monitor_port="null"}',
            $name === 'Portal' ? '7' : ($name === 'Application' ? '8' : ''),
            $name,
            $url
        );
        $metrics = implode("\n", [
            '# TYPE monitor_status gauge',
            'monitor_status' . $labels('Portal', 'https://kuma-web' . $s . '.example.test/shop') . ' 0',
            'monitor_cert_days_remaining' . $labels('Portal', 'https://kuma-web' . $s . '.example.test/shop') . ' 9',
            'monitor_cert_is_valid' . $labels('Portal', 'https://kuma-web' . $s . '.example.test/shop') . ' 1',
            'monitor_status' . $labels('kuma-hidden' . $s, 'https://kuma-hidden' . $s . '.example.test') . ' 1',
            'monitor_status' . $labels('Unknown ' . $s, 'https://unknown' . $s . '.example.test') . ' 1',
        ]);
        $page = (string) json_encode(['publicGroupList' => [[
            'name' => 'Services', 'monitorList' => [
                ['id' => 7, 'name' => 'Portal', 'type' => 'http'],
                ['id' => 8, 'name' => 'kuma-app' . $s, 'type' => 'http', 'certExpiryDaysRemaining' => 200,
                    'validCert' => true],
            ],
        ]]]);
        $time = fn (int $ago): string => gmdate('Y-m-d H:i:s', $this->now - $ago) . '.000';
        $beats = (string) json_encode([
            'heartbeatList' => [
                '7' => [['status' => 1, 'time' => $time(4 * 3600)], ['status' => 0, 'time' => $time(3 * 3600)]],
                '8' => [['status' => 1, 'time' => $time(3600), 'ping' => 80]],
            ],
            'uptimeList' => ['7_24' => 0.875, '8_24' => 1],
        ]);
        $answers = [
            '/metrics'                             => $metrics,
            '/api/status-page/customers'           => $page,
            '/api/status-page/heartbeat/customers' => $beats,
        ];

        return new UptimeKumaClient(
            (string) Rules::normalizeBaseUrl(self::URL),
            self::KEY,
            static function (
                string $method,
                string $url,
                array $headers,
                ?string $body
            ) use (
                $answers,
                $status
            ): array {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($status !== 200 || !isset($answers[$path])) {
                    return ['status' => $status !== 200 ? $status : 404, 'body' => '', 'retry_after' => 0];
                }

                return ['status' => 200, 'body' => $answers[$path], 'retry_after' => 0];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function monitor(string $name): array
    {
        foreach (UptimeKumaMonitoring::pageData()['monitors'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        $this->fail('Sonde absente : ' . $name);
    }

    public function testReadMatchesMonitorsAndComputesOutages(): void
    {
        $result = UptimeKumaMonitoring::read($this->client());
        $this->assertSame('', $result['error']);
        $this->assertSame(4, $result['monitors']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(4, countElementsInTable(UptimeKumaTables::MONITORS, ['entities_id' => $this->entity]));

        $portal = $this->monitor('Portal');
        $this->assertSame(Rules::STATUS_DOWN, $portal['status']);
        $this->assertSame(Computer::class, $portal['itemtype']);
        $this->assertSame($this->computers['web'], $portal['asset_id']);
        $this->assertTrue($portal['critical']);
        $this->assertSame(Rules::CERT_EXPIRING, $portal['cert_status']);
        $this->assertSame(87.5, $portal['uptime_24h']);
        // En panne depuis trois heures, RTO d'une heure : écart, panne longue.
        $this->assertGreaterThanOrEqual(3 * 3600, $portal['longest']);
        $this->assertTrue($portal['rto_gap']);
        $this->assertTrue($portal['long_outage']);
        $this->assertSame($this->process, $portal['processes'][0]['id']);
        $this->assertLessThan(50.0, $portal['availability']);

        $app = $this->monitor('kuma-app' . $this->suffix);
        $this->assertSame(Appliance::class, $app['itemtype']);
        $this->assertSame(Rules::CERT_OK, $app['cert_status']);
        // L'actif de l'entité cachée n'est pas visible : sa sonde n'a pas d'actif ici.
        $this->assertNull($this->monitor('kuma-hidden' . $this->suffix)['asset_key']);

        $summary = UptimeKumaMonitoring::summary();
        $this->assertSame(1, $summary['down_now']);
        $this->assertSame(1, $summary['down_critical']);
        $this->assertSame(1, $summary['certs_action']);
        $this->assertSame(1, $summary['exposed_without']);
        $this->assertSame(1, $summary['rto_gaps']);
        $this->assertSame(2, $summary['unmatched']);
        $exposed = array_column(UptimeKumaMonitoring::pageData()['exposed'], 'name');
        $this->assertSame(['kuma-exposed' . $this->suffix], $exposed);

        // Une seconde lecture n'ajoute pas les relevés déjà connus des pages de statut.
        $samples = countElementsInTable(UptimeKumaTables::SAMPLES, ['entities_id' => $this->entity]);
        UptimeKumaMonitoring::read($this->client());
        $this->assertSame($samples, countElementsInTable(UptimeKumaTables::SAMPLES, ['entities_id' => $this->entity]));
    }

    public function testFailedReadKeepsThePreviousOne(): void
    {
        UptimeKumaMonitoring::read($this->client());
        $refused = UptimeKumaMonitoring::read($this->client(401));
        $this->assertSame(UptimeKumaClient::ERROR_UNAUTHORIZED, $refused['error']);
        $this->assertSame(4, countElementsInTable(UptimeKumaTables::MONITORS, ['entities_id' => $this->entity]));
        $lastRead = UptimeKumaConfig::lastRead();
        $this->assertSame(UptimeKumaClient::ERROR_UNAUTHORIZED, $lastRead['error']);
        $this->assertSame(4, $lastRead['monitors']);
        $this->assertNotSame('', $lastRead['date']);
    }

    public function testIncidentAndProcessLinksSurviveTheNextRead(): void
    {
        UptimeKumaMonitoring::read($this->client());
        $portal = $this->monitor('Portal');
        $app = $this->monitor('kuma-app' . $this->suffix);

        $this->assertSame(0, UptimeKumaMonitoring::proposeIncident($app['id']), 'Aucune panne.');
        $incidentId = UptimeKumaMonitoring::proposeIncident($portal['id']);
        $this->assertGreaterThan(0, $incidentId);
        $incident = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($incident->getFromDB($incidentId));
        $this->assertSame('monitoring', $incident->fields['source']);
        $this->assertSame('availability', $incident->fields['category']);
        $this->assertSame('critical', $incident->fields['severity']);
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype' => Computer::class, 'items_id' => $this->computers['web'],
        ]));
        $this->assertSame(0, UptimeKumaMonitoring::proposeIncident($portal['id']), 'Une seule fois par panne.');

        $this->assertTrue(UptimeKumaMonitoring::linkProcess($app['id'], $this->process));

        // Nouvelle lecture : l'incident et le processus choisi sont gardés.
        UptimeKumaMonitoring::read($this->client());
        $portal = $this->monitor('Portal');
        $this->assertSame($incidentId, $portal['incident_id']);
        $this->assertFalse($portal['can_propose']);
        $this->assertStringContainsString('forcetab=', $portal['crisis_url']);
        $app = $this->monitor('kuma-app' . $this->suffix);
        $this->assertSame($this->process, $app['process_id']);
        $this->assertSame($this->process, $app['processes'][0]['id']);
        $this->assertTrue($app['processes'][0]['chosen']);
    }

    public function testPageKriEvidenceSecurity360AndRights(): void
    {
        UptimeKumaMonitoring::read($this->client());
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/uptime_kuma.html.twig',
            UptimeKumaMonitoring::pageData()
        );
        $this->assertStringContainsString('grc-kuma-availability', $html);
        $this->assertStringContainsString('kuma-web' . $this->suffix, $html);
        $this->assertStringContainsString('name="uptimekuma_incident"', $html);
        $this->assertStringContainsString('name="read_uptimekuma"', $html);
        $this->assertStringNotContainsString(self::KEY, $html, 'La clé d\'API n\'est jamais réaffichée.');
        $this->assertTrue(UptimeKumaMonitoring::pageData()['connection']['has_key']);

        $sources = RiskIndicator::getSources();
        foreach (['uptimekuma_availability', 'uptimekuma_certificates', 'uptimekuma_exposed_without_monitor'] as $key) {
            $this->assertArrayHasKey($key, $sources);
        }
        $this->assertNotNull(UptimeKumaMonitoring::exposedAvailability([$this->entity]));
        $this->assertSame(1.0, UptimeKumaMonitoring::certificatesToRenew([$this->entity]));
        $this->assertSame(1.0, UptimeKumaMonitoring::exposedWithoutMonitor([$this->entity]));

        foreach (['A.8.16', 'A.5.30', 'A.8.24'] as $code) {
            $this->assertNotNull(UptimeKumaMonitoring::evidence($code));
            $evidence = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
                '@grcmanager/_uptime_kuma_evidence.html.twig',
                ['evidence' => UptimeKumaMonitoring::evidence($code)]
            );
            $this->assertStringContainsString('grc-kuma-evidence', $evidence);
        }
        $this->assertNull(UptimeKumaMonitoring::evidence('A.5.1'));

        $computer = new Computer();
        $computer->getFromDB($this->computers['web']);
        $source = UptimeKumaMonitoringFeature::assetSource($computer);
        $this->assertNotNull($source);
        $this->assertSame(Security360Rules::STATUS_CRITICAL, $source['status']);
        $other = new Computer();
        $other->getFromDB($this->computers['exposed']);
        $this->assertNull(UptimeKumaMonitoringFeature::assetSource($other));

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        UptimeKumaMonitoring::reset();
        try {
            $this->assertFalse(UptimeKumaMonitoring::canManage());
            $this->assertSame('rights', UptimeKumaMonitoring::read($this->client())['error']);
            $this->assertFalse(UptimeKumaMonitoring::clear());
            $this->assertFalse(UptimeKumaMonitoring::linkProcess(1, $this->process));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        $computerRight = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        UptimeKumaMonitoring::reset();
        try {
            $this->assertFalse(UptimeKumaMonitoring::canView());
            $this->assertNull(UptimeKumaMonitoring::evidence('A.8.16'));
            $this->assertNull(UptimeKumaMonitoringFeature::assetSource($computer));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $computerRight;
        }
    }

    public function testSettingsClearAndNoKeyColumn(): void
    {
        global $DB;

        $this->assertFalse(
            UptimeKumaConfig::save('ftp://status.example.test', '', false, '', $this->entity, false)['saved']
        );
        $this->assertSame(
            ['period_days' => 7, 'cert_days' => 14, 'outage_minutes' => 60],
            UptimeKumaConfig::saveSettings(['period_days' => '7', 'cert_days' => '14', 'outage_minutes' => '60'])
        );
        $saved = UptimeKumaConfig::save(self::URL, '', true, 'customers, Other-Page', $this->entity, true);
        $this->assertSame('changed', $saved['changes'][UptimeKumaConfig::API_KEY]);
        $this->assertFalse(UptimeKumaConfig::hasApiKey());
        $this->assertSame(['customers', 'other-page'], UptimeKumaConfig::statusPages());
        $this->assertTrue(UptimeKumaConfig::isEnabled());
        $this->assertTrue(UptimeKumaConfig::isConfigured(), 'Une page de statut suffit.');
        foreach ([UptimeKumaTables::MONITORS, UptimeKumaTables::SAMPLES, UptimeKumaTables::LINKS] as $table) {
            foreach (array_keys($DB->listFields($table)) as $column) {
                $this->assertStringNotContainsString('key_', $column);
                $this->assertStringNotContainsString('api', $column);
            }
        }

        UptimeKumaConfig::save(self::URL, self::KEY, false, 'customers', $this->entity, false);
        UptimeKumaMonitoring::read($this->client());
        $this->assertTrue(UptimeKumaMonitoring::clear());
        $this->assertSame(0, countElementsInTable(UptimeKumaTables::MONITORS, ['entities_id' => $this->entity]));
        $this->assertSame(0, countElementsInTable(UptimeKumaTables::SAMPLES, ['entities_id' => $this->entity]));
    }
}
