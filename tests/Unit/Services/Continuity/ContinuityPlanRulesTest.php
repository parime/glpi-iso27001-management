<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Continuity;

use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1083 : règles des plans de continuité et de reprise (types, revue, tests, contenu figé,
 * manques, indicateurs). Données fictives.
 */
final class ContinuityPlanRulesTest extends TestCase
{
    public function testTypesSeparateBusinessAndItPlans(): void
    {
        $this->assertSame(['pca', 'pra', 'pci', 'pri'], Rules::TYPES);
        $this->assertTrue(Rules::isType('pri'));
        $this->assertFalse(Rules::isType('xyz'));
        $this->assertFalse(Rules::isItPlan(Rules::TYPE_PCA));
        $this->assertFalse(Rules::isItPlan(Rules::TYPE_PRA));
        $this->assertTrue(Rules::isItPlan(Rules::TYPE_PCI));
        $this->assertTrue(Rules::isItPlan(Rules::TYPE_PRI));
    }

    public function testReviewPeriodAndNextReviewDate(): void
    {
        $this->assertSame(0, Rules::sanitizeReviewMonths('abc'));
        $this->assertSame(12, Rules::sanitizeReviewMonths(-3, false));
        $this->assertSame(60, Rules::sanitizeReviewMonths(240));
        $this->assertSame(6, Rules::effectiveReviewMonths(6, 12));
        $this->assertSame(24, Rules::effectiveReviewMonths(0, 24));
        $this->assertSame('2027-03-15', Rules::nextReviewDate('2026-03-15', 12));
        $this->assertSame('2026-09-15', Rules::nextReviewDate('2026-03-15 10:00:00', 6));
        $this->assertNull(Rules::nextReviewDate('', 12));
        $this->assertSame(30, Rules::sanitizeReminderDays('x'));
        $this->assertSame(0, Rules::sanitizeReminderDays(-5));
        $this->assertSame(365, Rules::sanitizeReminderDays(1000));
    }

    public function testReviewStatus(): void
    {
        $this->assertSame(Rules::REVIEW_NONE, Rules::reviewStatus(null, '2026-06-01', 30));
        $this->assertSame(Rules::REVIEW_OVERDUE, Rules::reviewStatus('2026-05-31', '2026-06-01', 30));
        $this->assertSame(Rules::REVIEW_SOON, Rules::reviewStatus('2026-06-20', '2026-06-01', 30));
        $this->assertSame(Rules::REVIEW_SOON, Rules::reviewStatus('2026-06-01', '2026-06-01', 0));
        $this->assertSame(Rules::REVIEW_OK, Rules::reviewStatus('2026-09-01', '2026-06-01', 30));
    }

    public function testLastTestAndTestStatus(): void
    {
        $tests = [
            ['id' => 1, 'test_date' => '2025-02-01', 'result' => 'passed'],
            ['id' => 3, 'test_date' => '2025-11-10', 'result' => 'failed'],
            ['id' => 2, 'test_date' => '2025-11-10', 'result' => 'partial'],
            ['id' => 4, 'test_date' => 'not a date', 'result' => 'passed'],
        ];
        $last = Rules::lastTest($tests);
        $this->assertNotNull($last);
        $this->assertSame(3, $last['id']);
        $this->assertNull(Rules::lastTest([]));
        $this->assertSame(CrisisScenarioRules::EXERCISE_NEVER, Rules::testStatus(null, '2026-06-01'));
        $this->assertSame(CrisisScenarioRules::EXERCISE_OK, Rules::testStatus('2025-11-10', '2026-06-01'));
        $this->assertSame(CrisisScenarioRules::EXERCISE_OVERDUE, Rules::testStatus('2025-02-01', '2026-06-01'));
        $this->assertSame(Rules::RESULT_PARTIAL, Rules::normalizeResult('unknown'));
    }

    public function testContentIsLockedDuringAndAfterApproval(): void
    {
        $this->assertFalse(Rules::isContentLocked(Rules::STATUS_DRAFT));
        $this->assertTrue(Rules::isContentLocked(Rules::STATUS_PENDING));
        $this->assertTrue(Rules::isContentLocked(Rules::STATUS_APPROVED));
        $current = [
            'name' => 'Recovery of the data centre', 'procedures' => "1. Restore\r\n2. Check", 'comment' => 'x',
        ];
        $this->assertSame([], Rules::changedContentFields($current, [
            'name' => 'Recovery of the data centre ', 'procedures' => "1. Restore\n2. Check", 'comment' => 'changed',
        ]), 'Espaces, fins de ligne et commentaire ne comptent pas.');
        $this->assertSame(['procedures'], Rules::changedContentFields($current, ['procedures' => '1. Restore']));
    }

    public function testFingerprintIsCanonical(): void
    {
        $a = ['name' => 'Plan', 'processes' => [
            ['name' => 'Payroll', 'rto' => '4 h'], ['name' => 'Orders', 'rto' => '1 h'],
        ]];
        $b = ['processes' => [
            ['rto' => '1 h', 'name' => 'Orders'], ['name' => 'Payroll', 'rto' => '4 h'],
        ], 'name' => 'Plan '];
        $this->assertSame(Rules::fingerprint($a), Rules::fingerprint($b));
        $this->assertSame(64, strlen(Rules::fingerprint($a)));
        $this->assertNotSame(Rules::fingerprint($a), Rules::fingerprint(['name' => 'Other plan'] + $a));
    }

    public function testGapsOfAPlan(): void
    {
        $complete = ['status' => 'approved', 'groups_id_crisis' => 4, 'procedures' => '1. Restore'];
        $ok = [
            'test_status' => CrisisScenarioRules::EXERCISE_OK, 'last_result' => 'passed',
            'review_status' => Rules::REVIEW_OK, 'process_count' => 2, 'person_left' => false,
        ];
        $this->assertSame([], Rules::gaps($complete, $ok));

        $this->assertSame([
            Rules::GAP_NOT_APPROVED, Rules::GAP_REVIEW_OVERDUE, Rules::GAP_NEVER_TESTED, Rules::GAP_NO_PROCESS,
            Rules::GAP_NO_CRISIS_TEAM, Rules::GAP_NO_PROCEDURE, Rules::GAP_PERSON_LEFT,
        ], Rules::gaps(['status' => 'draft'], [
            'test_status' => CrisisScenarioRules::EXERCISE_NEVER, 'last_result' => '',
            'review_status' => Rules::REVIEW_OVERDUE, 'process_count' => 0, 'person_left' => true,
        ]));

        // Nouvelle version en préparation : la version approuvée précédente reste en vigueur.
        $this->assertNotContains(
            Rules::GAP_NOT_APPROVED,
            Rules::gaps(['status' => 'draft', 'date_approved' => '2026-01-10'] + $complete, $ok)
        );
        $this->assertSame(
            [Rules::GAP_TEST_OVERDUE, Rules::GAP_LAST_TEST_FAILED],
            Rules::gaps(
                $complete,
                ['test_status' => CrisisScenarioRules::EXERCISE_OVERDUE, 'last_result' => 'failed'] + $ok
            )
        );
    }

    public function testSummaryCountsIndicators(): void
    {
        $plans = [
            ['gaps' => []],
            ['gaps' => [Rules::GAP_NOT_APPROVED, Rules::GAP_NEVER_TESTED]],
            ['gaps' => [Rules::GAP_REVIEW_OVERDUE, Rules::GAP_TEST_OVERDUE]],
        ];
        $processes = [
            ['id' => 1, 'rto_hours' => 4],
            ['id' => 2, 'rto_hours' => 8],
            ['id' => 3, 'rto_hours' => 72],
        ];
        $summary = Rules::summary($plans, $processes, [1 => 2]);
        $this->assertSame([
            'plans' => 3, 'not_approved' => 1, 'review_overdue' => 1, 'untested' => 2,
            'critical_uncovered' => 1, 'approved' => 2, 'tested' => 1,
        ], $summary);
    }
}
