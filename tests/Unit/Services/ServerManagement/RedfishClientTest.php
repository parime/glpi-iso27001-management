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
use PHPUnit\Framework\TestCase;

/**
 * Issue #843 : lecture d'un faux contrôleur Redfish (en mémoire, aucun appel réseau). Le transport
 * ne reçoit pas de méthode : le client ne sait faire que des GET.
 */
final class RedfishClientTest extends TestCase
{
    private const BASE = 'https://ilo-srv01.example.test';
    private const PASSWORD = 'mot-de-passe-fictif';

    /** @var list<array{url: string, headers: array<string, string>, verify: bool}> */
    private array $calls = [];

    /**
     * Faux iLO : un contrôleur BMC, IPMI sur le réseau activé, SNMP v2c, certificat auto-signé,
     * un serveur au démarrage sécurisé désactivé, trois comptes dont « Administrator » et un
     * emplacement vide.
     *
     * @return array<string, array<mixed>>
     */
    public static function fakeIlo(): array
    {
        $self = ['CommonName' => 'ilo-srv01', 'Organization' => 'Exemple'];

        return [
            '/redfish/v1/' => [
                'RedfishVersion' => '1.13.0', 'Vendor' => 'HPE',
                'Managers' => ['@odata.id' => '/redfish/v1/Managers'],
                'Systems' => ['@odata.id' => '/redfish/v1/Systems'],
                'AccountService' => ['@odata.id' => '/redfish/v1/AccountService'],
            ],
            '/redfish/v1/Managers' => ['Members' => [['@odata.id' => '/redfish/v1/Managers/1']]],
            '/redfish/v1/Managers/1' => [
                'ManagerType' => 'BMC', 'Model' => 'iLO 5', 'FirmwareVersion' => 'iLO 5 v2.72',
                'Status' => ['Health' => 'OK'],
                'NetworkProtocol' => ['@odata.id' => '/redfish/v1/Managers/1/NetworkProtocol'],
            ],
            '/redfish/v1/Managers/1/NetworkProtocol' => [
                'IPMI' => ['ProtocolEnabled' => true, 'Port' => 623],
                'SNMP' => ['ProtocolEnabled' => true, 'EnableSNMPv1' => false, 'EnableSNMPv2c' => true],
                'HTTPS' => ['ProtocolEnabled' => true, 'Certificates' => ['@odata.id' => '/redfish/v1/Managers/1/NetworkProtocol/HTTPS/Certificates']],
            ],
            '/redfish/v1/Managers/1/NetworkProtocol/HTTPS/Certificates' => [
                'Members' => [['@odata.id' => '/redfish/v1/Managers/1/NetworkProtocol/HTTPS/Certificates/1']],
            ],
            '/redfish/v1/Managers/1/NetworkProtocol/HTTPS/Certificates/1' => [
                'Issuer' => $self, 'Subject' => $self, 'ValidNotAfter' => '2027-03-01T00:00:00Z',
            ],
            '/redfish/v1/Systems' => ['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]],
            '/redfish/v1/Systems/1' => [
                'SerialNumber' => 'CZJ0000TEST', 'Model' => 'ProLiant DL380 Gen10', 'Manufacturer' => 'HPE',
                'BiosVersion' => 'U30 v2.80', 'PowerState' => 'On',
                'Status' => ['Health' => 'OK', 'HealthRollup' => 'Warning'],
                'SecureBoot' => ['@odata.id' => '/redfish/v1/Systems/1/SecureBoot'],
            ],
            '/redfish/v1/Systems/1/SecureBoot' => ['SecureBootEnable' => false],
            '/redfish/v1/AccountService' => ['Accounts' => ['@odata.id' => '/redfish/v1/AccountService/Accounts']],
            '/redfish/v1/AccountService/Accounts' => ['Members' => [
                ['@odata.id' => '/redfish/v1/AccountService/Accounts/1'],
                ['@odata.id' => '/redfish/v1/AccountService/Accounts/2'],
                ['@odata.id' => '/redfish/v1/AccountService/Accounts/3'],
                // Lien vers un autre hôte : jamais suivi.
                ['@odata.id' => 'https://piege.example.test/redfish/v1/AccountService/Accounts/4'],
            ]],
            '/redfish/v1/AccountService/Accounts/1' => ['UserName' => 'Administrator', 'RoleId' => 'Administrator', 'Enabled' => true],
            '/redfish/v1/AccountService/Accounts/2' => ['UserName' => 'audit-grc', 'RoleId' => 'ReadOnly', 'Enabled' => true],
            '/redfish/v1/AccountService/Accounts/3' => ['UserName' => '', 'RoleId' => 'None', 'Enabled' => false],
        ];
    }

    /**
     * @param array<string, array<mixed>|int> $resources chemin => réponse JSON, ou code HTTP
     */
    private function client(array $resources, bool $verifyTls = true, int $transportStatus = 200, string $transportError = ''): RedfishClient
    {
        return new RedfishClient(
            self::BASE,
            'audit-grc',
            self::PASSWORD,
            $verifyTls,
            function (string $url, array $headers, bool $verify) use ($resources, $transportStatus, $transportError): array {
                $this->calls[] = ['url' => $url, 'headers' => $headers, 'verify' => $verify];
                if ($transportStatus !== 200) {
                    return ['status' => $transportStatus, 'body' => '', 'retry_after' => 0, 'error' => $transportError];
                }
                $path = substr($url, strlen(self::BASE));
                $resource = $resources[$path] ?? 404;
                if (is_int($resource)) {
                    return ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status'];
                }

                return ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
            }
        );
    }

    public function testReadsInventoryRiskySettingsAndAccounts(): void
    {
        $result = $this->client(self::fakeIlo())->fetch();

        $this->assertTrue($result['complete']);
        $this->assertSame('', $result['error']);
        $s = $result['snapshot'];
        $this->assertSame('HPE', $s['vendor']);
        $this->assertSame('iLO 5', $s['manager_model']);
        $this->assertSame('iLO 5 v2.72', $s['firmware_version']);
        $this->assertSame('U30 v2.80', $s['bios_version']);
        $this->assertSame('CZJ0000TEST', $s['serial']);
        $this->assertSame('Warning', $s['health']);
        $this->assertSame(1, $s['ipmi_lan']);
        $this->assertSame(Rules::UNKNOWN, $s['telnet']);
        $this->assertSame(1, $s['snmp_v1v2']);
        $this->assertSame(0, $s['secure_boot']);
        $this->assertSame(1, $s['cert_self_signed']);
        $this->assertSame('2027-03-01', $s['cert_expiry']);
        $this->assertSame(1, $s['tls_verified']);
        $this->assertTrue($result['accounts_read']);
        $this->assertSame(['Administrator', 'audit-grc'], array_column($result['accounts'], 'username'));
        $this->assertSame(1, $result['accounts'][0]['enabled']);
    }

    public function testOnlyCallsTheConfiguredAddressWithBasicAuthentication(): void
    {
        $this->client(self::fakeIlo())->fetch();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertStringStartsWith(self::BASE . '/redfish/v1', $call['url']);
            $this->assertStringNotContainsString(self::PASSWORD, $call['url']);
            $this->assertSame('Basic ' . base64_encode('audit-grc:' . self::PASSWORD), $call['headers']['Authorization']);
            $this->assertTrue($call['verify']);
        }
        $this->assertNotContains(
            'https://piege.example.test/redfish/v1/AccountService/Accounts/4',
            array_column($this->calls, 'url')
        );
    }

    public function testSelfSignedOptionDisablesVerificationAndIsReported(): void
    {
        $result = $this->client(self::fakeIlo(), false)->fetch();

        $this->assertSame(0, $result['snapshot']['tls_verified']);
        foreach ($this->calls as $call) {
            $this->assertFalse($call['verify']);
        }
    }

    public function testOptionalSectionsMayBeMissing(): void
    {
        $resources = self::fakeIlo();
        $resources['/redfish/v1/Managers/1/NetworkProtocol'] = 404;
        $resources['/redfish/v1/Systems/1/SecureBoot'] = 404;
        $resources['/redfish/v1/AccountService/Accounts'] = 403;
        $result = $this->client($resources)->fetch();

        $this->assertTrue($result['complete']);
        $this->assertSame(Rules::UNKNOWN, $result['snapshot']['ipmi_lan']);
        $this->assertSame(Rules::UNKNOWN, $result['snapshot']['secure_boot']);
        $this->assertFalse($result['accounts_read']);
        $this->assertSame([], $result['accounts']);
    }

    public function testErrorsAreMappedAndNothingIsComplete(): void
    {
        $this->assertSame(RedfishClient::ERROR_UNAUTHORIZED, $this->client(['/redfish/v1/' => 401])->fetch()['error']);
        $this->assertSame(RedfishClient::ERROR_NOT_REDFISH, $this->client([])->fetch()['error']);
        $this->assertSame(RedfishClient::ERROR_NOT_REDFISH, $this->client(['/redfish/v1/' => ['Hello' => 'world']])->fetch()['error']);
        $tls = $this->client([], true, 0, 'transport_error: SSL certificate problem: self-signed certificate')->fetch();
        $this->assertSame(RedfishClient::ERROR_TLS, $tls['error']);
        $this->assertFalse($tls['complete']);
        $internal = $this->client([], true, 0, 'internal_address')->fetch();
        $this->assertSame(RedfishClient::ERROR_INTERNAL, $internal['error']);
        $this->assertSame(RedfishClient::ERROR_UNREACHABLE, $this->client([], true, 0, 'transport_error: timeout')->fetch()['error']);

        $resources = self::fakeIlo();
        $resources['/redfish/v1/Systems/1'] = 500;
        $failed = $this->client($resources)->fetch();
        $this->assertFalse($failed['complete']);
        $this->assertSame(RedfishClient::ERROR_STATUS, $failed['error']);
    }

    public function testRefusesPathsOutsideRedfishAndMissingConfiguration(): void
    {
        $client = $this->client(self::fakeIlo());
        $this->assertSame(RedfishClient::ERROR_INVALID, $client->get('https://piege.example.test/redfish/v1/')['error']);
        $this->assertSame(RedfishClient::ERROR_INVALID, $client->get('/redfish/v1/../x')['error']);
        $this->assertSame([], $this->calls);

        $empty = new RedfishClient('', '', '', true, static fn (): array => ['status' => 200, 'body' => '{}', 'retry_after' => 0]);
        $this->assertSame(RedfishClient::ERROR_CONFIG, $empty->fetch()['error']);
    }

    public function testClientHasNoWriteMethod(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
            (new \ReflectionClass(RedfishClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        foreach (['post', 'patch', 'put', 'delete', 'reset', 'update', 'action'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Services/ServerManagement/RedfishClient.php');
        $this->assertSame(1, preg_match_all("/Http::send\\(/", $source));
        $this->assertSame(0, preg_match("/Http::send\\('(POST|PATCH|PUT|DELETE)'/", $source));
    }
}
