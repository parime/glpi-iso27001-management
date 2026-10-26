<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Network;

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;
use PHPUnit\Framework\TestCase;

final class NetworkFlowRulesTest extends TestCase
{
    public function testPortsAreNormalizedOrRejected(): void
    {
        self::assertSame('any', NetworkFlowRules::normalizePorts(''));
        self::assertSame('any', NetworkFlowRules::normalizePorts('Any'));
        self::assertSame('443', NetworkFlowRules::normalizePorts(' 443 '));
        self::assertSame('80,443,8000-8100', NetworkFlowRules::normalizePorts('80; 443, 8000-8100 80'));
        self::assertNull(NetworkFlowRules::normalizePorts('https'));
        self::assertNull(NetworkFlowRules::normalizePorts('70000'));
        self::assertNull(NetworkFlowRules::normalizePorts('2000-1000'));
    }

    public function testProtocolAndTypeFallBackToDefaults(): void
    {
        self::assertSame('tcp_udp', NetworkFlowRules::normalizeProtocol('TCP/UDP'));
        self::assertSame('any', NetworkFlowRules::normalizeProtocol('*'));
        self::assertSame('tcp', NetworkFlowRules::normalizeProtocol('sctp'));
        self::assertSame('backup', NetworkFlowRules::normalizeType('BACKUP'));
        self::assertSame('internal', NetworkFlowRules::normalizeType('inconnu'));
    }

    public function testPortRangesAreMatched(): void
    {
        self::assertTrue(NetworkFlowRules::portsInclude('any', 22));
        self::assertTrue(NetworkFlowRules::portsInclude('80,3000-3500', 3389));
        self::assertFalse(NetworkFlowRules::portsInclude('80,443', 22));
        self::assertSame([22, 3389], NetworkFlowRules::exposedAdminPorts('22,443,3389'));
    }

    public function testChecksOnAWellDocumentedFlow(): void
    {
        $flow = ['flow_type' => 'internet_publication', 'ports' => '443', 'justification' => 'Site public',
            'users_id' => 5, 'date_end' => null, 'is_active' => 1];
        self::assertSame([], NetworkFlowRules::checks($flow, '2026-10-24'));
    }

    public function testChecksFlagMissingInformationAndDates(): void
    {
        $flow = ['flow_type' => 'internal', 'ports' => '445', 'justification' => '<p> </p>', 'users_id' => 0,
            'date_end' => '2026-10-01'];
        self::assertSame(
            [
                NetworkFlowRules::CHECK_NO_JUSTIFICATION,
                NetworkFlowRules::CHECK_NO_OWNER,
                NetworkFlowRules::CHECK_EXPIRED,
            ],
            NetworkFlowRules::checks($flow, '2026-10-24')
        );
        $flow = ['justification' => 'ok', 'users_id' => 1, 'date_end' => '2026-11-20'];
        self::assertSame([NetworkFlowRules::CHECK_EXPIRING], NetworkFlowRules::checks($flow, '2026-10-24'));
        self::assertSame([], NetworkFlowRules::checks(['is_active' => 0], '2026-10-24'), 'Flux inactif.');
    }

    public function testInternetPublicationOfAdminServices(): void
    {
        $base = ['flow_type' => 'internet_publication', 'justification' => 'x', 'users_id' => 1];
        self::assertSame(
            [NetworkFlowRules::CHECK_ADMIN_EXPOSED],
            NetworkFlowRules::checks($base + ['ports' => '443,3389'], '2026-10-24')
        );
        self::assertSame(
            [NetworkFlowRules::CHECK_ANY_PORT_INTERNET],
            NetworkFlowRules::checks($base + ['ports' => 'any'], '2026-10-24')
        );
        self::assertSame(
            [],
            NetworkFlowRules::checks(['flow_type' => 'admin_access', 'ports' => '22'] + $base, '2026-10-24'),
            'Accès d\'administration interne : pas d\'alerte d\'exposition.'
        );
    }

    public function testPartnerTunnelOverlappingAnInternalNetworkIsBlocking(): void
    {
        $internal = ['10.10.0.0/16', '192.168.1.0/24'];
        $flow = [
            'flow_type'         => 'partner_tunnel',
            'justification'     => 'Échanges EDI',
            'users_id'          => 2,
            'source_label'      => 'Site principal',
            'destination_label' => 'Partenaire logistique 10.10.20.0/24',
        ];
        self::assertSame(['10.10.0.0/16'], NetworkFlowRules::partnerOverlaps($flow, $internal));
        self::assertContains(
            NetworkFlowRules::CHECK_PARTNER_OVERLAP,
            NetworkFlowRules::checks($flow, '2026-10-26', $internal)
        );
        self::assertTrue(NetworkFlowRules::isBlocking(NetworkFlowRules::CHECK_PARTNER_OVERLAP));

        $flow['destination_label'] = 'Partenaire 172.16.5.0/24';
        self::assertSame([], NetworkFlowRules::partnerOverlaps($flow, $internal), 'Pas de chevauchement.');

        $flow['destination_label'] = 'Serveur partenaire 192.168.1.40';
        self::assertSame(['192.168.1.0/24'], NetworkFlowRules::partnerOverlaps($flow, $internal), 'Adresse seule.');

        $flow['flow_type'] = 'internal';
        self::assertSame([], NetworkFlowRules::partnerOverlaps($flow, $internal), 'Seuls les tunnels partenaires.');
        $checks = NetworkFlowRules::checks($flow, '2026-10-26', []);
        self::assertNotContains(NetworkFlowRules::CHECK_PARTNER_OVERLAP, $checks);
    }

    public function testExtractNetworksIgnoresInvalidAddresses(): void
    {
        self::assertSame(
            [[ip2long('10.0.0.0'), 8]],
            NetworkFlowRules::extractNetworks('LAN 10.1.2.3/8 et 999.1.1.1')
        );
        self::assertTrue(NetworkFlowRules::networksOverlap([ip2long('10.0.0.0'), 8], [ip2long('10.200.0.0'), 16]));
        self::assertFalse(NetworkFlowRules::networksOverlap([ip2long('10.0.0.0'), 16], [ip2long('10.1.0.0'), 16]));
    }
}
