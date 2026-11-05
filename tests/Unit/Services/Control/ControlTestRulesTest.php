<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #380 : règles des tests d'efficacité des mesures.
 */
final class ControlTestRulesTest extends TestCase
{
    public function testInputsAreNormalized(): void
    {
        self::assertSame('sampling', ControlTestRules::normalizeMethod('sampling'));
        self::assertSame('inspection', ControlTestRules::normalizeMethod('<script>'));
        self::assertSame('', ControlTestRules::normalizePlanMethod('inconnue'));
        self::assertSame('interview', ControlTestRules::normalizePlanMethod('interview'));
        self::assertSame(12, ControlTestRules::normalizeFrequency('12'));
        self::assertSame(0, ControlTestRules::normalizeFrequency(7), 'Fréquence hors liste : aucun plan.');
        self::assertTrue(ControlTestRules::isResult('partial'));
        self::assertFalse(ControlTestRules::isResult(''));
        self::assertSame('2026-02-28', ControlTestRules::normalizeDate('2026-02-28'));
        self::assertNull(ControlTestRules::normalizeDate('2026-02-30'));
        self::assertNull(ControlTestRules::normalizeDate('28/02/2026'));
    }

    public function testNextTestDateUsesTheEnteredDateOrThePlan(): void
    {
        self::assertSame('2027-03-01', ControlTestRules::nextTestDate('2026-11-03', '2027-03-01', 12));
        self::assertSame('2027-11-03', ControlTestRules::nextTestDate('2026-11-03', '', 12));
        self::assertSame('2027-02-03', ControlTestRules::nextTestDate('2026-11-03', null, 3));
        self::assertNull(ControlTestRules::nextTestDate('2026-11-03', null, 0), 'Sans plan ni date saisie.');
        // Fin de mois : jamais de report sur le mois suivant.
        self::assertSame('2027-02-28', ControlTestRules::nextTestDate('2027-01-31', null, 1));
        self::assertSame('2028-02-29', ControlTestRules::nextTestDate('2027-08-31', null, 6));
    }

    public function testDueStatus(): void
    {
        $today = '2026-11-03';
        self::assertSame(ControlTestRules::DUE_NONE, ControlTestRules::dueStatus(null, $today));
        self::assertSame(ControlTestRules::DUE_OVERDUE, ControlTestRules::dueStatus('2026-11-02', $today));
        self::assertSame(ControlTestRules::DUE_SOON, ControlTestRules::dueStatus('2026-11-03', $today));
        self::assertSame(ControlTestRules::DUE_SOON, ControlTestRules::dueStatus('2026-12-03', $today));
        self::assertSame(ControlTestRules::DUE_PLANNED, ControlTestRules::dueStatus('2026-12-04', $today));
    }

    public function testVerifiedStatusFollowsTheLastTest(): void
    {
        self::assertSame('verified', ControlTestRules::statusAfterTest('implemented', 'effective'));
        self::assertNull(ControlTestRules::statusAfterTest('verified', 'effective'));
        self::assertSame('implemented', ControlTestRules::statusAfterTest('verified', 'partial'));
        self::assertSame('implemented', ControlTestRules::statusAfterTest('verified', 'ineffective'));
        self::assertNull(ControlTestRules::statusAfterTest('in_progress', 'effective'), 'Pas encore en place.');
        self::assertNull(ControlTestRules::statusAfterTest('not_started', 'ineffective'));
    }

    public function testVerifiedStatusIsWithdrawnOnlyWhenTheNextTestIsOverdue(): void
    {
        $today = '2026-11-03';
        self::assertSame('implemented', ControlTestRules::statusWhenDue('verified', '2026-11-02', $today));
        self::assertNull(ControlTestRules::statusWhenDue('verified', '2026-11-03', $today), 'Échéance du jour.');
        self::assertNull(ControlTestRules::statusWhenDue('verified', null, $today), 'Sans échéance.');
        self::assertNull(ControlTestRules::statusWhenDue('implemented', '2026-01-01', $today));
        self::assertNull(ControlTestRules::statusWhenDue('in_progress', '2026-01-01', $today));
    }

    public function testOneReminderPerDueDateAndState(): void
    {
        $today = '2026-11-03';
        self::assertNull(ControlTestRules::reminderKey(null, $today));
        self::assertNull(ControlTestRules::reminderKey('2027-01-01', $today), 'Échéance lointaine.');
        self::assertSame('2026-11-20|due_soon', ControlTestRules::reminderKey('2026-11-20', $today));
        self::assertSame('2026-11-02|overdue', ControlTestRules::reminderKey('2026-11-02', $today));
        self::assertNotSame(
            ControlTestRules::reminderKey('2026-11-20', $today),
            ControlTestRules::reminderKey('2026-11-20', '2026-11-21'),
            'Un test en retard est rappelé une seconde fois.'
        );
    }

    public function testCisoGroupFollowsTheSetting(): void
    {
        self::assertSame(ControlTestRules::CISO_OVERDUE, ControlTestRules::normalizeCisoMode('nimporte'));
        self::assertSame(ControlTestRules::CISO_NEVER, ControlTestRules::normalizeCisoMode('never'));
        self::assertTrue(ControlTestRules::notifiesCiso('overdue', ControlTestRules::DUE_OVERDUE));
        self::assertFalse(ControlTestRules::notifiesCiso('overdue', ControlTestRules::DUE_SOON));
        self::assertTrue(ControlTestRules::notifiesCiso('always', ControlTestRules::DUE_SOON));
        self::assertFalse(ControlTestRules::notifiesCiso('always', ControlTestRules::DUE_PLANNED));
        self::assertFalse(ControlTestRules::notifiesCiso('never', ControlTestRules::DUE_OVERDUE));
    }

    public function testBrandColorAcceptsOnlyAHexColor(): void
    {
        self::assertSame('#aa3366', ControlTestRules::brandColor('#AA3366'));
        self::assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestRules::brandColor(''));
        self::assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestRules::brandColor('red'));
        self::assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestRules::brandColor('#123456"><script>'));
        self::assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestRules::brandColor(null));
    }

    public function testOnlyAnIneffectiveControlOpensANonconformity(): void
    {
        self::assertTrue(ControlTestRules::opensNonconformity('ineffective'));
        self::assertFalse(ControlTestRules::opensNonconformity('partial'));
        self::assertFalse(ControlTestRules::opensNonconformity('effective'));
    }
}
