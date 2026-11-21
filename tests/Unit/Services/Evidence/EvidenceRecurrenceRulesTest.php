<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Evidence;

use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceRecurrenceRules as Rules;
use PHPUnit\Framework\TestCase;

final class EvidenceRecurrenceRulesTest extends TestCase
{
    public function testRollingRhythmsStartFromTheLastCollection(): void
    {
        $today = '2026-03-01';
        self::assertSame('2026-01-11', Rules::nextDue('2026-01-01', Rules::UNIT_DAY, 10, $today));
        self::assertSame('2026-01-15', Rules::nextDue('2026-01-01', Rules::UNIT_WEEK, 2, $today));
        self::assertSame('2026-04-01', Rules::nextDue('2026-01-01', Rules::UNIT_MONTH, 3, $today));
        self::assertSame('2027-01-01', Rules::nextDue('2026-01-01', Rules::UNIT_YEAR, 1, $today));
        self::assertSame('2027-01-29', Rules::nextDue('2026-12-25', Rules::UNIT_WEEK, 5, $today), 'Change d\'année.');
    }

    public function testMonthsStayInTheTargetMonth(): void
    {
        self::assertSame('2026-02-28', Rules::addMonths('2026-01-31', 1));
        self::assertSame('2028-02-29', Rules::addMonths('2028-01-31', 1), 'Année bissextile.');
        self::assertSame('2026-04-30', Rules::addMonths('2026-03-31', 1));
        self::assertSame('2027-01-31', Rules::addMonths('2026-12-31', 1));
        self::assertSame('2025-12-15', Rules::addMonths('2026-01-15', -1));
        self::assertSame('2028-02-29', Rules::nextDue('2024-02-29', Rules::UNIT_YEAR, 4, '2026-01-01'));
        self::assertSame('2027-02-28', Rules::nextDue('2024-02-29', Rules::UNIT_YEAR, 3, '2026-01-01'));
    }

    public function testNeverCollectedIsDueNowForRollingRhythms(): void
    {
        self::assertSame('2026-05-12', Rules::nextDue(null, Rules::UNIT_WEEK, 1, '2026-05-12'));
        self::assertSame('2026-05-12', Rules::nextDue('not a date', Rules::UNIT_MONTH, 1, '2026-05-12'));
        self::assertNull(Rules::nextDue('2026-05-01', '', 1, '2026-05-12'), 'Sans rythme.');
        self::assertNull(Rules::nextDue('2026-05-01', 'fortnight', 1, '2026-05-12'), 'Rythme inconnu.');
    }

    public function testLastBusinessDaySkipsWeekendsAndHolidays(): void
    {
        // Mai 2026 se termine un dimanche : le dernier jour ouvré est le vendredi 29.
        self::assertSame('2026-05-29', Rules::lastBusinessDay(2026, 5));
        // Février 2026 se termine un samedi.
        self::assertSame('2026-02-27', Rules::lastBusinessDay(2026, 2));
        self::assertSame('2026-06-30', Rules::lastBusinessDay(2026, 6), 'Mardi.');
        $closed = [['begin' => '2026-06-29', 'end' => '2026-06-30', 'perpetual' => false]];
        self::assertSame('2026-06-26', Rules::lastBusinessDay(2026, 6, $closed), 'Fermeture de fin de mois.');
        // 31 décembre 2026 (jeudi) fermé tous les ans.
        $newYearEve = [['begin' => '2020-12-31', 'end' => '2020-12-31', 'perpetual' => true]];
        self::assertSame('2026-12-30', Rules::lastBusinessDay(2026, 12, $newYearEve));
    }

    public function testPerpetualHolidayAcrossTheNewYear(): void
    {
        $winter = ['begin' => '2019-12-24', 'end' => '2020-01-02', 'perpetual' => true];
        self::assertTrue(Rules::inHoliday('2026-12-28', $winter));
        self::assertTrue(Rules::inHoliday('2027-01-01', $winter));
        self::assertFalse(Rules::inHoliday('2027-01-05', $winter));
        self::assertFalse(Rules::inHoliday('2026-12-28', ['begin' => '', 'end' => '', 'perpetual' => true]));
    }

    public function testCalendarRhythmsExpectTheNextPeriod(): void
    {
        // Collectée en mars : celle d'avril est attendue le dernier jour ouvré d'avril (jeudi 30).
        self::assertSame('2026-04-30', Rules::nextDue('2026-03-10', Rules::UNIT_MONTH_END, 1, '2026-03-15'));
        // Collectée le 31 janvier : celle de février, qui finit un samedi.
        self::assertSame('2026-02-27', Rules::nextDue('2026-01-31', Rules::UNIT_MONTH_END, 1, '2026-02-01'));
        // Tous les deux mois, collectée en novembre : fin janvier de l'année suivante.
        self::assertSame('2027-01-29', Rules::nextDue('2026-11-03', Rules::UNIT_MONTH_END, 2, '2026-11-03'));
        // Jamais collectée : la période en cours.
        self::assertSame('2026-05-29', Rules::nextDue(null, Rules::UNIT_MONTH_END, 1, '2026-05-04'));
        // Trimestre : collectée en février (T1), celle du T2 est attendue fin juin.
        self::assertSame('2026-06-30', Rules::nextDue('2026-02-10', Rules::UNIT_QUARTER_END, 1, '2026-02-10'));
        // Collectée en novembre (T4) : T1 de l'année suivante, le 31 mars 2027 est un mercredi.
        self::assertSame('2027-03-31', Rules::nextDue('2026-11-20', Rules::UNIT_QUARTER_END, 1, '2026-11-20'));
        self::assertSame('2026-09-30', Rules::nextDue(null, Rules::UNIT_QUARTER_END, 1, '2026-08-01'));
    }

    public function testStatusAroundTheDueDate(): void
    {
        $status = static fn (string $today): string => Rules::status('2026-04-30', Rules::UNIT_MONTH_END, 1, $today);
        self::assertSame(Rules::STATUS_FRESH, $status('2026-04-22'));
        self::assertSame(Rules::STATUS_DUE_SOON, $status('2026-04-23'), 'Sept jours avant.');
        self::assertSame(Rules::STATUS_DUE_SOON, $status('2026-04-30'), 'Le jour même, encore à l\'heure.');
        self::assertSame(Rules::STATUS_STALE, $status('2026-05-01'));
        self::assertSame(Rules::STATUS_NONE, Rules::status(null, Rules::UNIT_WEEK, 1, '2026-05-01'));
    }

    public function testWarningWindowFollowsTheRhythm(): void
    {
        self::assertSame(1, Rules::warningDays(Rules::UNIT_DAY, 1));
        self::assertSame(1, Rules::warningDays(Rules::UNIT_WEEK, 1));
        self::assertSame(3, Rules::warningDays(Rules::UNIT_WEEK, 2));
        self::assertSame(7, Rules::warningDays(Rules::UNIT_MONTH, 1));
        self::assertSame(7, Rules::warningDays(Rules::UNIT_YEAR, 1));
    }

    public function testOneReminderPerDueDate(): void
    {
        self::assertTrue(Rules::reminderDue(Rules::STATUS_DUE_SOON, '2026-04-30', null));
        self::assertTrue(Rules::reminderDue(Rules::STATUS_STALE, '2026-04-30', null));
        self::assertFalse(Rules::reminderDue(Rules::STATUS_STALE, '2026-04-30', '2026-04-30'), 'Déjà prévenu.');
        self::assertTrue(Rules::reminderDue(Rules::STATUS_DUE_SOON, '2026-05-29', '2026-04-30'), 'Échéance suivante.');
        self::assertFalse(Rules::reminderDue(Rules::STATUS_FRESH, '2026-05-29', null));
    }

    public function testCountIsBounded(): void
    {
        self::assertSame(1, Rules::normalizeCount(0));
        self::assertSame(Rules::MAX_COUNT, Rules::normalizeCount(100000));
        self::assertFalse(Rules::isDate('2026-02-30'));
    }
}
