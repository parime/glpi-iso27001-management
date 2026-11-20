<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Consistency;

use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #614 : règles de cohérence du SMSI, chacune sur des données fictives.
 */
final class ConsistencyRulesTest extends TestCase
{
    private const TODAY = '2026-11-15';

    public function testCatalogIsCompleteAndOrderedByArea(): void
    {
        $areas = array_keys(R::AREAS);
        $previous = 0;
        foreach (R::RULES as $key => $rule) {
            self::assertContains($rule['area'], $areas, $key);
            self::assertArrayHasKey($rule['severity'], R::SEVERITY_WEIGHTS, $key);
            self::assertNotSame('', $rule['clause'], $key);
            $position = array_search($rule['area'], $areas, true);
            self::assertGreaterThanOrEqual($previous, $position, $key . ' : règles regroupées par domaine');
            $previous = $position;
        }
        self::assertCount(24, R::RULES);
    }

    public function testSettingsDefaultToEnabledWithDefaultThresholds(): void
    {
        $settings = R::settings([]);

        self::assertSame(array_keys(R::RULES), array_keys($settings));
        self::assertTrue($settings['risk_without_owner']['enabled']);
        self::assertNull($settings['risk_without_owner']['threshold']);
        self::assertSame(12, $settings['control_without_evidence']['threshold']);
        self::assertSame('high', $settings['risk_accepted_without_approval']['threshold']);
        self::assertSame(20, $settings['control_exclusion_weak_justification']['threshold']);
    }

    public function testThresholdsAreBoundedAndInvalidValuesFallBack(): void
    {
        self::assertSame(60, R::threshold('control_without_evidence', '999'));
        self::assertSame(1, R::threshold('control_without_evidence', '0'));
        self::assertSame(12, R::threshold('control_without_evidence', 'abc'));
        self::assertSame(12, R::threshold('control_without_evidence', '6.5'));
        self::assertSame(30, R::threshold('policy_review_overdue', ' 30 '));
        self::assertSame(0, R::threshold('policy_review_overdue', '-5'));
        self::assertSame('critical', R::threshold('risk_without_decision', 'critical'));
        self::assertSame('high', R::threshold('risk_without_decision', 'low'), 'niveau non proposé');
        self::assertSame('high', R::threshold('risk_without_decision', ['x']));
        self::assertNull(R::threshold('risk_without_owner', 5));
        self::assertNull(R::threshold('unknown_rule', 5));
    }

    public function testFormDisablesUncheckedRulesAndKeepsThresholds(): void
    {
        $settings = R::fromForm([
            'risk_without_owner'       => ['enabled' => '0'],
            'control_without_evidence' => ['enabled' => '1', 'threshold' => '24'],
            'policy_review_overdue'    => ['threshold' => '15'],
            'not_a_rule'               => ['enabled' => '1'],
        ]);

        self::assertFalse($settings['risk_without_owner']['enabled']);
        self::assertTrue($settings['control_without_evidence']['enabled']);
        self::assertSame(24, $settings['control_without_evidence']['threshold']);
        self::assertFalse($settings['policy_review_overdue']['enabled'], 'case absente : décochée');
        self::assertSame(15, $settings['policy_review_overdue']['threshold']);
        self::assertArrayNotHasKey('not_a_rule', $settings);

        $stored = R::settings(['risk_without_owner' => ['enabled' => false]]);
        self::assertFalse($stored['risk_without_owner']['enabled']);
        self::assertTrue($stored['risk_review_overdue']['enabled']);
    }

    public function testDatesAndReviewState(): void
    {
        self::assertTrue(R::isPastDue('2026-11-14', self::TODAY));
        self::assertFalse(R::isPastDue('2026-11-15', self::TODAY), 'échéance du jour : pas en retard');
        self::assertFalse(R::isPastDue('2026-11-10', self::TODAY, 10), 'dans la tolérance');
        self::assertTrue(R::isPastDue('2026-11-04 08:00:00', self::TODAY, 10));
        self::assertFalse(R::isPastDue(null, self::TODAY));
        self::assertFalse(R::isPastDue('0000-00-00', self::TODAY));

        self::assertSame('missing', R::reviewState(null, self::TODAY, 0, true));
        self::assertNull(R::reviewState('', self::TODAY, 0, false));
        self::assertSame('overdue', R::reviewState('2026-01-01', self::TODAY, 0, true));
        self::assertNull(R::reviewState('2027-01-01', self::TODAY, 0, true));
        self::assertSame('2025-11-15', R::monthsAgo(self::TODAY, 12));
    }

    public function testWeakTextIgnoresMarkupAndSpaces(): void
    {
        self::assertTrue(R::isWeakText('', 1));
        self::assertTrue(R::isWeakText('<p>&nbsp; </p>', 1));
        self::assertTrue(R::isWeakText('<p>N/A</p>', 20));
        self::assertFalse(R::isWeakText('<p>Aucune activité de développement logiciel</p>', 20));
        self::assertFalse(R::isWeakText('éèàçôûîïöü', 10), 'caractères, pas octets');
    }

    public function testPolicyAndRiskOwnerRules(): void
    {
        self::assertTrue(R::riskWithoutOwner(['status' => 'identified', 'users_id' => 0]));
        self::assertFalse(R::riskWithoutOwner(['status' => 'identified', 'users_id' => 7]));
        self::assertFalse(R::riskWithoutOwner(['status' => 'closed', 'users_id' => 0]), 'risque clos');
    }

    public function testRiskWithoutDecisionDependsOnTheLevelThreshold(): void
    {
        $risk = ['status' => 'identified', 'treatment' => '', 'risk_level' => 'high'];

        self::assertTrue(R::riskWithoutDecision($risk, 'high'));
        self::assertFalse(R::riskWithoutDecision($risk, 'critical'));
        self::assertFalse(R::riskWithoutDecision(['treatment' => 'mitigate'] + $risk, 'high'));
        self::assertFalse(R::riskWithoutDecision(['status' => 'closed'] + $risk, 'high'));
        self::assertTrue(R::riskWithoutDecision(['risk_level' => 'critical'] + $risk, 'medium'));
    }

    public function testRiskAcceptedWithoutApprovalUsesTheResidualLevel(): void
    {
        $risk = [
            'status' => 'accepted', 'treatment' => 'accept', 'risk_level' => 'critical',
            'residual_level' => '', 'users_id_acceptance' => 0,
        ];

        self::assertTrue(R::riskAcceptedWithoutApproval($risk, 'high'));
        self::assertFalse(R::riskAcceptedWithoutApproval(['users_id_acceptance' => 3] + $risk, 'high'), 'signée');
        self::assertFalse(
            R::riskAcceptedWithoutApproval(['residual_level' => 'medium'] + $risk, 'high'),
            'risque résiduel moyen'
        );
        self::assertFalse(R::riskAcceptedWithoutApproval(['treatment' => 'mitigate'] + $risk, 'high'));
        self::assertSame('medium', R::retainedLevel(['residual_level' => 'medium'] + $risk));
        self::assertSame('critical', R::retainedLevel(['residual_level' => 'bogus'] + $risk));
    }

    public function testExclusionJustificationAndApplicableControlsWithoutRisk(): void
    {
        self::assertTrue(R::exclusionWeaklyJustified(['applicability' => 'no', 'justification' => 'N/A'], 20));
        self::assertTrue(R::exclusionWeaklyJustified(['applicability' => 'partial', 'justification' => null], 1));
        self::assertFalse(R::exclusionWeaklyJustified(
            ['applicability' => 'no', 'justification' => 'Pas de développement logiciel en interne'],
            20
        ));
        self::assertFalse(R::exclusionWeaklyJustified(['applicability' => 'yes', 'justification' => ''], 20));

        $control = ['applicability' => 'yes', 'is_reviewed' => 1];
        self::assertTrue(R::applicableWithoutRisk($control, 0));
        self::assertFalse(R::applicableWithoutRisk($control, 2));
        self::assertFalse(R::applicableWithoutRisk(['is_reviewed' => 0] + $control, 0), 'pas encore revue');
        self::assertFalse(R::applicableWithoutRisk(['applicability' => 'no'] + $control, 0));
    }

    public function testImplementedControlNeedsValidEvidenceOrARecentTest(): void
    {
        $control = ['applicability' => 'yes', 'implementation_status' => 'implemented'];

        self::assertTrue(R::implementedWithoutEvidence($control, 0, null, self::TODAY, 12));
        self::assertTrue(R::implementedWithoutEvidence($control, 0, '2025-10-01', self::TODAY, 12), 'test trop ancien');
        self::assertFalse(R::implementedWithoutEvidence($control, 0, '2026-03-01', self::TODAY, 12), 'test récent');
        self::assertFalse(R::implementedWithoutEvidence($control, 1, null, self::TODAY, 12), 'document valide');
        self::assertTrue(R::implementedWithoutEvidence($control, 0, '2026-03-01', self::TODAY, 6));
        self::assertFalse(R::implementedWithoutEvidence(
            ['implementation_status' => 'in_progress'] + $control,
            0,
            null,
            self::TODAY,
            12
        ));
        self::assertTrue(R::implementedWithoutEvidence(
            ['implementation_status' => 'verified'] + $control,
            0,
            null,
            self::TODAY,
            12
        ));
    }

    public function testExpiredEvidenceAndRecentEvents(): void
    {
        self::assertTrue(R::isExpired('2026-11-14', self::TODAY));
        self::assertFalse(R::isExpired('2026-11-15', self::TODAY), 'valable le jour même');
        self::assertFalse(R::isExpired(null, self::TODAY), 'sans date de validité');

        self::assertTrue(R::noRecentEvent(null, self::TODAY, 12));
        self::assertTrue(R::noRecentEvent('2025-11-14', self::TODAY, 12));
        self::assertFalse(R::noRecentEvent('2025-11-15', self::TODAY, 12));
        self::assertTrue(R::noRecentEvent('2026-03-01', self::TODAY, 6));
    }

    public function testNonconformityRules(): void
    {
        $closed = ['finding_type' => 'nonconformity', 'status' => 'closed', 'corrective_action' => '<p></p>'];

        self::assertTrue(R::closedWithoutAction($closed));
        self::assertTrue(R::closedWithoutAction(['status' => 'verified'] + $closed));
        self::assertFalse(R::closedWithoutAction(['corrective_action' => 'Procédure revue'] + $closed));
        self::assertFalse(R::closedWithoutAction(['finding_type' => 'observation'] + $closed), 'observation');
        self::assertFalse(R::closedWithoutAction(['status' => 'open'] + $closed));

        $open = ['status' => 'in_progress', 'due_date' => '2026-10-01'];
        self::assertTrue(R::nonconformityOverdue($open, self::TODAY, 0));
        self::assertFalse(R::nonconformityOverdue($open, self::TODAY, 60));
        self::assertFalse(R::nonconformityOverdue(['status' => 'closed'] + $open, self::TODAY, 0));
        self::assertFalse(R::nonconformityOverdue(['due_date' => null] + $open, self::TODAY, 0));
    }

    public function testTreatmentActionWaiverProcessDpiaAndAccessReviewRules(): void
    {
        $today = self::TODAY;
        self::assertTrue(R::treatmentActionOverdue(['status' => 'planned', 'due_date' => '2026-11-01'], $today, 0));
        self::assertFalse(R::treatmentActionOverdue(['status' => 'done', 'due_date' => '2026-11-01'], $today, 0));

        self::assertTrue(R::waiverExpired(['status' => 'approved', 'date_end' => '2026-11-01'], $today));
        self::assertFalse(R::waiverExpired(['status' => 'closed', 'date_end' => '2026-11-01'], $today));
        self::assertFalse(R::waiverExpired(['status' => 'approved', 'date_end' => '2027-01-01'], self::TODAY));

        self::assertTrue(R::processWithoutRto(['rto_hours' => 0]));
        self::assertFalse(R::processWithoutRto(['rto_hours' => 4]));

        self::assertTrue(R::dpiaPending(['status' => 'active', 'dpia_status' => 'required']));
        self::assertFalse(R::dpiaPending(['status' => 'active', 'dpia_status' => 'done']));
        self::assertFalse(R::dpiaPending(['status' => 'archived', 'dpia_status' => 'required']));

        self::assertTrue(R::accessReviewOverdue(['status' => 'open', 'due_date' => '2026-11-01'], $today, 0));
        self::assertFalse(R::accessReviewOverdue(['status' => 'closed', 'due_date' => '2026-11-01'], $today, 0));
        self::assertFalse(R::accessReviewOverdue(['status' => 'open', 'due_date' => '2026-11-10'], self::TODAY, 7));
    }

    public function testScoreIsWeightedBySeverity(): void
    {
        self::assertNull(R::score([]));
        self::assertNull(R::score(['a' => ['severity' => 'high', 'total' => 0, 'gaps' => 0]]));
        self::assertSame(100, R::score(['a' => ['severity' => 'high', 'total' => 10, 'gaps' => 0]]));
        // Haute : 3 x 10 examinés, 3 x 5 écarts ; basse : 1 x 10, aucun écart. 1 - 15 / 40.
        self::assertSame(63, R::score([
            'a' => ['severity' => 'high', 'total' => 10, 'gaps' => 5],
            'b' => ['severity' => 'low', 'total' => 10, 'gaps' => 0],
        ]));
        self::assertSame(0, R::score(['a' => ['severity' => 'medium', 'total' => 1, 'gaps' => 3]]));

        self::assertSame('red', R::scoreColor(59));
        self::assertSame('orange', R::scoreColor(84));
        self::assertSame('green', R::scoreColor(85));
        self::assertSame('secondary', R::scoreColor(null));

        self::assertSame(
            ['high' => 5, 'medium' => 1, 'low' => 0],
            R::countBySeverity([
                'a' => ['severity' => 'high', 'gaps' => 5],
                'b' => ['severity' => 'medium', 'gaps' => 1],
            ])
        );
    }

    public function testDeltasFiltersAndCsvCells(): void
    {
        self::assertSame(
            ['a' => 2, 'b' => -1, 'c' => null],
            R::deltas(['a' => 5, 'b' => 0, 'c' => 3], ['a' => 3, 'b' => 1])
        );

        $filters = R::filters(['area' => 'policies', 'severity' => 'bogus', 'rule' => ['x']]);
        self::assertSame(['area' => 'policies', 'severity' => '', 'rule' => ''], $filters);
        self::assertTrue(R::matches('policy_without_owner', $filters));
        self::assertFalse(R::matches('risk_without_owner', $filters));
        self::assertTrue(R::matches('risk_without_owner', ['area' => '', 'severity' => 'high', 'rule' => '']));
        self::assertFalse(R::matches('risk_review_overdue', ['area' => '', 'severity' => 'high', 'rule' => '']));
        self::assertFalse(R::matches('unknown', ['area' => '', 'severity' => '', 'rule' => '']));

        self::assertSame("'=SUM(A1)", R::csvCell('=SUM(A1)'));
        self::assertSame('Politique fictive', R::csvCell('Politique fictive'));
    }
}
