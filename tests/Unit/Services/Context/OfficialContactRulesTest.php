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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Context;

use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #771 : règles du registre des contacts officiels (A.5.5, A.5.6).
 */
final class OfficialContactRulesTest extends TestCase
{
    public function testCategoryFallsBackToOther(): void
    {
        $this->assertSame('csirt', Rules::normalizeCategory('csirt'));
        $this->assertSame('other', Rules::normalizeCategory('<script>'));
        $this->assertSame('other', Rules::normalizeCategory(['csirt']));
        $this->assertSame('other', Rules::normalizeCategory(null));
    }

    public function testControlsAndTheirCategories(): void
    {
        $this->assertContains('supervisory_authority', Rules::categoriesForControl('A.5.5') ?? []);
        $this->assertNotContains('interest_group', Rules::categoriesForControl('A.5.5') ?? []);
        $this->assertSame(['interest_group'], Rules::categoriesForControl('A.5.6'));
        $this->assertNull(Rules::categoriesForControl('A.5.7'));
        foreach (Rules::CONTROL_CATEGORIES as $categories) {
            foreach ($categories as $category) {
                $this->assertContains($category, Rules::CATEGORIES);
            }
        }
    }

    public function testVerificationStatusAfterTwelveMonths(): void
    {
        $this->assertSame(Rules::STATUS_NEVER, Rules::verificationStatus(null, '2026-11-20'));
        $this->assertSame(Rules::STATUS_NEVER, Rules::verificationStatus('', '2026-11-20'));
        $this->assertSame(Rules::STATUS_NEVER, Rules::verificationStatus('0000-00-00', '2026-11-20'));
        $this->assertSame(Rules::STATUS_OK, Rules::verificationStatus('2026-11-20', '2026-11-20'));
        $exactlyOneYear = Rules::verificationStatus('2025-11-20', '2026-11-20');
        $this->assertSame(Rules::STATUS_OK, $exactlyOneYear, 'Exactement 12 mois : encore à jour.');
        $this->assertSame(Rules::STATUS_OVERDUE, Rules::verificationStatus('2025-11-19', '2026-11-20'));
        $this->assertSame(Rules::STATUS_OVERDUE, Rules::verificationStatus('2020-01-01 00:00:00', '2026-11-20'));
    }

    public function testNextVerificationDate(): void
    {
        $this->assertNull(Rules::nextVerificationDate(null));
        $this->assertSame('2027-11-20', Rules::nextVerificationDate('2026-11-20'));
    }

    public function testReminderAtMostOnceAMonth(): void
    {
        $today = '2026-11-20';
        $this->assertFalse(Rules::shouldRemind('2026-06-01', null, $today), 'Contact à jour : pas de relance.');
        $this->assertTrue(Rules::shouldRemind(null, null, $today), 'Jamais vérifié, jamais relancé.');
        $this->assertTrue(Rules::shouldRemind('2025-01-01', null, $today));
        $late = '2025-01-01';
        $this->assertFalse(Rules::shouldRemind($late, '2026-11-05 08:00:00', $today), 'Relancé il y a 15 jours.');
        $this->assertTrue(Rules::shouldRemind($late, '2026-10-21 08:00:00', $today), 'Relancé il y a 30 jours.');
    }

    public function testConcernsOnlyOfficialContactsFlaggedForTheEvent(): void
    {
        $row = ['is_official_contact' => 1, 'on_security_incident' => 1, 'on_data_breach' => 0];
        $this->assertTrue(Rules::concerns($row, Rules::CONTEXT_INCIDENT));
        $this->assertFalse(Rules::concerns($row, Rules::CONTEXT_BREACH));
        $this->assertFalse(Rules::concerns(['is_official_contact' => 0] + $row, Rules::CONTEXT_INCIDENT));
        $this->assertFalse(Rules::concerns($row, 'other'));
    }

    public function testIncidentCategoriesAreNormalized(): void
    {
        $this->assertSame(['malware', 'availability'], Rules::normalizeIncidentCategories(['availability', 'malware']));
        $this->assertSame(['malware'], Rules::normalizeIncidentCategories('["malware","<script>"]'));
        $this->assertSame([], Rules::normalizeIncidentCategories(''));
        $this->assertSame([], Rules::normalizeIncidentCategories('not json'));
        $this->assertSame([], Rules::normalizeIncidentCategories(42));
        $this->assertSame('', Rules::encodeIncidentCategories([]));
        $this->assertSame('["data_breach"]', Rules::encodeIncidentCategories(['data_breach', 'data_breach']));
    }

    public function testContactsAreProposedByIncidentCategory(): void
    {
        $base = ['is_official_contact' => 1, 'on_security_incident' => 1, 'on_data_breach' => 0];
        $malware = $base + ['contact_incident_categories' => '["malware"]'];
        $this->assertTrue(Rules::concerns($malware, Rules::CONTEXT_INCIDENT, 'malware'));
        $this->assertFalse(Rules::concerns($malware, Rules::CONTEXT_INCIDENT, 'availability'));
        $this->assertTrue(Rules::concerns($malware, Rules::CONTEXT_INCIDENT), 'Incident sans catégorie.');
        $this->assertTrue(Rules::concerns($base, Rules::CONTEXT_INCIDENT, 'availability'), 'Aucune catégorie : tous.');

        $breach = ['is_official_contact' => 1, 'on_security_incident' => 0, 'on_data_breach' => 1];
        $this->assertTrue(Rules::concerns($breach, Rules::CONTEXT_INCIDENT, Rules::INCIDENT_CATEGORY_DATA_BREACH));
        $this->assertFalse(Rules::concerns($breach, Rules::CONTEXT_INCIDENT, 'malware'));
        $this->assertTrue(Rules::concerns($breach, Rules::CONTEXT_BREACH));
    }

    public function testSummary(): void
    {
        $summary = Rules::summary([
            ['contact_verified_date' => '2026-10-01'],
            ['contact_verified_date' => '2024-10-01'],
            ['contact_verified_date' => null],
        ], '2026-11-20');
        $this->assertSame(['total' => 3, 'verified' => 1, 'to_verify' => 2], $summary);
    }

    public function testDelayHoursAreBounded(): void
    {
        $this->assertSame(72, Rules::normalizeDelayHours('72'));
        $this->assertSame(0, Rules::normalizeDelayHours('-5'));
        $this->assertSame(0, Rules::normalizeDelayHours('72 h'));
        $this->assertSame(0, Rules::normalizeDelayHours(['72']));
        $this->assertSame(Rules::MAX_DELAY_HOURS, Rules::normalizeDelayHours(999999));
    }

    public function testDateTimeAndDecisionNormalization(): void
    {
        $this->assertSame('2026-03-10 09:30:00', Rules::normalizeDateTime('2026-03-10T09:30'));
        $this->assertSame('2026-03-10 09:30:15', Rules::normalizeDateTime('2026-03-10 09:30:15'));
        $this->assertNull(Rules::normalizeDateTime('2026-02-30 09:30'), 'Date impossible.');
        $this->assertNull(Rules::normalizeDateTime('demain'));
        $this->assertNull(Rules::normalizeDateTime(null));
        $this->assertSame(Rules::DECISION_NOTIFIED, Rules::normalizeDecision('notified'));
        $this->assertSame('', Rules::normalizeDecision('<b>'));
    }

    public function testDeadlineFromTheStartOfTheEvent(): void
    {
        $this->assertSame('2026-03-13 09:30:00', Rules::deadline('2026-03-10 09:30:00', 72));
        $this->assertNull(Rules::deadline('2026-03-10 09:30:00', 0), 'Pas de délai chiffré.');
        $this->assertNull(Rules::deadline(null, 72), 'Pas de date de début.');
    }

    public function testTraceRefusals(): void
    {
        $now = '2026-11-20 10:00:00';
        $start = '2026-11-18 08:00:00';
        $this->assertNull(Rules::traceRefusal(Rules::DECISION_NOTIFIED, '2026-11-19 10:00:00', '', $start, $now));
        $this->assertSame(Rules::REFUSED_DECISION, Rules::traceRefusal('', '2026-11-19 10:00:00', '', $start, $now));
        $this->assertSame(
            Rules::REFUSED_REASON,
            Rules::traceRefusal(Rules::DECISION_NOT_REQUIRED, '2026-11-19 10:00:00', '  ', $start, $now),
            'Une décision de ne pas prévenir doit être motivée.'
        );
        $this->assertSame(Rules::REFUSED_DATE, Rules::traceRefusal(Rules::DECISION_NOTIFIED, null, '', $start, $now));
        $this->assertSame(
            Rules::REFUSED_FUTURE,
            Rules::traceRefusal(Rules::DECISION_NOTIFIED, '2026-11-21 10:00:00', '', $start, $now)
        );
        $this->assertSame(
            Rules::REFUSED_BEFORE_START,
            Rules::traceRefusal(Rules::DECISION_NOTIFIED, '2026-11-17 10:00:00', '', $start, $now)
        );
    }

    public function testNoticeStateOnTimeLateDecisionAndOverdue(): void
    {
        $start = '2026-11-10 08:00:00';
        $notified = static fn (string $date): array => [
            'decision'      => Rules::DECISION_NOTIFIED,
            'date_notified' => $date,
        ];

        $onTime = Rules::noticeState([$notified('2026-11-12 08:00:00')], $start, 72, '2026-11-20 10:00:00');
        $this->assertSame(Rules::NOTICE_ON_TIME, $onTime['state']);
        $this->assertSame('2026-11-13 08:00:00', $onTime['deadline']);

        // Première prévenance retenue : un complément tardif ne rend pas la notification tardive.
        $traces = [$notified('2026-11-19 08:00:00'), $notified('2026-11-12 08:00:00')];
        $this->assertSame(Rules::NOTICE_ON_TIME, Rules::noticeState($traces, $start, 72, '2026-11-20')['state']);

        $late = Rules::noticeState([$notified('2026-11-14 08:00:00')], $start, 72, '2026-11-20 10:00:00');
        $this->assertSame(Rules::NOTICE_LATE, $late['state']);

        $decision = [['decision' => Rules::DECISION_NOT_REQUIRED, 'date_notified' => '2026-11-11 08:00:00']];
        $this->assertSame(Rules::NOTICE_NOT_REQUIRED, Rules::noticeState($decision, $start, 72, '2026-11-20')['state']);

        $this->assertSame(Rules::NOTICE_PENDING, Rules::noticeState([], $start, 72, '2026-11-11 08:00:00')['state']);
        $this->assertSame(Rules::NOTICE_OVERDUE, Rules::noticeState([], $start, 72, '2026-11-20 10:00:00')['state']);
        $this->assertSame(
            Rules::NOTICE_PENDING,
            Rules::noticeState([], $start, 0, '2027-01-01 00:00:00')['state'],
            'Sans délai chiffré, jamais « échéance dépassée ».'
        );
    }
}
