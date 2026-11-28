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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\TreatmentEffectivenessRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1182 : efficacité du traitement (risque cible atteint une fois le plan terminé),
 * séparation des tâches et expiration de l'acceptation.
 */
final class TreatmentEffectivenessRulesTest extends TestCase
{
    public function testTargetIsAKnownLevelOrEmpty(): void
    {
        self::assertSame('medium', Rules::normalizeTarget('medium'));
        self::assertSame('', Rules::normalizeTarget('extreme'));
        self::assertSame('', Rules::normalizeTarget(['low']));
        self::assertSame('', Rules::normalizeTarget(null));
    }

    public function testPlanProgress(): void
    {
        self::assertSame(['total' => 0, 'done' => 0, 'completed' => false], Rules::planProgress([]));
        self::assertSame(['total' => 2, 'done' => 1, 'completed' => false], Rules::planProgress(['done', 'planned']));
        self::assertSame(['total' => 2, 'done' => 2, 'completed' => true], Rules::planProgress(['done', 'done']));
    }

    public function testStateWithoutTargetOrPlan(): void
    {
        self::assertSame(Rules::NO_TARGET, Rules::state('', 'high', 'mitigate', 2, 2));
        self::assertSame(Rules::NO_PLAN, Rules::state('low', 'high', 'accept', 2, 2));
        self::assertSame(Rules::NO_PLAN, Rules::state('low', 'high', 'mitigate', 0, 0));
        self::assertSame(Rules::PLAN_OPEN, Rules::state('low', 'high', 'transfer', 3, 2));
    }

    public function testCompletedPlanIsCheckedAgainstTheTarget(): void
    {
        self::assertSame(Rules::REACHED, Rules::state('medium', 'medium', 'mitigate', 2, 2));
        self::assertSame(Rules::REACHED, Rules::state('medium', 'low', 'mitigate', 2, 2));
        self::assertSame(Rules::NOT_REACHED, Rules::state('medium', 'high', 'mitigate', 2, 2));
        self::assertSame(Rules::NOT_ASSESSED, Rules::state('medium', '', 'mitigate', 2, 2));
        self::assertTrue(Rules::isAlert(Rules::NOT_REACHED));
        self::assertTrue(Rules::isAlert(Rules::NOT_ASSESSED));
        self::assertFalse(Rules::isAlert(Rules::REACHED));
        self::assertFalse(Rules::isAlert(Rules::PLAN_OPEN));
    }

    public function testTheOwnerNeverSignsTheAcceptance(): void
    {
        self::assertFalse(Rules::isSegregationRespected(7, 7));
        self::assertFalse(Rules::isSegregationRespected(0, 7));
        self::assertFalse(Rules::isSegregationRespected(0, 0));
        self::assertTrue(Rules::isSegregationRespected(7, 8));
        self::assertTrue(Rules::isSegregationRespected(7, 0));
    }

    public function testExpiryIsAValidFutureDate(): void
    {
        self::assertSame('2027-03-01', Rules::normalizeExpiry('2027-03-01', '2026-11-28'));
        self::assertNull(Rules::normalizeExpiry('2026-11-28', '2026-11-28'));
        self::assertNull(Rules::normalizeExpiry('2026-02-30', '2025-01-01'));
        self::assertNull(Rules::normalizeExpiry('01/03/2027', '2026-11-28'));
        self::assertNull(Rules::normalizeExpiry(null, '2026-11-28'));
        self::assertSame('2027-11-28', Rules::defaultExpiry('2026-11-28'));
    }

    public function testAcceptanceState(): void
    {
        $today = '2026-11-28';
        self::assertSame(Rules::ACCEPTANCE_NONE, Rules::acceptanceState(false, '2027-01-01', $today));
        self::assertSame(Rules::ACCEPTANCE_NO_EXPIRY, Rules::acceptanceState(true, null, $today));
        self::assertSame(Rules::ACCEPTANCE_EXPIRED, Rules::acceptanceState(true, '2026-11-27', $today));
        self::assertSame(Rules::ACCEPTANCE_EXPIRING, Rules::acceptanceState(true, '2026-11-28', $today));
        self::assertSame(Rules::ACCEPTANCE_EXPIRING, Rules::acceptanceState(true, '2026-12-28', $today));
        self::assertSame(Rules::ACCEPTANCE_VALID, Rules::acceptanceState(true, '2026-12-29', $today));
        self::assertTrue(Rules::needsRenewal(Rules::ACCEPTANCE_EXPIRED));
        self::assertTrue(Rules::needsRenewal(Rules::ACCEPTANCE_EXPIRING));
        self::assertFalse(Rules::needsRenewal(Rules::ACCEPTANCE_VALID));
        self::assertFalse(Rules::needsRenewal(Rules::ACCEPTANCE_NO_EXPIRY));
    }
}
