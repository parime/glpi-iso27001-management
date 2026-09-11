<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\LegacySecurityIncidentMigrator;
use PHPUnit\Framework\TestCase;

final class LegacySecurityIncidentMigratorTest extends TestCase
{
    /**
     * @dataProvider statusMapProvider
     */
    public function testMapStatusConvertsEachLegacyValue(string $legacy, int $expected): void
    {
        self::assertSame($expected, LegacySecurityIncidentMigrator::mapStatus($legacy));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function statusMapProvider(): array
    {
        return [
            'open -> INCOMING (1)'          => ['open', 1],
            'investigating -> ASSIGNED (2)' => ['investigating', 2],
            'contained -> WAITING (4)'      => ['contained', 4],
            'closed -> CLOSED (6)'          => ['closed', 6],
        ];
    }

    public function testMapStatusFallsBackToIncomingForAnUnknownValue(): void
    {
        self::assertSame(1, LegacySecurityIncidentMigrator::mapStatus('bogus'));
    }

    public function testMapStatusFallsBackToIncomingForNull(): void
    {
        self::assertSame(1, LegacySecurityIncidentMigrator::mapStatus(null));
    }

    public function testMapRowCopiesEveryIsoFieldAsIs(): void
    {
        $mapped = LegacySecurityIncidentMigrator::mapRow([
            'title'                      => 'Incident test - acces non autorise VPN',
            'description'                => 'Tentative de connexion VPN suspecte.',
            'incident_date'              => null,
            'status'                     => 'investigating',
            'category'                   => 'malware',
            'severity'                   => 'major',
            'cia_impact'                 => 'confidentiality',
            'root_cause'                 => '',
            'lessons_learned'            => '',
            'users_id'                   => 0,
            'linked_itemtype'            => '',
            'linked_items_id'            => 0,
            'plugin_grcmanager_risks_id' => 8,
            'date_creation'              => '2026-09-03 15:20:01',
        ]);

        self::assertSame('Incident test - acces non autorise VPN', $mapped['name']);
        self::assertSame('Tentative de connexion VPN suspecte.', $mapped['content']);
        self::assertNull($mapped['date']);
        self::assertSame(2, $mapped['status']);
        self::assertSame('malware', $mapped['category']);
        self::assertSame('major', $mapped['severity']);
        self::assertSame('confidentiality', $mapped['cia_impact']);
        self::assertSame(8, $mapped['plugin_grcmanager_risks_id']);
        self::assertSame(0, $mapped['entities_id']);
        self::assertSame('2026-09-03 15:20:01', $mapped['date_creation']);
    }

    public function testMapRowKeepsAnIncidentDateWhenSet(): void
    {
        $mapped = LegacySecurityIncidentMigrator::mapRow([
            'title'         => 'x',
            'incident_date' => '2026-01-15 10:00:00',
            'status'        => 'open',
        ]);

        self::assertSame('2026-01-15 10:00:00', $mapped['date']);
    }

    public function testHasResponsibleUserIsTrueOnlyForAPositiveId(): void
    {
        self::assertTrue(LegacySecurityIncidentMigrator::hasResponsibleUser(['users_id' => 5]));
        self::assertFalse(LegacySecurityIncidentMigrator::hasResponsibleUser(['users_id' => 0]));
        self::assertFalse(LegacySecurityIncidentMigrator::hasResponsibleUser([]));
    }

    public function testHasLinkedItemIsTrueOnlyWhenBothItemtypeAndIdArePresent(): void
    {
        self::assertTrue(LegacySecurityIncidentMigrator::hasLinkedItem([
            'linked_itemtype' => 'Ticket',
            'linked_items_id' => 3,
        ]));
        self::assertFalse(LegacySecurityIncidentMigrator::hasLinkedItem([
            'linked_itemtype' => '',
            'linked_items_id' => 3,
        ]));
        self::assertFalse(LegacySecurityIncidentMigrator::hasLinkedItem([
            'linked_itemtype' => 'Ticket',
            'linked_items_id' => 0,
        ]));
        self::assertFalse(LegacySecurityIncidentMigrator::hasLinkedItem([]));
    }
}
