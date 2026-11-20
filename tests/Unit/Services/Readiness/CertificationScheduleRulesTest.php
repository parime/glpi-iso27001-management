<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Readiness;

use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #761 : date cible, échéances conseillées, cycle de maintien et seuils réglables, sur des
 * dates fictives.
 */
final class CertificationScheduleRulesTest extends TestCase
{
    public function testEveryStepHasALeadTimeInTheOrderOfTheJourney(): void
    {
        self::assertSame(array_keys(R::STEPS), array_keys(R::LEAD_DAYS), 'un délai par étape, même ordre');
        $previous = PHP_INT_MAX;
        foreach (R::LEAD_DAYS as $step => $days) {
            self::assertGreaterThan(0, $days, $step);
            self::assertLessThanOrEqual($previous, $days, $step . ' : jamais plus tôt que l\'étape précédente');
            $previous = $days;
        }
        self::assertGreaterThan(
            R::LEAD_DAYS['management_review'],
            R::LEAD_DAYS['internal_audit'],
            'audit interne avant la revue de direction'
        );
        foreach (R::ANNUAL_STEPS as $step) {
            self::assertArrayHasKey($step, R::STEPS);
        }
    }

    public function testAdvisedDeadlineCountsBackFromTheTarget(): void
    {
        self::assertSame('2027-01-17', R::advisedDeadline('2027-03-03', 'internal_audit'));
        self::assertSame('2027-02-01', R::advisedDeadline('2027-03-03', 'management_review'));
        self::assertSame('2026-10-04', R::advisedDeadline('2027-03-03', 'scope'));
    }

    public function testDeadlineState(): void
    {
        $today = '2026-11-20';
        self::assertSame(R::DEADLINE_NONE, R::deadlineState(null, R::STATUS_TODO, $today), 'sans date cible');
        self::assertSame(R::DEADLINE_NONE, R::deadlineState('2026-11-01', R::STATUS_DONE, $today), 'étape faite');
        self::assertSame(R::DEADLINE_NONE, R::deadlineState('2026-11-01', R::STATUS_LOCKED, $today), 'hors droits');
        self::assertSame(R::DEADLINE_LATE, R::deadlineState('2026-11-19', R::STATUS_PARTIAL, $today));
        self::assertSame(R::DEADLINE_AT_RISK, R::deadlineState('2026-11-20', R::STATUS_TODO, $today), 'aujourd\'hui');
        self::assertSame(R::DEADLINE_AT_RISK, R::deadlineState('2026-12-04', R::STATUS_TODO, $today), '14 jours');
        self::assertSame(R::DEADLINE_ON_TRACK, R::deadlineState('2026-12-05', R::STATUS_TODO, $today));
    }

    public function testMaintenanceCycleAndNextAudit(): void
    {
        self::assertSame([
            ['type' => 'surveillance_1', 'date' => '2026-03-10'],
            ['type' => 'surveillance_2', 'date' => '2027-03-10'],
            ['type' => 'renewal', 'date' => '2028-03-10'],
        ], R::maintenanceCycle('2025-03-10'));

        self::assertNull(R::nextCycleAudit(null, '2026-11-20'), 'pas certifié');
        self::assertNull(R::nextCycleAudit('2027-01-01', '2026-11-20'), 'certification à venir');
        self::assertSame(
            ['type' => 'surveillance_2', 'date' => '2027-03-10'],
            R::nextCycleAudit('2025-03-10', '2026-11-20')
        );
        self::assertSame(
            ['type' => 'surveillance_1', 'date' => '2026-03-10'],
            R::nextCycleAudit('2025-03-10', '2026-03-10'),
            'le jour même'
        );
        // Après le renouvellement, le cycle suivant recommence.
        self::assertSame('2028-03-10', R::currentCycleStart('2025-03-10', '2028-11-20'));
        self::assertSame(
            ['type' => 'surveillance_1', 'date' => '2029-03-10'],
            R::nextCycleAudit('2025-03-10', '2028-11-20')
        );
        self::assertSame('2010-01-15', substr(R::currentCycleStart('1980-01-15', '2012-06-01'), 0, 10));
    }

    public function testTargetPrefersTheEnteredDateThenThePlannedAuditThenTheCycle(): void
    {
        $today = '2026-11-20';
        $audit = static fn (string $date, string $source = R::TARGET_AUDIT): array => [
            'date' => $date, 'source' => $source, 'type' => 'surveillance_1', 'name' => 'Audit fictif',
            'url' => '/a', 'users_id' => 7,
        ];
        $cycle = ['type' => 'surveillance_2', 'date' => '2027-03-10'];

        $manual = R::pickTarget('2026-12-03', [$audit('2026-12-10')], $cycle, $today);
        self::assertSame(['2026-12-03', R::TARGET_MANUAL], [$manual['date'] ?? '', $manual['source'] ?? '']);

        $planned = R::pickTarget(
            '2026-11-01',
            [$audit('2027-01-10'), $audit('2026-12-10', R::TARGET_EVENT)],
            $cycle,
            $today
        );
        self::assertSame(
            ['2026-12-10', R::TARGET_EVENT, 7],
            [$planned['date'] ?? '', $planned['source'] ?? '', $planned['users_id'] ?? 0],
            'date saisie passée ignorée, plus proche d\'abord'
        );

        $fromCycle = R::pickTarget(null, [$audit('2026-01-10')], $cycle, $today);
        self::assertSame(
            ['2027-03-10', R::TARGET_CYCLE],
            [$fromCycle['date'] ?? '', $fromCycle['source'] ?? ''],
            'audit passé ignoré'
        );

        self::assertNull(R::pickTarget(null, [], null, $today));
    }

    public function testSettingsAreCleaned(): void
    {
        self::assertSame('2026-12-03', R::normalizeDate('2026-12-03'));
        self::assertSame('2026-12-03', R::normalizeDate('2026-12-03 10:00:00'));
        self::assertNull(R::normalizeDate('2026-02-30'));
        self::assertNull(R::normalizeDate('demain'));
        self::assertNull(R::normalizeDate(''));
        self::assertNull(R::normalizeDate(['2026-12-03']));

        self::assertSame(90, R::normalizePercent('90', 100));
        self::assertSame(100, R::normalizePercent('250', 80));
        self::assertSame(1, R::normalizePercent('-5', 80));
        self::assertSame(80, R::normalizePercent('', 80));
        self::assertSame(80, R::normalizePercent(null, 80));
    }

    public function testThresholdsReplaceTheFixedValues(): void
    {
        self::assertSame(R::STATUS_PARTIAL, R::awareness(3, 1, 70), 'cible par défaut : 80 %');
        self::assertSame(R::STATUS_DONE, R::awareness(3, 1, 70, 70), 'cible réglée à 70 %');
        self::assertSame(R::STATUS_PARTIAL, R::internalAudit(true, 2, 90), 'cible par défaut : 100 %');
        self::assertSame(R::STATUS_DONE, R::internalAudit(true, 2, 90, 90), 'cible réglée à 90 %');
    }
}
