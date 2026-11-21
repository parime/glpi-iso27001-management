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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #843 : règles des contrôleurs de gestion des serveurs (sans GLPI ni réseau).
 */
final class BmcRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'nom en https' => ['https://ilo-srv01.example.test', false, 'https://ilo-srv01.example.test'];
        yield 'sans schéma' => ['idrac-srv02.example.test', false, 'https://idrac-srv02.example.test'];
        yield 'port' => ['https://bmc.example.test:8443/', false, 'https://bmc.example.test:8443'];
        yield 'port 443 retiré' => ['https://bmc.example.test:443', false, 'https://bmc.example.test'];
        yield 'chemin Redfish toléré' => ['https://bmc.example.test/redfish/v1/', false, 'https://bmc.example.test'];
        yield 'http refusé' => ['http://bmc.example.test', true, null];
        yield 'identifiants refusés' => ['https://root:calvin@bmc.example.test', true, null];
        yield 'paramètres refusés' => ['https://bmc.example.test/?a=1', true, null];
        yield 'autre chemin refusé' => ['https://bmc.example.test/admin', true, null];
        yield 'adresse interne refusée par défaut' => ['https://10.0.99.12', false, null];
        yield 'adresse interne autorisée' => ['https://10.0.99.12', true, 'https://10.0.99.12'];
        yield 'vide' => ['', true, null];
        yield 'autre schéma' => ['ftp://bmc.example.test', true, null];
    }

    #[DataProvider('urls')]
    public function testNormalizeUrl(string $url, bool $allowPrivate, ?string $expected): void
    {
        $this->assertSame($expected, Rules::normalizeUrl($url, $allowPrivate));
    }

    public function testSafePathOnlyAcceptsRedfishPathsOfTheSameHost(): void
    {
        $this->assertSame('/redfish/v1/Managers/1', Rules::safePath('/redfish/v1/Managers/1'));
        $this->assertSame('/redfish/v1/', Rules::safePath('/redfish/v1/'));
        $idrac = '/redfish/v1/Managers/iDRAC.Embedded.1';
        $this->assertSame($idrac, Rules::safePath($idrac));
        foreach (
            [
                'https://autre.example.test/redfish/v1/Managers', '//autre.example.test/redfish/v1',
                '/redfish/v1/../../etc',
                '/redfish/v1/Managers?$expand=*', '/redfish/v1/Managers#x', '/api/v1', '/redfish/v1/%2e%2e/x', 12, null,
                '/redfish/v1//Managers',
            ] as $refused
        ) {
            $this->assertNull(Rules::safePath($refused), (string) json_encode($refused));
        }
    }

    public function testDefaultAccountsAndAdminRoles(): void
    {
        foreach (['root', 'Administrator', 'ADMIN', 'USERID', 'admin'] as $name) {
            $this->assertTrue(Rules::isDefaultAccount($name), $name);
        }
        $this->assertFalse(Rules::isDefaultAccount('audit-grc'));
        $this->assertTrue(Rules::isAdminRole('Administrator'));
        $this->assertFalse(Rules::isAdminRole('ReadOnly'));
        $this->assertFalse(Rules::isAdminRole('Operator'));
    }

    public function testVendorFromRedfishRoot(): void
    {
        $this->assertSame('Dell', Rules::vendorOf(['Vendor' => 'Dell']));
        $this->assertSame('HPE', Rules::vendorOf(['Oem' => ['Hpe' => []]]));
        $this->assertSame('Lenovo', Rules::vendorOf(['Vendor' => 'Lenovo']));
        $this->assertSame('Contoso', Rules::vendorOf(['Vendor' => 'Contoso']));
        $this->assertSame('', Rules::vendorOf([]));
    }

    public function testHealthAndTristate(): void
    {
        $this->assertSame('Warning', Rules::normalizeHealth('warning'));
        $this->assertSame('', Rules::normalizeHealth(['x']));
        $this->assertSame('Critical', Rules::worstHealth('Warning', 'Critical'));
        $this->assertSame('Warning', Rules::worstHealth('Warning', 'OK'));
        $this->assertSame(1, Rules::tristate(true));
        $this->assertSame(0, Rules::tristate('non'));
        $this->assertSame(1, Rules::tristate('Oui'));
        $this->assertSame(Rules::UNKNOWN, Rules::tristate(''));
        $this->assertSame(Rules::UNKNOWN, Rules::tristate(null));
    }

    public function testSelfSignedCertificate(): void
    {
        $name = ['CommonName' => 'ilo-srv01', 'Organization' => 'Exemple'];
        $this->assertTrue(Rules::isSelfSigned(['Issuer' => $name, 'Subject' => $name]));
        $this->assertFalse(Rules::isSelfSigned(['Issuer' => ['CommonName' => 'AC Exemple'], 'Subject' => $name]));
        $this->assertFalse(Rules::isSelfSigned([]));
        $this->assertSame('2027-03-01', Rules::dateOf('2027-03-01T10:00:00Z'));
        $this->assertNull(Rules::dateOf('pas une date'));
    }

    public function testAdminNetworksFromTheFlowMatrix(): void
    {
        $networks = Rules::adminNetworks([
            ['source_label' => 'Bastion 192.0.2.10', 'destination_label' => 'MGMT 10.0.99.0/24'],
            ['source_label' => 'Postes 10.1.0.0/16', 'destination_label' => 'Serveurs 10.2.0.0/16'],
            ['source_label' => 'ADMINISTRATIF 10.3.0.0/16', 'destination_label' => 'Internet'],
        ]);
        $this->assertCount(2, $networks);
        $this->assertTrue(Rules::inAdminNetwork('10.0.99.12', $networks));
        $this->assertTrue(Rules::inAdminNetwork('192.0.2.10', $networks));
        $this->assertFalse(Rules::inAdminNetwork('10.2.0.5', $networks));
        $this->assertNull(Rules::inAdminNetwork('', $networks));
        $this->assertNull(Rules::inAdminNetwork('10.2.0.5', []));
    }

    public function testGapsAreReportedInOrder(): void
    {
        $controller = [
            'date_read' => '2026-01-10 10:00:00', 'read_error' => '', 'source' => Rules::SOURCE_REDFISH,
            'ipmi_lan' => 1, 'telnet' => 0, 'snmp_v1v2' => 1, 'weak_tls' => Rules::UNKNOWN, 'secure_boot' => 0,
            'tls_verified' => 0, 'cert_self_signed' => 1, 'cert_expiry' => '2026-01-20', 'health' => 'Warning',
        ];
        $accounts = [['username' => 'root', 'enabled' => 1], ['username' => 'audit', 'enabled' => 1]];
        $gaps = Rules::gaps($controller, $accounts, false, false, '2026-01-10');
        $this->assertSame([
            Rules::GAP_IPMI_LAN, Rules::GAP_DEFAULT_ACCOUNT, Rules::GAP_OUTSIDE_ADMIN_NETWORK, Rules::GAP_SNMP_V1V2,
            Rules::GAP_SECURE_BOOT_OFF, Rules::GAP_CERT_SELF_SIGNED, Rules::GAP_TLS_NOT_VERIFIED,
            Rules::GAP_HEALTH_WARNING, Rules::GAP_CERT_EXPIRING, Rules::GAP_NO_SERVER,
        ], $gaps);
        $this->assertTrue(Rules::hasHighGap($gaps));

        $clean = [
            'date_read' => '2026-01-10 10:00:00', 'read_error' => '', 'source' => Rules::SOURCE_REDFISH,
            'ipmi_lan' => 0, 'telnet' => 0, 'snmp_v1v2' => 0, 'weak_tls' => Rules::UNKNOWN, 'secure_boot' => 1,
            'tls_verified' => 1, 'cert_self_signed' => 0, 'cert_expiry' => '2027-06-01', 'health' => 'OK',
        ];
        $disabledRoot = [['username' => 'root', 'enabled' => 0]];
        $this->assertSame([], Rules::gaps($clean, $disabledRoot, true, true, '2026-01-10'));
        $expired = Rules::gaps(['cert_expiry' => '2026-01-01'] + $clean, [], null, true, '2026-01-10');
        $this->assertSame([Rules::GAP_CERT_EXPIRED], $expired);
        $neverRead = Rules::gaps(['source' => Rules::SOURCE_REDFISH], [], null, false, '2026-01-10');
        $this->assertSame([Rules::GAP_NOT_READ], $neverRead);
        $failed = Rules::gaps(['read_error' => 'unauthorized'] + $clean, [], null, true, '2026-01-10');
        $this->assertSame([Rules::GAP_READ_FAILED], $failed);
        $this->assertFalse(Rules::hasHighGap($failed));
    }

    public function testCsvImport(): void
    {
        $csv = "name;address;vendor;model;firmware;serial;ipmi_lan;weak_tls;accounts\n"
            . "bmc-srv03;192.0.2.30;Supermicro;X11;3.10;SM123456;oui;yes;ADMIN:Administrator;audit:ReadOnly:disabled\n"
            . ";192.0.2.31;;;;;;;\n"
            . "bmc-srv04;bmc-srv04.example.test;;;;;non;;\n";
        $parsed = Rules::parseCsv($csv);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $first = $parsed['rows'][0];
        $this->assertSame('bmc-srv03', $first['name']);
        $this->assertSame('192.0.2.30', $first['ip_address']);
        $this->assertSame(1, $first['ipmi_lan']);
        $this->assertSame(1, $first['weak_tls']);
        $this->assertSame(Rules::UNKNOWN, $first['telnet']);
        $this->assertSame('SM123456', $first['serial']);
        // Le « ; » des comptes est aussi le séparateur du fichier : les comptes en fin de ligne restent lus.
        $this->assertCount(2, $first['accounts']);
        $this->assertSame('ADMIN', $first['accounts'][0]['username']);
        $this->assertSame(0, $first['accounts'][1]['enabled']);
        $this->assertSame('', $parsed['rows'][1]['ip_address']);
        $this->assertSame(0, $parsed['rows'][1]['ipmi_lan']);

        $this->assertSame(['missing_name'], Rules::parseCsv("address;model\n192.0.2.1;x\n")['errors']);
        $this->assertSame(['empty'], Rules::parseCsv('  ')['errors']);
    }

    public function testCsvAccountsCell(): void
    {
        $accounts = Rules::parseAccounts('root:Administrator; audit:ReadOnly:disabled|ops:Operator');
        $this->assertCount(3, $accounts);
        $this->assertSame(['username' => 'audit', 'role' => 'ReadOnly', 'enabled' => 0, 'locked' => 0], $accounts[1]);
        $this->assertSame(1, $accounts[2]['enabled']);
    }
}
