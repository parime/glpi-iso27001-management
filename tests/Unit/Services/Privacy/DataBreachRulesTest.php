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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Privacy;

use GlpiPlugin\Grcmanager\Services\Privacy\DataBreachRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #620 : registre des violations de données personnelles (RGPD, articles 33 et 34).
 */
final class DataBreachRulesTest extends TestCase
{
    public function testDeadlineIsSeventyTwoHoursAfterAwareness(): void
    {
        $this->assertSame('2026-03-13 09:30:00', Rules::deadline('2026-03-10 09:30:00'));
        $this->assertFalse(Rules::isLate('2026-03-10 09:30:00', '2026-03-13 09:30:00'), 'Pile à 72 h : à temps.');
        $this->assertTrue(Rules::isLate('2026-03-10 09:30:00', '2026-03-13 09:31:00'));
        $this->assertSame(72, Rules::hoursLeft('2026-03-10 09:30:00', '2026-03-10 09:30:00'));
        $this->assertSame(1, Rules::hoursLeft('2026-03-10 09:30:00', '2026-03-13 08:00:00'));
        $this->assertSame(-2, Rules::hoursLeft('2026-03-10 09:30:00', '2026-03-13 10:45:00'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function states(): array
    {
        $known = ['date_awareness' => '2026-03-10 09:30:00'];

        return [
            'en attente, loin de l\'échéance' => [$known, '2026-03-10 12:00:00', Rules::STATE_PENDING],
            'en attente, moins de 24 h'      => [$known, '2026-03-12 10:00:00', Rules::STATE_SOON],
            'en attente, 72 h dépassées'     => [$known, '2026-03-14 10:00:00', Rules::STATE_OVERDUE],
            'notifiée à temps'               => [
                $known + ['authority_decision' => 'notified', 'date_notified' => '2026-03-12 16:00'],
                '2026-04-01 00:00:00',
                Rules::STATE_NOTIFIED,
            ],
            'notifiée en retard'             => [
                $known + ['authority_decision' => 'notified', 'date_notified' => '2026-03-16T09:00'],
                '2026-04-01 00:00:00',
                Rules::STATE_NOTIFIED_LATE,
            ],
            'non notifiée'                   => [
                $known + ['authority_decision' => 'not_notified'],
                '2026-04-01 00:00:00',
                Rules::STATE_NOT_NOTIFIED,
            ],
            'décision inconnue : en attente' => [
                $known + ['authority_decision' => 'peut-etre'],
                '2026-03-10 12:00:00',
                Rules::STATE_PENDING,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('states')]
    public function testState(array $fields, string $now, string $expected): void
    {
        $this->assertSame($expected, Rules::state($fields, $now));
    }

    public function testDecisionNotToNotifyNeedsAReason(): void
    {
        $fields = ['date_awareness' => '2026-03-10 09:30', 'authority_decision' => 'not_notified'];
        $this->assertSame([Rules::PROBLEM_NO_AUTHORITY_REASON], Rules::problems($fields));
        $this->assertSame(
            [Rules::PROBLEM_NO_AUTHORITY_REASON],
            Rules::problems($fields + ['authority_reason' => "  \n "]),
            'Des espaces ne sont pas une justification.'
        );
        $this->assertSame([], Rules::problems($fields + [
            'authority_reason' => 'Fichier de paie fictif supprimé par le destinataire interne, risque improbable.',
        ]));
    }

    public function testNotificationRules(): void
    {
        $base = ['date_awareness' => '2026-03-10 09:30', 'authority_decision' => 'notified'];
        $this->assertSame([Rules::PROBLEM_NO_NOTIFICATION_DATE], Rules::problems($base));
        $this->assertSame(
            [Rules::PROBLEM_NOTIFIED_BEFORE_AWARENESS],
            Rules::problems($base + ['date_notified' => '2026-03-09 10:00'])
        );
        $this->assertSame([], Rules::problems($base + ['date_notified' => '2026-03-13 09:30']));
        $late = $base + ['date_notified' => '2026-03-15 10:00'];
        $this->assertSame([Rules::PROBLEM_NO_DELAY_REASON], Rules::problems($late));
        $this->assertSame([], Rules::problems($late + ['delay_reason' => 'Ampleur connue le 14 mars seulement.']));
    }

    public function testAwarenessAndSubjectsRules(): void
    {
        $this->assertSame([Rules::PROBLEM_NO_AWARENESS], Rules::problems([]));
        $this->assertSame([Rules::PROBLEM_NO_AWARENESS], Rules::problems(['date_awareness' => 'hier']));
        $fields = ['date_awareness' => '2026-03-10', 'subjects_decision' => 'not_informed'];
        $this->assertSame([Rules::PROBLEM_NO_SUBJECTS_REASON], Rules::problems($fields));
        $this->assertSame([], Rules::problems($fields + ['subjects_reason' => 'Données chiffrées (article 34.3.a).']));
    }

    public function testChecksPointAtGapsWithoutBlocking(): void
    {
        $this->assertEqualsCanonicalizing(
            [Rules::CHECK_NO_NATURE, Rules::CHECK_NO_COUNTS, Rules::CHECK_NO_RISK, Rules::CHECK_NO_MEASURES],
            Rules::checks([])
        );
        $complete = [
            'nature' => '["confidentiality"]', 'subjects_count' => 40, 'risk_level' => 'unlikely',
            'measures_taken' => 'Suppression confirmée par écrit.', 'authority_decision' => 'not_notified',
        ];
        $this->assertSame([], Rules::checks($complete));
        $this->assertSame(
            [Rules::CHECK_NOT_NOTIFIED_DESPITE_RISK, Rules::CHECK_HIGH_RISK_SUBJECTS_PENDING],
            Rules::checks(['risk_level' => 'high'] + $complete)
        );
    }

    public function testNormalisation(): void
    {
        $this->assertSame('2026-03-10 09:30:00', Rules::normalizeDateTime('2026-03-10T09:30'));
        $this->assertSame('2026-03-10 00:00:00', Rules::normalizeDateTime('2026-03-10'));
        $this->assertNull(Rules::normalizeDateTime('2026-02-30 10:00'));
        $this->assertNull(Rules::normalizeDateTime(null));
        $this->assertSame('2026-03-10', Rules::normalizeDate('2026-03-10 09:30:00'));
        $this->assertSame(1200, Rules::normalizeCount('1 200'));
        $this->assertSame(40, Rules::normalizeCount('40'));
        $this->assertNull(Rules::normalizeCount(''));
        $this->assertNull(Rules::normalizeCount('-3'));
        $this->assertNull(Rules::normalizeCount('environ 40'));
        $this->assertSame('', Rules::normalizeRiskLevel('extrême'));
        $this->assertSame('pending', Rules::normalizeAuthorityDecision(null));
        $this->assertSame('not_informed', Rules::normalizeSubjectsDecision('not_informed'));
    }
}
