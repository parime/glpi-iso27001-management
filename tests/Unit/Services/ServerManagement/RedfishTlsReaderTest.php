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
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishTlsReader;
use GlpiPlugin\Grcmanager\Services\ServerManagement\TlsEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Issue #843, lot 4 : protocoles TLS anciens lus dans les extensions Oem des constructeurs et dans
 * la politique de sécurité Redfish, sur de fausses réponses (aucun appel réseau, GET seulement).
 */
final class RedfishTlsReaderTest extends TestCase
{
    private const BASE = 'https://bmc.example.test';

    /** @var list<string> */
    private array $calls = [];

    /**
     * Faux contrôleur : la racine, un Manager et ses ressources liées.
     *
     * @param array<mixed> $manager
     * @param array<string, array<mixed>|int> $extra
     * @return array<string, array<mixed>|int>
     */
    private static function controller(array $manager, array $extra = [], array $protocol = []): array
    {
        return [
            '/redfish/v1/' => [
                'RedfishVersion' => '1.15.0',
                'Managers' => ['@odata.id' => '/redfish/v1/Managers'],
                'Systems' => ['@odata.id' => '/redfish/v1/Systems'],
            ],
            '/redfish/v1/Managers' => ['Members' => [['@odata.id' => '/redfish/v1/Managers/1']]],
            '/redfish/v1/Managers/1' => $manager + [
                'ManagerType' => 'BMC', 'Model' => 'Exemple', 'FirmwareVersion' => '1.00',
                'NetworkProtocol' => ['@odata.id' => '/redfish/v1/Managers/1/NetworkProtocol'],
            ],
            '/redfish/v1/Managers/1/NetworkProtocol' => $protocol + ['HTTPS' => ['ProtocolEnabled' => true]],
            '/redfish/v1/Systems' => ['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]],
            '/redfish/v1/Systems/1' => ['SerialNumber' => 'SRV-TLS-0001', 'Model' => 'Serveur fictif'],
        ] + $extra;
    }

    /**
     * @param array<string, array<mixed>|int> $resources
     */
    private function fetch(array $resources): array
    {
        $client = new RedfishClient(
            self::BASE,
            'audit-grc',
            'mot-de-passe-fictif',
            true,
            function (string $url) use ($resources): array {
                $this->calls[] = $url;
                $resource = $resources[substr($url, strlen(self::BASE))] ?? 404;
                if (is_int($resource)) {
                    return ['status' => $resource, 'body' => '', 'retry_after' => 0, 'error' => 'http_status'];
                }

                return ['status' => 200, 'body' => (string) json_encode($resource), 'retry_after' => 0, 'error' => ''];
            }
        );
        $result = $client->fetch();
        $this->assertTrue($result['complete']);

        return $result['snapshot'];
    }

    /** HPE iLO 6 : service de sécurité lié par Oem.Hpe, TLS 1.1 encore activé. */
    public function testHpeSecurityServiceWithTls11Enabled(): void
    {
        $manager = ['Oem' => ['Hpe' => ['Links' => [
            'SecurityService' => ['@odata.id' => '/redfish/v1/Managers/1/SecurityService'],
        ]]]];
        $service = [
            'SecurityState' => 'Production',
            'TLSVersion' => [
                'TLS1_0' => 'Disabled', 'TLS1_1' => 'Enabled', 'TLS1_2' => 'Enabled', 'TLS1_3' => 'Enabled',
            ],
        ];
        $snapshot = $this->fetch(self::controller($manager, ['/redfish/v1/Managers/1/SecurityService' => $service]));

        $this->assertSame(1, $snapshot['weak_tls']);
        $this->assertContains(self::BASE . '/redfish/v1/Managers/1/SecurityService', $this->calls);
    }

    /** HPE iLO 5 : mode de sécurité élevé (TLS 1.2 et plus seulement), sans objet TLSVersion. */
    public function testHpeStrictSecurityStateMeansModernTlsOnly(): void
    {
        $manager = ['Oem' => ['Hpe' => ['Links' => [
            'SecurityService' => ['@odata.id' => '/redfish/v1/Managers/1/SecurityService'],
        ]]]];
        $snapshot = $this->fetch(self::controller(
            $manager,
            ['/redfish/v1/Managers/1/SecurityService' => ['SecurityState' => 'HighSecurity']]
        ));

        $this->assertSame(0, $snapshot['weak_tls']);
    }

    /** Dell iDRAC 9 : attribut WebServer.1.TLSProtocol, lien de l'iDRAC choisi parmi plusieurs. */
    public function testDellAttributes(): void
    {
        $manager = ['Links' => ['Oem' => ['Dell' => ['DellAttributes' => [
            ['@odata.id' => '/redfish/v1/Managers/1/Oem/Dell/DellAttributes/System.Embedded.1'],
            ['@odata.id' => '/redfish/v1/Managers/1/Oem/Dell/DellAttributes/iDRAC.Embedded.1'],
        ]]]]];
        $attributes = static fn (string $tls): array => ['Attributes' => [
            'WebServer.1.Enable' => 'Enabled',
            'WebServer.1.TLSProtocol' => $tls,
            'WebServer.1.SSLEncryptionBitLength' => '256-Bit or higher',
        ]];
        $path = '/redfish/v1/Managers/1/Oem/Dell/DellAttributes/iDRAC.Embedded.1';

        $old = $this->fetch(self::controller($manager, [$path => $attributes('TLS 1.1 and Higher')]));
        $this->assertSame(1, $old['weak_tls']);
        $this->assertContains(self::BASE . $path, $this->calls);
        $this->assertNotContains(
            self::BASE . '/redfish/v1/Managers/1/Oem/Dell/DellAttributes/System.Embedded.1',
            $this->calls
        );

        $this->assertSame(
            0,
            $this->fetch(self::controller($manager, [$path => $attributes('TLS 1.2 Only')]))['weak_tls']
        );
        $this->assertSame(
            0,
            $this->fetch(self::controller($manager, [$path => $attributes('TLS 1.3 Only')]))['weak_tls']
        );
    }

    /** Politique de sécurité Redfish standard : versions autorisées côté serveur. */
    public function testDmtfSecurityPolicy(): void
    {
        $manager = ['SecurityPolicy' => ['@odata.id' => '/redfish/v1/Managers/1/SecurityPolicy']];
        $policy = static fn (array $allowed): array => ['TLS' => [
            'Server' => ['Versions' => ['Allowed' => $allowed]],
            'Client' => ['Versions' => ['Allowed' => ['1.0']]],
        ]];
        $path = '/redfish/v1/Managers/1/SecurityPolicy';

        $this->assertSame(0, $this->fetch(self::controller($manager, [$path => $policy(['1.2', '1.3'])]))['weak_tls']);
        $this->assertSame(1, $this->fetch(self::controller($manager, [$path => $policy(['1.1', '1.2'])]))['weak_tls']);
    }

    /** Lenovo, Supermicro et autres : réglages trouvés dans la rubrique Oem du contrôleur. */
    public function testGenericOemSettings(): void
    {
        $lenovo = ['Oem' => ['Lenovo' => [
            'Security' => ['@odata.id' => '/redfish/v1/Managers/1/Oem/Lenovo/Security'],
        ]]];
        $snapshot = $this->fetch(self::controller(
            $lenovo,
            ['/redfish/v1/Managers/1/Oem/Lenovo/Security' => ['Configurator' => ['MinimumTLSVersion' => 'TLS1.2']]]
        ));
        $this->assertSame(0, $snapshot['weak_tls']);

        $supermicro = self::controller([], [], ['Oem' => ['Supermicro' => ['TLSProtocols' => ['TLSv1.1', 'TLSv1.2']]]]);
        $this->assertSame(1, $this->fetch($supermicro)['weak_tls']);
    }

    /** Rien de lisible : inconnu (l'import CSV reste la seule source) ; lien vers un autre hôte ignoré. */
    public function testUnknownWhenNothingIsReadable(): void
    {
        $this->assertSame(Rules::UNKNOWN, $this->fetch(self::controller([]))['weak_tls']);

        $manager = ['Oem' => ['Hpe' => ['Links' => [
            'SecurityService' => ['@odata.id' => 'https://piege.example.test/redfish/v1/SecurityService'],
        ]]]];
        $this->calls = [];
        $this->assertSame(Rules::UNKNOWN, $this->fetch(self::controller($manager))['weak_tls']);
        foreach ($this->calls as $url) {
            $this->assertStringStartsWith(self::BASE . '/redfish/v1', $url);
        }

        // Ressource liée illisible : inconnu, la lecture reste complète.
        $hpe = ['Oem' => ['Hpe' => ['Links' => [
            'SecurityService' => ['@odata.id' => '/redfish/v1/Managers/1/SecurityService'],
        ]]]];
        $this->assertSame(
            Rules::UNKNOWN,
            $this->fetch(self::controller($hpe, ['/redfish/v1/Managers/1/SecurityService' => 403]))['weak_tls']
        );
    }

    public function testParsingHelpers(): void
    {
        $this->assertSame('tls10', RedfishTlsReader::toggleOf('tls10'));
        $this->assertSame('tls11', RedfishTlsReader::toggleOf('tlsv11'));
        $this->assertSame('ssl3', RedfishTlsReader::toggleOf('sslv3enabled'));
        $this->assertNull(RedfishTlsReader::toggleOf('tlsprotocol'));
        $this->assertTrue(RedfishTlsReader::isTlsSetting('tlsprotocol'));
        $this->assertTrue(RedfishTlsReader::isTlsSetting('minimumtlsversion'));
        $this->assertFalse(RedfishTlsReader::isTlsSetting('firmwareversion'));
        $this->assertSame(0, RedfishTlsReader::lowestMinor('TLS 1.0 and Higher'));
        $this->assertSame(2, RedfishTlsReader::lowestMinor('TLS 1.2 Only'));
        $this->assertSame(0, RedfishTlsReader::lowestMinor('SSLv3, TLSv1.2'));
        $this->assertNull(RedfishTlsReader::lowestMinor('1.2'));
        $this->assertSame(2, RedfishTlsReader::lowestMinor('1.2', true));
        $this->assertNull(RedfishTlsReader::lowestMinor('Enabled'));

        // TLS 1.0 et 1.1 tous deux désactivés : TLS 1.2 et plus seulement ; un seul : inconnu.
        $both = new TlsEvidence();
        RedfishTlsReader::scan(['TLS1_0' => false, 'TLS1_1' => 'Disabled'], $both);
        $this->assertSame(0, $both->result());
        $one = new TlsEvidence();
        RedfishTlsReader::scan(['TLS1_0' => 'Disabled'], $one);
        $this->assertSame(Rules::UNKNOWN, $one->result());
        // Un réglage d'une autre nature (version du firmware) n'est pas lu comme TLS.
        $other = new TlsEvidence();
        RedfishTlsReader::scan(['FirmwareVersion' => '1.10', 'MinVersion' => '1.0'], $other);
        $this->assertSame(Rules::UNKNOWN, $other->result());
    }
}
