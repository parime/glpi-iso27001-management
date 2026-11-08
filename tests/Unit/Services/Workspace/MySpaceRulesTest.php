<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Workspace;

use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceRules as Rules;
use PHPUnit\Framework\TestCase;

final class MySpaceRulesTest extends TestCase
{
    private const TODAY = '2026-11-08';

    /**
     * @return array{source: string, id: int, name: string, due: ?string, url: string, open_task: bool}
     */
    private static function candidate(
        string $name,
        ?string $due,
        bool $openTask = false,
        string $source = 'risk_review'
    ): array {
        return [
            'source' => $source, 'id' => 1, 'name' => $name, 'due' => $due, 'url' => '/x', 'open_task' => $openTask,
        ];
    }

    public function testNormalizeDateKeepsTheDayOnly(): void
    {
        $this->assertSame('2026-11-08', Rules::normalizeDate('2026-11-08 14:30:00'));
        $this->assertSame('2026-11-08', Rules::normalizeDate('2026-11-08'));
        $this->assertNull(Rules::normalizeDate(null));
        $this->assertNull(Rules::normalizeDate(''));
        $this->assertNull(Rules::normalizeDate('0000-00-00'));
        $this->assertNull(Rules::normalizeDate('demain'));
    }

    public function testUrgencyIncludesTheDueDay(): void
    {
        $this->assertSame(Rules::URGENCY_OVERDUE, Rules::urgency('2026-11-07', self::TODAY));
        $this->assertSame(Rules::URGENCY_SOON, Rules::urgency('2026-11-08', self::TODAY), 'Dû aujourd\'hui.');
        $this->assertSame(Rules::URGENCY_SOON, Rules::urgency('2026-12-08', self::TODAY), 'Fin de l\'horizon.');
        $this->assertSame(Rules::URGENCY_LATER, Rules::urgency('2026-12-09', self::TODAY));
        $this->assertSame(Rules::URGENCY_NONE, Rules::urgency(null, self::TODAY));
    }

    public function testOpenTasksAlwaysWaitPeriodicDeadlinesOnlyWithinTheHorizon(): void
    {
        $this->assertTrue(Rules::isWaiting(null, true, self::TODAY), 'Tâche ouverte sans échéance.');
        $this->assertTrue(Rules::isWaiting('2027-06-01', true, self::TODAY), 'Tâche ouverte lointaine.');
        $this->assertTrue(Rules::isWaiting('2020-01-01', false, self::TODAY), 'Revue en retard.');
        $this->assertTrue(Rules::isWaiting('2026-12-08', false, self::TODAY));
        $this->assertFalse(Rules::isWaiting('2026-12-09', false, self::TODAY), 'Revue au-delà de 30 jours.');
        $this->assertFalse(Rules::isWaiting(null, false, self::TODAY), 'Échéance périodique sans date.');
    }

    public function testDaysLate(): void
    {
        $this->assertSame(3, Rules::daysLate('2026-11-05', self::TODAY));
        $this->assertSame(0, Rules::daysLate('2026-11-08', self::TODAY));
        $this->assertSame(0, Rules::daysLate('2026-12-01', self::TODAY));
        $this->assertSame(0, Rules::daysLate(null, self::TODAY));
        $this->assertSame(366, Rules::daysLate('2025-11-07', self::TODAY));
    }

    public function testBuildFiltersSortsAndQualifies(): void
    {
        $items = Rules::build([
            self::candidate('Sans échéance', null, true, 'treatment_action'),
            self::candidate('Revue lointaine', '2027-03-01'),
            self::candidate('Bientôt', '2026-11-20 10:00:00'),
            self::candidate('Action lointaine', '2027-03-01', true, 'treatment_action'),
            self::candidate('En retard', '2026-10-01'),
            self::candidate('b même jour', '2026-11-20'),
        ], self::TODAY);

        $this->assertSame(
            ['En retard', 'b même jour', 'Bientôt', 'Action lointaine', 'Sans échéance'],
            array_column($items, 'name'),
            'Échéance croissante, puis nom (sans casse), sans échéance à la fin ; revue lointaine écartée.'
        );
        $this->assertSame(
            [
                Rules::URGENCY_OVERDUE, Rules::URGENCY_SOON, Rules::URGENCY_SOON, Rules::URGENCY_LATER,
                Rules::URGENCY_NONE,
            ],
            array_column($items, 'urgency')
        );
        $this->assertSame(38, $items[0]['days_late']);
        $this->assertSame('2026-11-20', $items[2]['due'], 'Heure retirée.');
        $this->assertSame('', $items[0]['detail']);

        $this->assertSame(['total' => 5, 'overdue' => 1, 'soon' => 2], Rules::summary($items));
        $this->assertSame(['total' => 0, 'overdue' => 0, 'soon' => 0], Rules::summary([]));
    }

    public function testAccessReviewCampaignsKeepOnlyThoseWithPendingDecisions(): void
    {
        $line = static fn (int $campaign, string $decision): array => [
            'plugin_grcmanager_accessreviews_id' => $campaign,
            'campaign_name'                      => 'Campagne ' . $campaign,
            'campaign_due_date'                  => '2026-11-30',
            'decision'                           => $decision,
        ];
        $campaigns = Rules::accessReviewCampaigns([
            $line(1, 'pending'), $line(1, 'keep'), $line(1, 'pending'),
            $line(2, 'keep'), $line(2, 'remove'),
        ]);

        $this->assertSame(
            [['id' => 1, 'name' => 'Campagne 1', 'due' => '2026-11-30', 'pending' => 2, 'total' => 3]],
            $campaigns,
            'La campagne 2, entièrement décidée, n\'attend plus rien.'
        );
        $this->assertSame([], Rules::accessReviewCampaigns([]));
    }

    public function testTrainingRenewalsUseTheLatestCompletionOfEachProgram(): void
    {
        $row = static fn (int $id, int $program, ?string $date, int $months): array => [
            'id' => $id, 'program' => $program, 'title' => 'Formation ' . $id, 'completion_date' => $date,
            'months' => $months,
        ];
        $renewals = Rules::trainingRenewals([
            $row(1, 1, '2024-01-15', 12),
            $row(2, 1, '2025-11-01', 12),
            $row(3, 3, '2025-06-30', 0),
            $row(4, 4, null, 12),
            $row(5, 5, '2026-02-28', 6),
        ]);

        $this->assertSame([
            ['id' => 2, 'title' => 'Formation 2', 'due' => '2026-11-01'],
            ['id' => 5, 'title' => 'Formation 5', 'due' => '2026-08-28'],
        ], $renewals, 'Session récente du programme 1 seulement ; sans renouvellement ni date : rien.');
    }
}
