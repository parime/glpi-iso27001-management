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
use ComputerModel;
use ComputerType;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables as Tables;
use GlpiPlugin\Grcmanager\NetworkFlow;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishClient;
use GlpiPlugin\Grcmanager\Tests\Unit\Services\ServerManagement\RedfishClientTest;

/**
 * Issue #843 : contrôleurs de gestion des serveurs lus dans un faux Redfish (aucun appel réseau),
 * sur des ordinateurs fictifs d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class ServerManagementTest extends GrcmanagerIntegrationTestCase
{
    private const PASSWORD = 'mot-de-passe-fictif';

    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $computers = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Unit/Services/ServerManagement/RedfishClientTest.php';
        ServerManagement::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo BMC ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo BMC cachée ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$this->entity]);
        $_SESSION['glpiactive_entity'] = $this->entity;
        Http::setAllowPrivateNetworks(true);

        $serverType = (int) (new ComputerType())->add(['name' => 'Server ' . $this->suffix]);
        $virtualModel = (int) (new ComputerModel())->add(['name' => 'VMware Virtual Platform ' . $this->suffix]);
        $add = function (string $key, int $entity, string $serial, int $model = 0) use ($serverType): void {
            $id = (int) (new Computer())->add([
                'name' => 'bmc-' . $key . $this->suffix, 'entities_id' => $entity, 'serial' => $serial,
                'computertypes_id' => $serverType, 'computermodels_id' => $model,
            ]);
            $this->assertGreaterThan(0, $id);
            $this->computers[$key] = $id;
        };
        // Serveur du faux iLO (même numéro de série), serveur physique sans contrôleur, machine
        // virtuelle (pas de contrôleur attendu), serveur de l'entité cachée.
        $add('db', $this->entity, 'CZJ0000TEST');
        $add('web', $this->entity, 'WEB' . $this->suffix);
        $add('vm', $this->entity, 'VM' . $this->suffix, $virtualModel);
        $add('hidden', $this->hiddenEntity, 'HID' . $this->suffix);

        // Réseau d'administration déclaré dans la matrice des flux.
        $flow = (int) (new NetworkFlow())->add([
            'name' => 'Administration ' . $this->suffix, 'flow_type' => 'admin_access',
            'source_label' => 'Bastion 192.0.2.10', 'destination_label' => 'MGMT 10.0.99.0/24',
            'protocol' => 'tcp', 'ports' => '443', 'justification' => 'Test', 'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $flow);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        ServerManagement::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, array<mixed>|int> $resources
     */
    private static function fake(array $resources, int $status = 200): RedfishClient
    {
        return new RedfishClient(
            'https://10.0.99.12',
            'audit-grc',
            self::PASSWORD,
            true,
            static function (string $url) use ($resources, $status): array {
                if ($status !== 200) {
                    return ['status' => $status, 'body' => '', 'retry_after' => 0, 'error' => 'http_status'];
                }
                $resource = $resources[substr($url, strlen('https://10.0.99.12'))] ?? 404;

                return is_int($resource)
                    ? ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status']
                    : ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
            }
        );
    }

    private function declare(string $url = 'https://10.0.99.12'): int
    {
        $saved = ServerManagement::saveController([
            'name' => 'iLO srv-db ' . $this->suffix, 'url' => $url, 'username' => 'audit-grc',
            'password' => self::PASSWORD,
        ]);
        $this->assertSame('', $saved['error']);
        $this->assertGreaterThan(0, $saved['id']);

        return $saved['id'];
    }

    public function testPasswordIsEncryptedAndNeverShown(): void
    {
        global $DB;

        $id = $this->declare();
        $row = $DB->request(['FROM' => Tables::CONTROLLERS, 'WHERE' => ['id' => $id]])->current();
        $this->assertNotSame(self::PASSWORD, $row['password']);
        $this->assertSame(self::PASSWORD, (new GLPIKey())->decrypt((string) $row['password']));
        $this->assertSame($this->entity, (int) $row['entities_id']);
        $this->assertSame(0, (int) $row['allow_self_signed']);

        $page = (string) json_encode(ServerManagement::pageData($id));
        $this->assertStringNotContainsString(self::PASSWORD, $page);
        $this->assertStringNotContainsString((string) $row['password'], $page);

        // Autre adresse sans ressaisir le mot de passe : refusé.
        $moved = ServerManagement::saveController([
            'name' => 'iLO srv-db', 'url' => 'https://10.0.99.13', 'username' => 'audit-grc', 'password' => '',
        ], $id);
        $this->assertSame('password_required', $moved['error']);
        // Même adresse, mot de passe vide : celui enregistré est gardé.
        $kept = ServerManagement::saveController([
            'name' => 'iLO srv-db renommé', 'url' => 'https://10.0.99.12', 'username' => 'audit-grc',
            'password' => '', 'allow_self_signed' => true,
        ], $id);
        $this->assertSame('', $kept['error']);
        $row = $DB->request(['FROM' => Tables::CONTROLLERS, 'WHERE' => ['id' => $id]])->current();
        $this->assertSame(self::PASSWORD, (new GLPIKey())->decrypt((string) $row['password']));
        $this->assertSame(1, (int) $row['allow_self_signed']);

        $this->assertSame('invalid_url', ServerManagement::saveController([
            'name' => 'x', 'url' => 'http://10.0.99.12', 'username' => 'a', 'password' => 'b',
        ])['error']);
    }

    public function testReadMatchesTheServerAndReportsGaps(): void
    {
        global $DB;

        $id = $this->declare();
        $result = ServerManagement::read($id, self::fake(RedfishClientTest::fakeIlo()));
        $this->assertSame('', $result['error']);
        $this->assertSame(2, $result['accounts']);

        $row = $DB->request(['FROM' => Tables::CONTROLLERS, 'WHERE' => ['id' => $id]])->current();
        $this->assertSame('iLO 5 v2.72', $row['firmware_version']);
        $this->assertSame('10.0.99.12', $row['ip_address']);
        $this->assertSame(1, (int) $row['ipmi_lan']);

        $data = ServerManagement::pageData();
        $this->assertSame(1, $data['total_rows']);
        $controller = $data['rows'][0];
        $this->assertSame('serial', $controller['matched_by']);
        $this->assertSame('bmc-db' . $this->suffix, $controller['computer']['name']);
        $this->assertTrue($controller['in_admin']);
        $this->assertContains(Rules::GAP_IPMI_LAN, $controller['gaps']);
        $this->assertContains(Rules::GAP_DEFAULT_ACCOUNT, $controller['gaps']);
        $this->assertContains(Rules::GAP_SECURE_BOOT_OFF, $controller['gaps']);
        $this->assertNotContains(Rules::GAP_OUTSIDE_ADMIN_NETWORK, $controller['gaps']);
        $this->assertTrue($controller['high']);

        // Serveurs physiques sans contrôleur : le serveur web seulement (ni la VM, ni l'entité cachée).
        $this->assertSame(['bmc-web' . $this->suffix], array_column($data['servers_without'], 'name'));
        $this->assertSame(2, $data['summary']['physical_servers']);
        $this->assertSame(50, $data['summary']['coverage']);
        $this->assertSame(1, $data['summary']['default_accounts']);

        // Onglet de l'ordinateur.
        $computer = new Computer();
        $this->assertTrue($computer->getFromDB($this->computers['db']));
        $this->assertCount(1, ServerManagement::tabData($computer)['rows']);
        $other = new Computer();
        $this->assertTrue($other->getFromDB($this->computers['web']));
        $this->assertSame([], ServerManagement::tabData($other)['rows']);

        // Preuve et KRI.
        $evidence = ServerManagement::evidence('A.8.2');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['default_accounts']);
        $this->assertSame(1, $evidence['admin_accounts']);
        $this->assertNull(ServerManagement::evidence('A.5.1'));
        $this->assertSame(1.0, ServerManagement::riskyControllers(RiskIndicator::perimeter($this->entity)));
        $this->assertNull(ServerManagement::riskyControllers([$this->hiddenEntity]));
    }

    public function testFailedReadKeepsThePreviousOne(): void
    {
        global $DB;

        $id = $this->declare();
        $this->assertSame('', ServerManagement::read($id, self::fake(RedfishClientTest::fakeIlo()))['error']);
        $failed = ServerManagement::read($id, self::fake([], 401));
        $this->assertSame(RedfishClient::ERROR_UNAUTHORIZED, $failed['error']);

        $row = $DB->request(['FROM' => Tables::CONTROLLERS, 'WHERE' => ['id' => $id]])->current();
        $this->assertSame('iLO 5 v2.72', $row['firmware_version']);
        $this->assertSame(RedfishClient::ERROR_UNAUTHORIZED, $row['read_error']);
        $this->assertSame(2, countElementsInTable(Tables::ACCOUNTS, ['plugin_grcmanager_bmccontrollers_id' => $id]));
        $gaps = ServerManagement::pageData()['rows'][0]['gaps'];
        $this->assertContains(Rules::GAP_READ_FAILED, $gaps);
        $this->assertContains(Rules::GAP_IPMI_LAN, $gaps);
    }

    public function testOutsideTheAdministrationNetworks(): void
    {
        $saved = ServerManagement::saveController([
            'name' => 'iDRAC ' . $this->suffix, 'url' => 'https://10.2.0.5', 'username' => 'audit-grc',
            'password' => self::PASSWORD,
        ]);
        $transport = static function (string $url): array {
            $resource = RedfishClientTest::fakeIlo()[substr($url, strlen('https://10.2.0.5'))] ?? 404;

            return is_int($resource)
                ? ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status']
                : ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
        };
        $client = new RedfishClient('https://10.2.0.5', 'audit-grc', self::PASSWORD, true, $transport);
        $this->assertSame('', ServerManagement::read($saved['id'], $client)['error']);
        $this->assertContains(Rules::GAP_OUTSIDE_ADMIN_NETWORK, ServerManagement::pageData()['rows'][0]['gaps']);
    }

    public function testCsvImportForControllersWithoutRedfish(): void
    {
        $csv = "name;address;model;firmware;serial;ipmi_lan;weak_tls;accounts\n"
            . "bmc-ipmi-{$this->suffix};10.0.99.40;X11;3.10;WEB{$this->suffix};yes;yes;ADMIN:Administrator\n";
        $result = ServerManagement::importCsv($csv);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $again = ServerManagement::importCsv($csv);
        $this->assertSame(1, $again['updated']);

        $rows = ServerManagement::pageData()['rows'];
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_redfish']);
        $this->assertSame('bmc-web' . $this->suffix, $rows[0]['computer']['name']);
        $this->assertContains(Rules::GAP_WEAK_TLS, $rows[0]['gaps']);
        $this->assertContains(Rules::GAP_DEFAULT_ACCOUNT, $rows[0]['gaps']);
        $this->assertNotContains(Rules::GAP_TLS_NOT_VERIFIED, $rows[0]['gaps']);
        // Un contrôleur importé ne se lit pas par Redfish.
        $this->assertSame('csv_source', ServerManagement::read((int) $rows[0]['id'])['error']);
        $this->assertSame(['csv_missing_name'], ServerManagement::importCsv("address\n10.0.0.1\n")['errors']);
    }

    public function testHiddenEntityAndRights(): void
    {
        global $DB;

        $DB->insert(Tables::CONTROLLERS, [
            'entities_id' => $this->hiddenEntity, 'name' => 'Caché ' . $this->suffix, 'source' => Rules::SOURCE_CSV,
            'date_read' => date('Y-m-d H:i:s'),
        ]);
        $hiddenId = (int) $DB->insertId();
        $this->assertSame(0, ServerManagement::pageData()['total_rows']);
        $this->assertNull(ServerManagement::controller($hiddenId));
        $this->assertFalse(ServerManagement::deleteController($hiddenId));
        $this->assertSame('not_found', ServerManagement::read($hiddenId)['error']);

        $id = $this->declare();
        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertSame('rights', ServerManagement::read($id)['error']);
            $this->assertFalse(ServerManagement::deleteController($id));
            $this->assertSame(['rights'], ServerManagement::importCsv("name\nx\n")['errors']);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
        $this->assertTrue(ServerManagement::deleteController($id));
    }
}
