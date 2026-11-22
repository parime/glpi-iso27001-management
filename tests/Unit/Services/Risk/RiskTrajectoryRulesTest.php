<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskTrajectoryRules;
use PHPUnit\Framework\TestCase;

final class RiskTrajectoryRulesTest extends TestCase
{
    private const TODAY = '2026-06-15';

    /**
     * @return list<array{due_date: ?string, completion_date: ?string, status: string}>
     */
    private static function plan(): array
    {
        return [
            ['due_date' => '2026-03-01', 'completion_date' => '2026-02-20 10:00:00', 'status' => 'done'],
            ['due_date' => '2026-05-01', 'completion_date' => null, 'status' => 'in_progress'],
            ['due_date' => '2026-09-01', 'completion_date' => null, 'status' => 'planned'],
        ];
    }

    public function testNoTargetWhenResidualRiskIsNotAssessed(): void
    {
        $result = RiskTrajectoryRules::build(12.0, null, self::plan(), '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_NO_TARGET, $result['state']);
        self::assertSame([], $result['planned']);
    }

    public function testNoReductionWhenTargetIsNotLower(): void
    {
        $result = RiskTrajectoryRules::build(6.0, 6.0, self::plan(), '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_NO_REDUCTION, $result['state']);
    }

    public function testNoActions(): void
    {
        $result = RiskTrajectoryRules::build(12.0, 3.0, [], '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_NO_ACTIONS, $result['state']);
    }

    public function testPlannedCurveDropsAnEqualShareAtEachDueDate(): void
    {
        $result = RiskTrajectoryRules::build(12.0, 3.0, self::plan(), '2026-01-10', self::TODAY);

        self::assertSame(3.0, $result['step']);
        self::assertSame([
            ['2026-01-10', 12.0],
            ['2026-03-01', 9.0],
            ['2026-05-01', 6.0],
            ['2026-09-01', 3.0],
        ], $result['planned']);
        self::assertSame('2026-09-01', $result['planned_end']);
    }

    public function testActualCurveDropsOnCompletionAndStopsToday(): void
    {
        $result = RiskTrajectoryRules::build(12.0, 3.0, self::plan(), '2026-01-10', self::TODAY);

        self::assertSame([
            ['2026-01-10', 12.0],
            ['2026-02-20', 9.0],
            [self::TODAY, 9.0],
        ], $result['actual']);
        self::assertSame(6.0, $result['planned_today']);
        self::assertSame(9.0, $result['actual_today']);
    }

    public function testOverdueActionMakesThePlanLate(): void
    {
        $result = RiskTrajectoryRules::build(12.0, 3.0, self::plan(), '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_LATE, $result['state']);
        self::assertSame(1, $result['late']);
        self::assertSame(1, $result['done']);
        self::assertSame(3, $result['total']);
    }

    public function testActionDoneEarlyIsAhead(): void
    {
        $actions = [
            ['due_date' => '2026-08-01', 'completion_date' => '2026-04-01', 'status' => 'done'],
            ['due_date' => '2026-10-01', 'completion_date' => null, 'status' => 'planned'],
        ];
        $result = RiskTrajectoryRules::build(8.0, 2.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_AHEAD, $result['state']);
        self::assertSame(5.0, $result['actual_today']);
        self::assertSame(8.0, $result['planned_today']);
    }

    public function testOnTrackWhenNothingIsDueYet(): void
    {
        $actions = [['due_date' => '2026-08-01', 'completion_date' => null, 'status' => 'planned']];
        $result = RiskTrajectoryRules::build(8.0, 2.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_ON_TRACK, $result['state']);
        // La courbe prévue est prolongée au-delà d'aujourd'hui jusqu'à la dernière échéance.
        self::assertSame([['2026-01-10', 8.0], ['2026-08-01', 2.0]], $result['planned']);
    }

    public function testAllActionsDone(): void
    {
        $actions = [['due_date' => '2026-02-01', 'completion_date' => '2026-02-03', 'status' => 'done']];
        $result = RiskTrajectoryRules::build(8.0, 2.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame(RiskTrajectoryRules::STATE_DONE, $result['state']);
        self::assertSame(2.0, $result['actual_today']);
        // Prévue prolongée jusqu'à aujourd'hui au niveau visé.
        self::assertSame([self::TODAY, 2.0], end($result['planned']));
    }

    public function testUnscheduledActionKeepsThePlanAboveTheTarget(): void
    {
        $actions = [
            ['due_date' => '2026-08-01', 'completion_date' => null, 'status' => 'planned'],
            ['due_date' => null, 'completion_date' => null, 'status' => 'planned'],
        ];
        $result = RiskTrajectoryRules::build(8.0, 2.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame(1, $result['unscheduled']);
        self::assertSame(5.0, end($result['planned'])[1]);
    }

    public function testMilestonesGroupActionsDueTheSameDay(): void
    {
        $actions = [
            ['due_date' => '2026-08-01', 'completion_date' => null, 'status' => 'planned'],
            ['due_date' => '2026-08-01', 'completion_date' => null, 'status' => 'planned'],
            ['due_date' => '2026-03-01', 'completion_date' => null, 'status' => 'planned'],
        ];
        $result = RiskTrajectoryRules::build(9.0, 3.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame([
            ['date' => '2026-03-01', 'count' => 1, 'done' => 0, 'late' => 1, 'score' => 7.0],
            ['date' => '2026-08-01', 'count' => 2, 'done' => 0, 'late' => 0, 'score' => 3.0],
        ], $result['milestones']);
        self::assertSame([['2026-01-10', 9.0], ['2026-03-01', 7.0], ['2026-08-01', 3.0]], $result['planned']);
    }

    public function testCurveStartsAtTheEarliestDateWhenAnActionPrecedesTheRisk(): void
    {
        $actions = [['due_date' => '2025-12-01', 'completion_date' => '2025-12-01', 'status' => 'done']];
        $result = RiskTrajectoryRules::build(4.0, 1.0, $actions, '2026-01-10', self::TODAY);

        self::assertSame('2025-12-01', $result['planned'][0][0]);
    }

    public function testValueAt(): void
    {
        $points = [['2026-01-01', 10.0], ['2026-03-01', 6.0]];

        self::assertSame(10.0, RiskTrajectoryRules::valueAt($points, '2025-06-01'));
        self::assertSame(10.0, RiskTrajectoryRules::valueAt($points, '2026-02-28'));
        self::assertSame(6.0, RiskTrajectoryRules::valueAt($points, '2026-03-01'));
        self::assertSame(0.0, RiskTrajectoryRules::valueAt([], '2026-03-01'));
    }

    public function testAggregateSumsRisksAndStopsActualToday(): void
    {
        $first = RiskTrajectoryRules::build(12.0, 3.0, self::plan(), '2026-01-10', self::TODAY);
        $second = RiskTrajectoryRules::build(
            4.0,
            2.0,
            [['due_date' => '2026-02-01', 'completion_date' => '2026-02-01', 'status' => 'done']],
            '2026-01-10',
            self::TODAY
        );
        $register = RiskTrajectoryRules::aggregate([$first, $second], self::TODAY);

        self::assertSame(['2026-01-10', 16.0], $register['planned'][0]);
        self::assertSame(['2026-09-01', 5.0], end($register['planned']));
        self::assertSame([self::TODAY, 11.0], end($register['actual']));
        foreach ($register['actual'] as [$date]) {
            self::assertLessThanOrEqual(self::TODAY, $date);
        }
    }
}
