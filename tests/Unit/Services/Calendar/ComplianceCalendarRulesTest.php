<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Calendar;

use GlpiPlugin\Grcmanager\Services\Calendar\ComplianceCalendarRules;
use PHPUnit\Framework\TestCase;

final class ComplianceCalendarRulesTest extends TestCase
{
    public function testTypesAndStatusesFallBackToDefaults(): void
    {
        self::assertSame('pentest', ComplianceCalendarRules::normalizeType('pentest'));
        self::assertSame('other', ComplianceCalendarRules::normalizeType('inconnu'));
        self::assertSame('planned', ComplianceCalendarRules::normalizeStatus(''));
        self::assertSame('done', ComplianceCalendarRules::normalizeStatus('done'));
    }

    public function testRangeIsCompletedOrRejected(): void
    {
        self::assertSame(
            ['2026-11-03 09:00:00', '2026-11-03 17:00:00'],
            ComplianceCalendarRules::normalizeRange('2026-11-03 09:00', '2026-11-03 17:00')
        );
        self::assertSame(
            ['2026-11-03 09:00:00', '2026-11-04 00:00:00'],
            ComplianceCalendarRules::normalizeRange('2026-11-03 09:00', '2026-11-02'),
            'Fin antérieure : jusqu\'à la fin de la journée.'
        );
        self::assertNull(ComplianceCalendarRules::normalizeRange('', '2026-11-03'));
        $day = ComplianceCalendarRules::dayRange('2027-01-15');
        self::assertSame(['2027-01-15 00:00:00', '2027-01-16 00:00:00'], $day);
        self::assertNull(ComplianceCalendarRules::dayRange(null));
    }

    public function testOverlapAndSort(): void
    {
        $month = ['2026-11-01', '2026-12-01'];
        self::assertTrue(ComplianceCalendarRules::overlaps('2026-11-03 00:00:00', '2026-11-04 00:00:00', ...$month));
        self::assertFalse(ComplianceCalendarRules::overlaps('2026-12-01 00:00:00', '2026-12-02 00:00:00', ...$month));
        $sorted = ComplianceCalendarRules::sortByDate([['begin' => '2027-02-01'], ['begin' => '2026-11-03']]);
        self::assertSame('2026-11-03', $sorted[0]['begin']);
    }

    public function testIcsExportIsValidAndEscaped(): void
    {
        $ics = ComplianceCalendarRules::toIcs([
            [
                'uid' => 'audit-1@grc', 'name' => 'Audit de certification; étape 2, site A',
                'begin' => '2026-11-03 00:00:00', 'end' => '2026-11-05 00:00:00',
                'description' => "Ligne 1\nLigne 2", 'url' => 'https://glpi.example/a',
            ],
            [
                'uid' => 'pentest-2@grc', 'name' => 'Pentest',
                'begin' => '2026-12-01 09:00:00', 'end' => '2026-12-01 17:00:00',
            ],
        ], 'Calendrier', '2026-10-24 12:00:00');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("DTSTART;VALUE=DATE:20261103\r\nDTEND;VALUE=DATE:20261105", $ics);
        self::assertStringContainsString("DTSTART:20261201T090000\r\nDTEND:20261201T170000", $ics);
        self::assertStringContainsString('SUMMARY:Audit de certification\; étape 2\, site A', $ics);
        self::assertStringContainsString('DESCRIPTION:Ligne 1\nLigne 2', $ics);
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function testNoTextCanInjectAnICalendarLine(): void
    {
        // Revue de sécurité 2.11.0 : \r isolé dans le nom, CR/LF dans l'UID et l'URL.
        $ics = ComplianceCalendarRules::toIcs([[
            'uid' => "x@grc\r\nATTENDEE:mailto:a@b", 'name' => "Audit\rATTENDEE:mailto:c@d",
            'begin' => '2026-11-03 00:00:00', 'end' => '2026-11-04 00:00:00',
            'url' => "https://glpi.example/a\nATTENDEE:mailto:e@f",
        ]], 'Calendrier', '2026-10-24 12:00:00');

        foreach (explode("\r\n", $ics) as $line) {
            self::assertStringStartsNotWith('ATTENDEE', $line);
        }
        self::assertStringNotContainsString("\r\r", $ics);
        self::assertSame(1, substr_count($ics, 'SUMMARY:'));
    }

    public function testLongLinesAreFoldedWithoutBreakingCharacters(): void
    {
        $ics = ComplianceCalendarRules::toIcs([
            ['uid' => 'x', 'name' => str_repeat('é', 60), 'begin' => '2026-11-03 00:00:00',
                'end' => '2026-11-04 00:00:00'],
        ], 'C', '2026-10-24');
        self::assertMatchesRegularExpression("/SUMMARY:é+\r\n é+/u", $ics);
        $summary = preg_replace(['/^.*SUMMARY:/s', "/\r\n /", "/\r\n.*$/s"], ['', '', ''], $ics);
        self::assertSame(str_repeat('é', 60), $summary);
    }

    public function testReminderDueFifteenDaysBeforeAPlannedEventOnlyOnce(): void
    {
        $today = '2026-10-25';
        self::assertTrue(ComplianceCalendarRules::reminderDue('planned', '2026-11-09 09:00:00', 4, null, $today));
        self::assertTrue(ComplianceCalendarRules::reminderDue('planned', '2026-10-25 14:00:00', 4, '', $today));
        self::assertFalse(
            ComplianceCalendarRules::reminderDue('planned', '2026-11-10 09:00:00', 4, null, $today),
            'Plus de 15 jours avant.'
        );
        self::assertFalse(ComplianceCalendarRules::reminderDue('planned', '2026-10-24 09:00:00', 4, null, $today));
        self::assertFalse(
            ComplianceCalendarRules::reminderDue('planned', '2026-11-01 09:00:00', 4, '2026-10-20 08:00:00', $today),
            'Déjà prévenu.'
        );
        self::assertFalse(ComplianceCalendarRules::reminderDue('done', '2026-11-01 09:00:00', 4, null, $today));
        self::assertFalse(ComplianceCalendarRules::reminderDue('planned', '2026-11-01 09:00:00', 0, null, $today));
        self::assertFalse(ComplianceCalendarRules::reminderDue('planned', null, 4, null, $today));
    }
}
