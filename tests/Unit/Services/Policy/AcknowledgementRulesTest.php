<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Policy\AcknowledgementRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #535 : public cible, échéance, taux, retardataires et nouvelle version d'une campagne de
 * prise de connaissance d'une politique.
 */
final class AcknowledgementRulesTest extends TestCase
{
    public function testAudienceIsTheUnionOfGroupsProfilesAndEntities(): void
    {
        $users = [
            10 => [
                'default_entity' => 1, 'groups' => [5],
                'authorizations' => [['profiles_id' => 1, 'entities_id' => 1]],
            ],
            11 => [
                'default_entity' => 0, 'groups' => [],
                'authorizations' => [['profiles_id' => 7, 'entities_id' => 2]],
            ],
            12 => ['default_entity' => 3, 'groups' => [], 'authorizations' => [
                ['profiles_id' => 1, 'entities_id' => 4], ['profiles_id' => 1, 'entities_id' => 3],
            ]],
            13 => [
                'default_entity' => 0, 'groups' => [6],
                'authorizations' => [['profiles_id' => 1, 'entities_id' => 9]],
            ],
        ];
        $members = AcknowledgementRules::members(['groups' => [5], 'profiles' => [7], 'entities' => [3, 4]], $users);

        self::assertSame([10 => 1, 11 => 2, 12 => 3], $members, 'groupe, profil, entité ; 13 hors du public');
        self::assertSame([], AcknowledgementRules::members([], $users), 'aucun public défini');
    }

    public function testCountingEntityPrefersTheMatchedEntityThenTheDefaultOne(): void
    {
        self::assertSame(4, AcknowledgementRules::countingEntity(1, [1, 4], [4]), 'entité du public');
        self::assertSame(3, AcknowledgementRules::countingEntity(3, [2, 3], []), 'entité par défaut habilitée');
        self::assertSame(2, AcknowledgementRules::countingEntity(9, [2, 3], []), 'première habilitation');
        self::assertSame(0, AcknowledgementRules::countingEntity(null, [], []));
    }

    public function testDeadlineIsCountedFromTheLaunchAndBounded(): void
    {
        self::assertSame('2026-12-14', AcknowledgementRules::dueDate('2026-11-14 10:00:00', 30));
        self::assertSame(30, AcknowledgementRules::sanitizeDays(''));
        self::assertSame(30, AcknowledgementRules::sanitizeDays(-4));
        self::assertSame(365, AcknowledgementRules::sanitizeDays(1000));
        self::assertSame(15, AcknowledgementRules::sanitizeDays('15'));
    }

    public function testLateOnlyAfterTheDueDateWithoutConfirmation(): void
    {
        self::assertFalse(AcknowledgementRules::isLate(null, '2026-11-10', '2026-11-10'), 'échéance incluse');
        self::assertTrue(AcknowledgementRules::isLate(null, '2026-11-10', '2026-11-11'));
        self::assertFalse(AcknowledgementRules::isLate('2026-11-12 09:00:00', '2026-11-10', '2026-11-20'));
    }

    public function testRateProgressAndLatecomers(): void
    {
        $lines = [
            ['users_id' => 1, 'entities_id' => 1, 'date_acknowledged' => '2026-11-02 10:00:00'],
            ['users_id' => 2, 'entities_id' => 1, 'date_acknowledged' => null],
            ['users_id' => 3, 'entities_id' => 2, 'date_acknowledged' => '2026-11-03 10:00:00'],
            ['users_id' => 4, 'entities_id' => 2, 'date_acknowledged' => '2026-11-04 10:00:00'],
        ];
        $progress = AcknowledgementRules::progress($lines, '2026-11-10', '2026-11-12');
        self::assertSame(
            ['total' => 4, 'done' => 3, 'pending' => 1, 'late' => 1, 'rate' => 75, 'color' => 'orange'],
            $progress
        );
        self::assertSame(
            0,
            AcknowledgementRules::progress($lines, '2026-11-10', '2026-11-05')['late'],
            'avant l\'échéance'
        );

        $byEntity = AcknowledgementRules::progressByEntity($lines, '2026-11-10', '2026-11-12');
        self::assertSame(50, $byEntity[1]['rate']);
        self::assertSame('green', $byEntity[2]['color']);

        $late = AcknowledgementRules::latecomers($lines, '2026-11-10', '2026-11-12');
        self::assertSame([2], array_column($late, 'users_id'));
        self::assertSame([], AcknowledgementRules::latecomers($lines, '2026-11-10', '2026-11-10'));
        self::assertSame(0, AcknowledgementRules::rate(0, 0));
        self::assertSame('red', AcknowledgementRules::rateColor(49));
        self::assertSame('green', AcknowledgementRules::rateColor(90));
    }

    public function testANewVersionRelaunchesOnlyAfterAFirstCampaign(): void
    {
        self::assertTrue(AcknowledgementRules::canLaunch('approved', true, null, '1.0'), 'première campagne');
        self::assertFalse(AcknowledgementRules::canLaunch('approved', true, '1.0', '1.0'), 'même version');
        self::assertFalse(AcknowledgementRules::canLaunch('draft', true, null, '1.0'), 'brouillon');
        self::assertFalse(AcknowledgementRules::canLaunch('approved', false, null, '1.0'), 'sans public');

        self::assertFalse(AcknowledgementRules::shouldRelaunch('approved', true, null, '1.0'), 'première : à la main');
        self::assertTrue(AcknowledgementRules::shouldRelaunch('approved', true, '1.0', '2.0'), 'nouvelle version');
        self::assertFalse(AcknowledgementRules::shouldRelaunch('approved', true, '2.0', '2.0'));
        self::assertFalse(AcknowledgementRules::shouldRelaunch('archived', true, '1.0', '2.0'), 'archivée');
    }

    public function testReminderIsANotificationAtMostWeekly(): void
    {
        self::assertFalse(AcknowledgementRules::isReminderDue(null, '2026-11-10', null, '2026-11-10'));
        self::assertTrue(AcknowledgementRules::isReminderDue(null, '2026-11-10', null, '2026-11-11'));
        self::assertFalse(AcknowledgementRules::isReminderDue(null, '2026-11-10', '2026-11-11 08:00:00', '2026-11-15'));
        self::assertTrue(AcknowledgementRules::isReminderDue(null, '2026-11-10', '2026-11-11 08:00:00', '2026-11-18'));
        self::assertFalse(
            AcknowledgementRules::isReminderDue('2026-11-12', '2026-11-10', null, '2026-11-20'),
            'confirmé'
        );
    }

    public function testCsvCellsCannotInjectFormulas(): void
    {
        self::assertSame("'=SUM(A1)", AcknowledgementRules::csvCell('=SUM(A1)'));
        self::assertSame('Alice Martin', AcknowledgementRules::csvCell('Alice Martin'));
    }
}
