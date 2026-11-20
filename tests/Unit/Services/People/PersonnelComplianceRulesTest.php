<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\People;

use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #546 : règles de la fiche de conformité sécurité d'un collaborateur.
 */
final class PersonnelComplianceRulesTest extends TestCase
{
    private const TODAY = '2026-11-20';

    /**
     * @param array<string, mixed> $facts
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $facts, int $days = 30, array $weights = []): array
    {
        $result = Rules::evaluate($facts, self::TODAY, $days, $weights);

        return array_column($result['components'], null, 'key');
    }

    /** @return array<string, mixed> */
    private static function compliant(): array
    {
        return [
            'arrival'        => '2024-01-15',
            'screening'      => ['status' => Rules::SCREENING_DONE, 'date' => '2024-01-10'],
            'nda'            => ['date' => '2024-01-15', 'version' => 'v2'],
            'trainings'      => [[
                'title' => 'Sensibilisation', 'status' => 'completed', 'completion_date' => '2026-03-01',
                'assigned' => '2026-02-01', 'renewal_months' => 12,
            ]],
            'charter'        => ['status' => 'signed', 'date' => '2026-02-01 10:00:00', 'campaign' => 'Charte 2026'],
            'policies'       => [['title' => 'PSSI', 'version' => '3', 'due_date' => '2026-05-01',
                'acknowledged' => '2026-04-20 09:00:00']],
            'access_review'  => ['last_decision' => '2026-06-01 12:00:00', 'pending_due' => null],
            'account_checks' => [],
        ];
    }

    public function testACompliantPersonScoresOneHundred(): void
    {
        $result = Rules::evaluate(self::compliant(), self::TODAY, 30, []);

        self::assertSame(Rules::OK, $result['status']);
        self::assertSame(100, $result['score']);
        self::assertSame(7, $result['compliant']);
        self::assertSame(Rules::COMPONENTS, array_column($result['components'], 'key'));
    }

    public function testNewcomerIsPendingWithinTheDelayThenLate(): void
    {
        $facts = ['arrival' => '2026-11-10'];
        $within = self::byKey($facts);
        self::assertSame(Rules::PENDING, $within[Rules::SCREENING]['status']);
        self::assertSame('2026-12-10', $within[Rules::SCREENING]['deadline']);
        self::assertSame(Rules::PENDING, $within[Rules::NDA]['status']);
        self::assertSame(Rules::PENDING, $within[Rules::TRAINING]['status'], 'Aucune formation encore.');
        self::assertSame('none', $within[Rules::TRAINING]['code']);

        $late = self::byKey($facts, 5);
        self::assertSame(Rules::LATE, $late[Rules::SCREENING]['status'], 'Délai de 5 jours dépassé.');
        self::assertSame(Rules::LATE, $late[Rules::NDA]['status']);
    }

    public function testScreeningNotApplicableIsNotCounted(): void
    {
        $facts = ['screening' => ['status' => Rules::SCREENING_NOT_APPLICABLE]] + self::compliant();
        $result = Rules::evaluate($facts, self::TODAY, 30, []);

        $components = array_column($result['components'], null, 'key');
        self::assertSame(Rules::NOT_APPLICABLE, $components[Rules::SCREENING]['status']);
        self::assertSame(6, $result['counted']);
        self::assertSame(100, $result['score']);
    }

    public function testTrainingAssignedLaterGetsItsOwnDelay(): void
    {
        $facts = ['trainings' => [[
            'title' => 'Phishing', 'status' => 'pending', 'completion_date' => null,
            'assigned' => '2026-11-01 08:00:00', 'renewal_months' => 0,
        ]]] + self::compliant();
        $training = self::byKey($facts)[Rules::TRAINING];

        self::assertSame(Rules::PENDING, $training['status']);
        self::assertSame('2026-12-01', $training['deadline']);
        self::assertSame(['titles' => ['Phishing']], $training['params']);
        self::assertSame(Rules::LATE, self::byKey($facts, 10)[Rules::TRAINING]['status']);
    }

    public function testTrainingRenewalOverdueOrSoon(): void
    {
        $overdue = ['trainings' => [[
            'title' => 'Sensibilisation', 'status' => 'completed', 'completion_date' => '2025-10-01',
            'assigned' => null, 'renewal_months' => 12,
        ]]] + self::compliant();
        self::assertSame('renewal_overdue', self::byKey($overdue)[Rules::TRAINING]['code']);
        self::assertSame(Rules::LATE, self::byKey($overdue)[Rules::TRAINING]['status']);

        $soon = ['trainings' => [[
            'title' => 'Sensibilisation', 'status' => 'completed', 'completion_date' => '2025-12-01',
            'assigned' => null, 'renewal_months' => 12,
        ]]] + self::compliant();
        $training = self::byKey($soon)[Rules::TRAINING];
        self::assertSame(Rules::OK, $training['status']);
        self::assertTrue($training['soon']);
        self::assertSame('2026-12-01', $training['deadline']);
    }

    public function testExemptedTrainingCountsAsDone(): void
    {
        $facts = ['trainings' => [['title' => 'Sensibilisation', 'status' => 'exempted']]] + self::compliant();

        self::assertSame(Rules::OK, self::byKey($facts)[Rules::TRAINING]['status']);
    }

    public function testCharterIsNotTrackedWithoutAssetsign(): void
    {
        $facts = ['charter' => null] + self::compliant();
        $result = Rules::evaluate($facts, self::TODAY, 30, []);

        $components = array_column($result['components'], null, 'key');
        self::assertSame(Rules::NOT_TRACKED, $components[Rules::CHARTER]['status']);
        self::assertSame(6, $result['counted'], 'Une source absente ne pèse pas sur le score.');
        self::assertSame(100, $result['score']);
    }

    public function testCharterPendingSinceTheCampaign(): void
    {
        $facts = ['charter' => ['status' => 'pending', 'assigned' => '2026-11-15 08:00:00', 'campaign' => 'Charte']]
            + self::compliant();

        $charter = self::byKey($facts)[Rules::CHARTER];
        self::assertSame(Rules::PENDING, $charter['status']);
        self::assertSame('2026-12-15', $charter['deadline']);
    }

    public function testPolicyDeadlineIsNeverBeforeTheCampaignDueDate(): void
    {
        $facts = ['policies' => [
            ['title' => 'PSSI', 'version' => '4', 'due_date' => '2026-12-31', 'acknowledged' => null],
        ]]
            + self::compliant();
        $policies = self::byKey($facts)[Rules::POLICIES];
        self::assertSame(Rules::PENDING, $policies['status']);
        self::assertSame('2026-12-31', $policies['deadline']);

        $past = ['policies' => [
            ['title' => 'PSSI', 'version' => '4', 'due_date' => '2026-10-01', 'acknowledged' => null],
        ]]
            + self::compliant();
        self::assertSame(Rules::LATE, self::byKey($past)[Rules::POLICIES]['status']);

        $none = self::byKey(['policies' => []] + self::compliant());
        self::assertSame(Rules::NOT_APPLICABLE, $none[Rules::POLICIES]['status']);
    }

    public function testAccessReview(): void
    {
        $outdated = ['access_review' => ['last_decision' => '2025-06-01', 'pending_due' => null]] + self::compliant();
        self::assertSame('outdated', self::byKey($outdated)[Rules::ACCESS_REVIEW]['code']);
        self::assertSame(Rules::LATE, self::byKey($outdated)[Rules::ACCESS_REVIEW]['status']);

        $campaignLate = ['access_review' => ['last_decision' => null, 'pending_due' => '2026-11-01']]
            + self::compliant();
        self::assertSame('campaign_late', self::byKey($campaignLate)[Rules::ACCESS_REVIEW]['code']);

        $campaignOpen = ['access_review' => ['last_decision' => '2025-06-01', 'pending_due' => '2026-12-15']]
            + self::compliant();
        self::assertSame(Rules::PENDING, self::byKey($campaignOpen)[Rules::ACCESS_REVIEW]['status']);

        $never = ['arrival' => '2026-03-01', 'access_review' => []] + self::compliant();
        $review = self::byKey($never)[Rules::ACCESS_REVIEW];
        self::assertSame('never', $review['code']);
        self::assertSame(Rules::PENDING, $review['status']);
        self::assertSame('2027-03-01', $review['deadline']);

        $soon = ['access_review' => ['last_decision' => '2025-12-01']] + self::compliant();
        self::assertTrue(self::byKey($soon)[Rules::ACCESS_REVIEW]['soon']);
    }

    public function testAccountChecksAreAGap(): void
    {
        $facts = ['account_checks' => ['expired']] + self::compliant();
        $account = self::byKey($facts)[Rules::ACCOUNT];

        self::assertSame(Rules::LATE, $account['status']);
        self::assertSame(['checks' => ['expired']], $account['params']);
    }

    public function testWeightsChangeTheScoreAndZeroDisablesAComponent(): void
    {
        $facts = ['nda' => []] + self::compliant(); // engagement de confidentialité manquant.

        $equal = Rules::evaluate($facts, self::TODAY, 30, []);
        self::assertSame(Rules::LATE, $equal['status']);
        self::assertSame(86, $equal['score'], '6 sur 7.');

        $heavy = Rules::evaluate($facts, self::TODAY, 30, [Rules::NDA => 3]);
        self::assertSame(67, $heavy['score'], '6 sur 9.');

        $disabled = Rules::evaluate($facts, self::TODAY, 30, [Rules::NDA => 0]);
        self::assertSame(Rules::OK, $disabled['status']);
        self::assertSame(100, $disabled['score']);
        self::assertSame('disabled', array_column($disabled['components'], null, 'key')[Rules::NDA]['code']);
    }

    public function testStaffRateCountsPeopleWithoutGap(): void
    {
        $rate = Rules::staffRate([
            ['status' => Rules::OK], ['status' => Rules::PENDING], ['status' => Rules::LATE], ['status' => Rules::OK],
        ]);

        self::assertSame(['people' => 4, 'compliant' => 3, 'late' => 1, 'pending' => 1, 'rate' => 75], $rate);
        self::assertNull(Rules::staffRate([])['rate']);
    }

    public function testNormalization(): void
    {
        self::assertSame(30, Rules::normalizeDays('abc'));
        self::assertSame(30, Rules::normalizeDays(0));
        self::assertSame(45, Rules::normalizeDays('45'));
        self::assertSame(30, Rules::normalizeDays(400));

        $weights = Rules::normalizeWeights([Rules::NDA => '3', Rules::TRAINING => 9, 'other' => 2]);
        self::assertSame(3, $weights[Rules::NDA]);
        self::assertSame(Rules::DEFAULT_WEIGHT, $weights[Rules::TRAINING]);
        self::assertArrayNotHasKey('other', $weights);
        self::assertSame(Rules::COMPONENTS, array_keys($weights));
    }

    public function testDeadlineTakesTheLatestStart(): void
    {
        self::assertSame('2026-02-10', Rules::deadline(10, '2026-01-01', '2026-01-31 23:00:00', null));
        self::assertNull(Rules::deadline(10, null, ''));
    }
}
