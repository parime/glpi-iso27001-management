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
}
