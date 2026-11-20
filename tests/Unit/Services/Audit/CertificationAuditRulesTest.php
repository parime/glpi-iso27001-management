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

use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as Rules;
use GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService;
use PHPUnit\Framework\TestCase;

/**
 * Issue #772 : type d'audit, vocabulaire des constats de l'organisme de certification et état de
 * levée.
 */
final class CertificationAuditRulesTest extends TestCase
{
    public function testAuditTypes(): void
    {
        self::assertSame('internal', Rules::normalizeAuditType(''), 'audit existant ou type vide');
        self::assertSame('internal', Rules::normalizeAuditType('external'), 'type inconnu');
        self::assertSame('surveillance_2', Rules::normalizeAuditType('surveillance_2'));
        self::assertFalse(Rules::isExternal('internal'));
        self::assertFalse(Rules::isExternal('autre'));
        self::assertTrue(Rules::isExternal('initial'));
        self::assertSame(['initial', 'surveillance_1', 'surveillance_2', 'renewal'], Rules::externalTypes());
    }

    public function testCategoriesRoundTripThroughTheExistingAxes(): void
    {
        foreach (Rules::CATEGORIES as $category) {
            $fields = Rules::fieldsFor($category);
            self::assertNotNull($fields, $category);
            self::assertSame($category, Rules::categoryOf($fields['finding_type'], $fields['severity']), $category);
        }
        self::assertNull(Rules::fieldsFor('critical'));
        self::assertNull(Rules::fieldsFor(''));
    }

    public function testExistingFindingsMapToTheBodyVocabulary(): void
    {
        self::assertSame(Rules::CATEGORY_MINOR_NC, Rules::categoryOf('nonconformity', 'minor'));
        self::assertSame(Rules::CATEGORY_MAJOR_NC, Rules::categoryOf('nonconformity', 'major'));
        self::assertSame(Rules::CATEGORY_MAJOR_NC, Rules::categoryOf('nonconformity', 'critical'));
        self::assertSame(Rules::CATEGORY_MAJOR_NC, Rules::categoryOf('', ''), 'valeur inconnue : prudence');
        self::assertNull(Rules::categoryOf('observation', 'minor'), 'observation interne : sans équivalent');
    }

    public function testCapaStaysMandatoryOnlyForANonconformity(): void
    {
        foreach ([Rules::CATEGORY_MAJOR_NC, Rules::CATEGORY_MINOR_NC] as $category) {
            $fields = Rules::fieldsFor($category);
            self::assertNotNull($fields);
            self::assertTrue(CapaRequirementService::isCapaMandatory($fields['finding_type']), $category);
        }
        self::assertFalse(CapaRequirementService::isCapaMandatory(Rules::FINDING_TYPE_SENSITIVE_POINT));
        self::assertFalse(CapaRequirementService::isCapaMandatory(Rules::FINDING_TYPE_IMPROVEMENT));
    }

    public function testLiftState(): void
    {
        self::assertSame(Rules::LIFT_NOT_LIFTED, Rules::liftState('open'));
        self::assertSame(Rules::LIFT_NOT_LIFTED, Rules::liftState(''));
        self::assertSame(Rules::LIFT_IN_PROGRESS, Rules::liftState('in_progress'));
        self::assertSame(Rules::LIFT_AWAITING_VERIFICATION, Rules::liftState('closed'));
        self::assertSame(Rules::LIFT_LIFTED, Rules::liftState('verified'));
        self::assertTrue(Rules::isOpen('open'));
        self::assertTrue(Rules::isOpen('in_progress'));
        self::assertFalse(Rules::isOpen('closed'));
        self::assertFalse(Rules::isOpen('verified'));
    }

    public function testSummary(): void
    {
        $summary = Rules::summary([
            ['lift_state' => Rules::LIFT_NOT_LIFTED, 'category' => Rules::CATEGORY_MAJOR_NC],
            ['lift_state' => Rules::LIFT_IN_PROGRESS, 'category' => Rules::CATEGORY_SENSITIVE_POINT],
            ['lift_state' => Rules::LIFT_AWAITING_VERIFICATION, 'category' => Rules::CATEGORY_MINOR_NC],
            ['lift_state' => Rules::LIFT_LIFTED, 'category' => Rules::CATEGORY_MINOR_NC],
            ['lift_state' => 'autre', 'category' => null],
        ]);

        self::assertSame(5, $summary['total']);
        self::assertSame(3, $summary['open']);
        self::assertSame(1, $summary['open_nc']);
        self::assertSame(2, $summary['lift'][Rules::LIFT_NOT_LIFTED]);
        self::assertSame(1, $summary['lift'][Rules::LIFT_LIFTED]);
        self::assertSame(2, $summary['categories'][Rules::CATEGORY_MINOR_NC]);
        self::assertSame(0, $summary['categories'][Rules::CATEGORY_IMPROVEMENT]);
        self::assertSame(0, Rules::summary([])['total']);
    }
}
