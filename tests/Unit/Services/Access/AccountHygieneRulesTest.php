<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use PHPUnit\Framework\TestCase;

final class AccountHygieneRulesTest extends TestCase
{
    private const NOW = '2026-10-24 12:00:00';

    public function testAHealthyAccountRaisesNothing(): void
    {
        self::assertSame([], AccountHygieneRules::checks([
            'is_deleted_ldap' => 0, 'end_date' => '2027-01-01 00:00:00',
            'last_login' => '2026-10-20 08:00:00', 'date_creation' => '2024-01-01 00:00:00',
        ], self::NOW, 90));
    }

    public function testDirectoryDeletionExpiryAndInactivity(): void
    {
        self::assertSame(
            [AccountHygieneRules::DIRECTORY_DELETED, AccountHygieneRules::EXPIRED, AccountHygieneRules::INACTIVE],
            AccountHygieneRules::checks([
                'is_deleted_ldap' => 1, 'end_date' => '2026-09-30 00:00:00', 'last_login' => '2026-06-01 08:00:00',
            ], self::NOW, 90)
        );
        self::assertSame([], AccountHygieneRules::checks(['last_login' => '2026-06-01 08:00:00'], self::NOW, 180));
    }

    public function testNeverUsedOnlyOnceTheAccountIsOlderThanThePeriod(): void
    {
        self::assertSame(
            [AccountHygieneRules::NEVER_USED],
            AccountHygieneRules::checks(['last_login' => null, 'date_creation' => '2026-01-01 00:00:00'], self::NOW, 90)
        );
        self::assertSame(
            [],
            AccountHygieneRules::checks(['last_login' => null, 'date_creation' => '2026-10-01'], self::NOW, 90),
            'Compte récent, pas encore utilisé.'
        );
    }

    public function testDuplicatesByEmailOrName(): void
    {
        $result = AccountHygieneRules::duplicates([
            ['id' => 1, 'emails' => ['Jean.Dupont@exemple.fr'], 'firstname' => 'Jean', 'realname' => 'Dupont'],
            ['id' => 2, 'emails' => ['jean.dupont@exemple.fr '], 'firstname' => 'J.', 'realname' => 'Dupont'],
            ['id' => 3, 'emails' => [], 'firstname' => 'Jéan', 'realname' => 'DUPONT'],
            ['id' => 4, 'emails' => ['autre@exemple.fr'], 'firstname' => '', 'realname' => 'Dupont'],
        ]);
        self::assertSame([AccountHygieneRules::DUPLICATE_EMAIL, AccountHygieneRules::DUPLICATE_NAME], $result[1]);
        self::assertSame([AccountHygieneRules::DUPLICATE_EMAIL], $result[2]);
        self::assertSame([AccountHygieneRules::DUPLICATE_NAME], $result[3]);
        self::assertArrayNotHasKey(4, $result, 'Sans prénom : jamais un doublon de nom.');
    }

    public function testInactivityPeriodIsAProposedValue(): void
    {
        self::assertSame(180, AccountHygieneRules::normalizeDays('180'));
        self::assertSame(90, AccountHygieneRules::normalizeDays(7));
    }
}
