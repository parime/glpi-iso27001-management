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

use GlpiPlugin\Grcmanager\Services\Privacy\DataSubjectRequestRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataSubjectRequestRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function dueDates(): array
    {
        return [
            'même jour le mois suivant'    => ['2026-03-15', false, '2026-04-15'],
            '31 janvier : fin février'     => ['2026-01-31', false, '2026-02-28'],
            'année bissextile'             => ['2028-01-31', false, '2028-02-29'],
            '31 mars : 30 avril'           => ['2026-03-31', false, '2026-04-30'],
            'changement d\'année'          => ['2026-12-15', false, '2027-01-15'],
            'prolongée : trois mois'       => ['2026-03-15', true, '2026-06-15'],
            'prolongée, fin de mois'       => ['2026-11-30', true, '2027-02-28'],
        ];
    }

    #[DataProvider('dueDates')]
    public function testDueDateKeepsTheDayOrTheEndOfTheMonth(string $received, bool $extended, string $due): void
    {
        self::assertSame($due, Rules::dueDate($received, $extended));
    }

    public function testInvalidDatesGiveNoDeadline(): void
    {
        self::assertNull(Rules::dueDate('pas une date', false));
        self::assertNull(Rules::dueDate('2026-02-31', false));
        self::assertNull(Rules::normalizeDate(null));
        self::assertSame('2026-03-01', Rules::normalizeDate('2026-03-01 10:00:00'));
    }

    public function testStateFollowsTheDeadline(): void
    {
        $due = '2026-06-15';
        self::assertSame(Rules::STATE_OK, Rules::state('received', $due, null, '2026-06-01'));
        self::assertSame(Rules::STATE_SOON, Rules::state('in_progress', $due, null, '2026-06-08'));
        // Le dernier jour compte encore.
        self::assertSame(Rules::STATE_SOON, Rules::state('in_progress', $due, null, $due));
        self::assertSame(Rules::STATE_OVERDUE, Rules::state('identity_check', $due, null, '2026-06-16'));
        self::assertSame(Rules::STATE_CLOSED, Rules::state('answered', $due, $due, '2026-07-01'));
        self::assertSame(Rules::STATE_LATE_ANSWER, Rules::state('answered', $due, '2026-06-16', '2026-07-01'));
        self::assertSame(Rules::STATE_CLOSED, Rules::state('refused', $due, '2026-06-01', '2026-07-01'));
        self::assertSame(Rules::STATE_OK, Rules::state('received', null, null, '2026-06-01'));
    }

    public function testDaysLeftAreSigned(): void
    {
        self::assertSame(3, Rules::daysLeft('2026-06-15', '2026-06-12'));
        self::assertSame(0, Rules::daysLeft('2026-06-15', '2026-06-15'));
        self::assertSame(-2, Rules::daysLeft('2026-06-15', '2026-06-17'));
    }

    public function testUnknownValuesFallBackToTheDefaults(): void
    {
        self::assertSame('erasure', Rules::normalizeType('erasure'));
        self::assertSame(Rules::DEFAULT_TYPE, Rules::normalizeType('inconnu'));
        self::assertSame(Rules::DEFAULT_STATUS, Rules::normalizeStatus(null));
    }

    public function testExtensionAndRefusalMustBeJustified(): void
    {
        self::assertSame([], Rules::problems(['status' => 'received']));
        self::assertSame(['extension_reason'], Rules::problems(['status' => 'received', 'is_extended' => 1]));
        self::assertSame([], Rules::problems([
            'status' => 'received', 'is_extended' => 1, 'extension_reason' => 'Demande complexe',
        ]));
        self::assertSame(['refusal_reason', 'date_answered'], Rules::problems(['status' => 'refused']));
        self::assertSame(['date_answered'], Rules::problems(['status' => 'answered']));
        self::assertSame([], Rules::problems(['status' => 'answered', 'date_answered' => '2026-06-01']));
    }
}
