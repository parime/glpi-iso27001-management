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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Audit;

use GlpiPlugin\Grcmanager\Services\Audit\BodyResponseRules as Rules;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as Audit;
use PHPUnit\Framework\TestCase;

/**
 * Issue #772, lot 3 : réponse aux constats de l'organisme de certification (plan d'actions,
 * preuves de levée, délais et décision de l'organisme).
 */
final class BodyResponseRulesTest extends TestCase
{
    private const TODAY = '2026-11-20';

    public function testDatesAndDecisionsAreNormalized(): void
    {
        self::assertNull(Rules::normalizeDate(''));
        self::assertNull(Rules::normalizeDate(null));
        self::assertNull(Rules::normalizeDate('0000-00-00'));
        self::assertNull(Rules::normalizeDate('2026-02-30'));
        self::assertSame('2026-12-01', Rules::normalizeDate('2026-12-01 00:00:00'));
        self::assertSame('', Rules::normalizeDecision('approuve'), 'valeur inconnue : en attente');
        self::assertSame('accepted', Rules::normalizeDecision('accepted'));
        self::assertSame('to_complete', Rules::normalizeDecision('to_complete'));
    }

    public function testDaysLeft(): void
    {
        self::assertNull(Rules::daysLeft(null, self::TODAY));
        self::assertSame(0, Rules::daysLeft(self::TODAY, self::TODAY));
        self::assertSame(12, Rules::daysLeft('2026-12-02', self::TODAY));
        self::assertSame(-5, Rules::daysLeft('2026-11-15', self::TODAY));
    }

    public function testResponseIsExpectedForNonconformitiesOnly(): void
    {
        self::assertTrue(Rules::isResponseRequired(Audit::CATEGORY_MAJOR_NC));
        self::assertTrue(Rules::isResponseRequired(Audit::CATEGORY_MINOR_NC));
        self::assertFalse(Rules::isResponseRequired(Audit::CATEGORY_SENSITIVE_POINT));
        self::assertFalse(Rules::isResponseRequired(Audit::CATEGORY_IMPROVEMENT));
        self::assertFalse(Rules::isResponseRequired(null));

        self::assertSame(
            Rules::RESPONSE_NOT_REQUIRED,
            Rules::responseState(Audit::CATEGORY_SENSITIVE_POINT, null, '2026-11-01', '', self::TODAY),
            'point sensible : jamais en retard'
        );
        self::assertSame(
            Rules::RESPONSE_SENT,
            Rules::responseState(Audit::CATEGORY_IMPROVEMENT, '2026-11-10', null, '', self::TODAY),
            'réponse facultative mais envoyée'
        );
    }

    public function testResponseStateFollowsTheDeadlineAndTheDecision(): void
    {
        $nc = Audit::CATEGORY_MINOR_NC;
        self::assertSame(Rules::RESPONSE_TO_SEND, Rules::responseState($nc, null, null, '', self::TODAY), 'sans délai');
        self::assertSame(Rules::RESPONSE_TO_SEND, Rules::responseState($nc, null, '2026-12-31', '', self::TODAY));
        self::assertSame(Rules::RESPONSE_DUE_SOON, Rules::responseState($nc, null, '2026-11-27', '', self::TODAY));
        self::assertSame(Rules::RESPONSE_DUE_SOON, Rules::responseState($nc, null, self::TODAY, '', self::TODAY));
        self::assertSame(Rules::RESPONSE_OVERDUE, Rules::responseState($nc, null, '2026-11-19', '', self::TODAY));
        self::assertSame(Rules::RESPONSE_SENT, Rules::responseState($nc, '2026-11-18', '2026-11-19', '', self::TODAY));
        self::assertSame(
            Rules::RESPONSE_ACCEPTED,
            Rules::responseState($nc, '2026-11-18', '2026-11-19', 'accepted', self::TODAY)
        );
        self::assertSame(
            Rules::RESPONSE_TO_COMPLETE,
            Rules::responseState($nc, '2026-11-18', '2026-11-19', 'to_complete', self::TODAY)
        );
        self::assertSame(
            Rules::RESPONSE_OVERDUE,
            Rules::responseState($nc, null, '2026-11-19', 'accepted', self::TODAY),
            'une décision sans envoi ne vaut pas réponse'
        );
    }

    public function testEvidenceState(): void
    {
        $nc = Audit::CATEGORY_MAJOR_NC;
        self::assertSame(Rules::EVIDENCE_TO_SEND, Rules::evidenceState($nc, null, null, self::TODAY));
        self::assertSame(Rules::EVIDENCE_DUE_SOON, Rules::evidenceState($nc, null, '2026-11-25', self::TODAY));
        self::assertSame(Rules::EVIDENCE_OVERDUE, Rules::evidenceState($nc, null, '2026-10-01', self::TODAY));
        self::assertSame(Rules::EVIDENCE_SENT, Rules::evidenceState($nc, '2026-11-01', '2026-10-01', self::TODAY));
        self::assertSame(
            Rules::EVIDENCE_NOT_REQUIRED,
            Rules::evidenceState(Audit::CATEGORY_SENSITIVE_POINT, null, '2026-10-01', self::TODAY)
        );
    }

    public function testSummaryAndDeadlineState(): void
    {
        $summary = Rules::summary([
            ['response_state' => Rules::RESPONSE_OVERDUE, 'evidence_state' => Rules::EVIDENCE_TO_SEND],
            ['response_state' => Rules::RESPONSE_TO_COMPLETE, 'evidence_state' => Rules::EVIDENCE_OVERDUE],
            ['response_state' => Rules::RESPONSE_ACCEPTED, 'evidence_state' => Rules::EVIDENCE_SENT],
            ['response_state' => 'inconnu', 'evidence_state' => 'inconnu'],
        ]);
        self::assertSame(4, $summary['total']);
        self::assertSame(2, $summary['responses_to_send'], 'en retard et à compléter');
        self::assertSame(2, $summary['evidence_to_send']);
        self::assertSame(2, $summary['late']);
        self::assertSame(1, $summary['response'][Rules::RESPONSE_NOT_REQUIRED], 'état inconnu');

        self::assertSame('none', Rules::deadlineState(null, 3, self::TODAY));
        self::assertSame('done', Rules::deadlineState('2026-11-01', 0, self::TODAY), 'tout est envoyé');
        self::assertSame('overdue', Rules::deadlineState('2026-11-01', 1, self::TODAY));
        self::assertSame('soon', Rules::deadlineState('2026-11-25', 1, self::TODAY));
        self::assertSame('upcoming', Rules::deadlineState('2026-12-25', 1, self::TODAY));
    }
}
