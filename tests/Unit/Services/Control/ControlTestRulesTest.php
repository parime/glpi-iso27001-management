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

    public function testOnlyAnIneffectiveControlOpensANonconformity(): void
    {
        self::assertTrue(ControlTestRules::opensNonconformity('ineffective'));
        self::assertFalse(ControlTestRules::opensNonconformity('partial'));
        self::assertFalse(ControlTestRules::opensNonconformity('effective'));
    }
}
