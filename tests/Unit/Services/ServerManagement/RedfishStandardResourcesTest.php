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
 * Issue #1085 : ressources Redfish lues aux emplacements documentés.
 *
 * - Maquette publique DMTF « public-rackmount1 » (dépôt DMTF/Redfish-Publications, licence BSD
 *   3 clauses, copie partielle dans tests/fixtures/redfish/dmtf-public-rackmount1) : politique TLS,
 *   certificat HTTPS, ThermalSubsystem et PowerSubsystem ;
 * - HPE iLO : certificat HTTPS dans SecurityService/HttpsCert (X509CertificateInformation) ;
 * - service de certificats DMTF (CertificateLocations), tel que le décrit Lenovo XClarity Controller.
 * Aucun appel réseau.
 */
final class RedfishStandardResourcesTest extends TestCase
{
    private const BASE = 'https://bmc.example.test';
    private const MOCKUP = __DIR__ . '/../../../fixtures/redfish/dmtf-public-rackmount1';

    /** @var list<string> */
    private array $calls = [];

    /** @param array<string, array<mixed>>|null $resources null : maquette DMTF */
    private function client(?array $resources = null): RedfishClient
    {
        return new RedfishClient(
            self::BASE,
            'lecteur-grc',
            'mot-de-passe-fictif',
            true,
            function (string $url) use ($resources): array {
                $path = substr($url, strlen(self::BASE));
                $this->calls[] = $path;
                $body = null;
                if ($resources === null) {
                    $relative = trim(substr($path, strlen('/redfish/v1')), '/');
                    $file = self::MOCKUP . ($relative === '' ? '' : '/' . $relative) . '/index.json';
                    if (str_starts_with($path, '/redfish/v1') && !str_contains($relative, '..') && is_file($file)) {
                        $body = (string) file_get_contents($file);
                    }
                } elseif (is_array($resources[$path] ?? null)) {
                    $body = (string) json_encode($resources[$path]);
                }
                if ($body === null) {
                    return ['status' => 404, 'body' => '', 'retry_after' => 0, 'error' => 'http_status'];
                }

                return ['status' => 200, 'body' => $body, 'retry_after' => 0, 'error' => ''];
            }
        );
    }

    public function testDmtfMockupController(): void
    {
        $result = $this->client()->fetch();

        $this->assertTrue($result['complete']);
        $snapshot = $result['snapshot'];
        // TLS.Server : Allowed [1.3], Denied [1.1, 1.2] ; TLS 1.0 et 1.1 non autorisés.
        $this->assertSame(0, $snapshot['weak_tls']);
        $this->assertContains('/redfish/v1/Managers/BMC/SecurityPolicy', $this->calls);
        // Certificat HTTPS de la maquette : auto-signé, expiré le 7 septembre 2019.
        $this->assertSame(1, $snapshot['cert_self_signed']);
        $this->assertSame('2019-09-07', $snapshot['cert_expiry']);
        $this->assertSame('437XR1138R2', $snapshot['serial']);
    }

    public function testDmtfMockupHealthUsesSubsystems(): void
    {
        $health = (new Reader($this->client()))->read();

        $this->assertTrue($health['read']);
        $this->assertContains('/redfish/v1/Chassis/1U/ThermalSubsystem/Fans/Bay1', $this->calls);
        $this->assertContains('/redfish/v1/Chassis/1U/PowerSubsystem/PowerSupplies/Bay1', $this->calls);
        // Ressources dépréciées non lues quand les récentes existent.
        $this->assertNotContains('/redfish/v1/Chassis/1U/Thermal', $this->calls);
        $this->assertNotContains('/redfish/v1/Chassis/1U/Power', $this->calls);

        $byName = [];
        foreach ($health['components'] as $component) {
            $byName[$component['kind'] . ':' . $component['name']] = $component;
        }
        $fan = $byName[Reader::KIND_FAN . ':Fan Bay 1'] ?? null;
        $this->assertNotNull($fan);
        $this->assertSame('45 %', $fan['reading']);
        $supply = $byName[Reader::KIND_POWER . ':Power Supply Bay 1'] ?? null;
        $this->assertNotNull($supply);
        $this->assertSame(Rules::HEALTH_WARNING, $supply['health']);
        $this->assertStringContainsString('400 W', $supply['reading']);
        $temperature = $byName[Reader::KIND_TEMPERATURE . ':Intake'] ?? null;
        $this->assertNotNull($temperature);
        $this->assertSame('25 °C', $temperature['reading']);
    }

    /**
     * Contrôleur minimal : racine, Manager, protocoles réseau sans lien de certificats, système.
     *
     * @param array<mixed> $manager
     * @param array<mixed> $root
     * @return array<string, array<mixed>>
     */
    private static function controller(array $manager, array $root = []): array
    {
        return [
            '/redfish/v1/' => $root + [
                'RedfishVersion' => '1.17.0',
                'Managers' => ['@odata.id' => '/redfish/v1/Managers'],
                'Systems' => ['@odata.id' => '/redfish/v1/Systems'],
            ],
            '/redfish/v1/Managers' => ['Members' => [['@odata.id' => '/redfish/v1/Managers/1']]],
            '/redfish/v1/Managers/1' => $manager + [
                '@odata.id' => '/redfish/v1/Managers/1', 'ManagerType' => 'BMC',
                'NetworkProtocol' => ['@odata.id' => '/redfish/v1/Managers/1/NetworkProtocol'],
            ],
            '/redfish/v1/Managers/1/NetworkProtocol' => ['HTTPS' => ['ProtocolEnabled' => true]],
            '/redfish/v1/Systems' => ['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]],
            '/redfish/v1/Systems/1' => ['SerialNumber' => 'SRV-CERT-0001'],
        ];
    }

    /** HPE iLO : HpeHttpsCert, émetteur et sujet en chaînes (exemple de la documentation HPE). */
    public function testHpeHttpsCertificate(): void
    {
        $manager = ['Oem' => ['Hpe' => ['Links' => [
            'SecurityService' => ['@odata.id' => '/redfish/v1/Managers/1/SecurityService/'],
        ]]]];
        $subject = 'C = FR, ST = Region Sud, L = Sophia-Antipolis, O = HPE, OU = Compute, CN = foo.domain.com';
        $certificate = static fn (string $issuer): array => ['X509CertificateInformation' => [
            'Issuer' => $issuer,
            'Subject' => $subject,
            'ValidNotAfter' => '2033-02-06T17:34:46Z',
            'ValidNotBefore' => '2023-02-09T17:34:46Z',
        ]];
        $resources = self::controller($manager) + [
            '/redfish/v1/Managers/1/SecurityService/' => ['SecurityState' => 'SecureStandard'],
        ];
        $path = '/redfish/v1/Managers/1/SecurityService/HttpsCert';

        $signed = $resources + [$path => $certificate(
            'C = FR, ST = Provence-Alpes-Cote d Azur, L = Sophia-Antipolis, O = HPE, OU = Compute, CN = myca.domain.com'
        )];
        $snapshot = $this->client($signed)->fetch()['snapshot'];
        $this->assertSame('2033-02-06', $snapshot['cert_expiry']);
        $this->assertSame(0, $snapshot['cert_self_signed']);
        $this->assertSame(0, $snapshot['weak_tls']);
        $this->assertContains($path, $this->calls);

        $self = $resources + [$path => $certificate(
            'C = FR,  ST = Region Sud, L = Sophia-Antipolis, O = HPE, OU = Compute, CN = FOO.domain.com'
        )];
        $this->assertSame(1, $this->client($self)->fetch()['snapshot']['cert_self_signed']);
    }

    /** Service de certificats DMTF : seuls les certificats HTTPS du contrôleur sont lus. */
    public function testCertificateLocations(): void
    {
        $name = ['CommonName' => 'XCC-0000-EXEMPLE', 'Organization' => 'Exemple'];
        $https = '/redfish/v1/Managers/1/NetworkProtocol/HTTPS/Certificates/1';
        $ldap = '/redfish/v1/AccountService/LDAP/Certificates/1';
        $root = ['CertificateService' => ['@odata.id' => '/redfish/v1/CertificateService']];
        $resources = self::controller([], $root) + [
            '/redfish/v1/CertificateService' => [
                'CertificateLocations' => ['@odata.id' => '/redfish/v1/CertificateService/CertificateLocations'],
            ],
            '/redfish/v1/CertificateService/CertificateLocations' => ['Links' => ['Certificates' => [
                ['@odata.id' => $ldap],
                ['@odata.id' => $https],
            ]]],
            $https => ['Issuer' => $name, 'Subject' => $name, 'ValidNotAfter' => '2029-12-17T01:51:18+00:00'],
            $ldap => ['Issuer' => $name, 'Subject' => $name, 'ValidNotAfter' => '2020-01-01T00:00:00Z'],
        ];

        $snapshot = $this->client($resources)->fetch()['snapshot'];

        $this->assertSame('2029-12-17', $snapshot['cert_expiry']);
        $this->assertSame(1, $snapshot['cert_self_signed']);
        $this->assertContains($https, $this->calls);
        $this->assertNotContains($ldap, $this->calls);
    }

    public function testSelfSignedWithStringNames(): void
    {
        $this->assertTrue(Rules::isSelfSigned(['Issuer' => 'CN = a, O = b', 'Subject' => 'cn=A,o=B']));
        $this->assertFalse(Rules::isSelfSigned(['Issuer' => 'CN = ca', 'Subject' => 'CN = a']));
        $this->assertFalse(Rules::isSelfSigned(['Issuer' => '', 'Subject' => '']));
    }
}
