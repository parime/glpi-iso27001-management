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

use GlpiPlugin\Grcmanager\Services\Audit\DuplicationRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #881 : préparer l'audit du cycle suivant à partir d'un audit passé.
 */
final class DuplicationRulesTest extends TestCase
{
    public function testPartsKeepKnownValuesInOrder(): void
    {
        self::assertSame(['scope', 'checklist'], Rules::parts(['checklist', 'unknown', 'scope', ['x']]));
        self::assertSame([], Rules::parts([]));
    }

    public function testCertificationCycleMovesToTheNextAudit(): void
    {
        self::assertSame('internal', Rules::nextAuditType('internal'));
        self::assertSame('internal', Rules::nextAuditType(''));
        self::assertSame('surveillance_1', Rules::nextAuditType('initial'));
        self::assertSame('surveillance_2', Rules::nextAuditType('surveillance_1'));
        self::assertSame('renewal', Rules::nextAuditType('surveillance_2'));
        self::assertSame('surveillance_1', Rules::nextAuditType('renewal'));
    }

    public function testPlannedDateIsOneYearLater(): void
    {
        self::assertSame('2027-03-15', Rules::defaultPlannedDate('2026-03-15', '2026-11-01'));
        self::assertSame('2027-11-01', Rules::defaultPlannedDate(null, '2026-11-01'));
        self::assertSame('2027-11-01', Rules::defaultPlannedDate('not a date', '2026-11-01'));
        self::assertNull(Rules::parseDate('2026-02-30'));
        self::assertSame('2026-02-28', Rules::parseDate('2026-02-28 10:00:00'));
    }

    public function testTitleTakesTheNewYear(): void
    {
        self::assertSame('Internal audit 2027', Rules::defaultTitle('Internal audit 2026', '2026-03-15', '2027-03-15'));
        self::assertSame('Internal audit - 2027', Rules::defaultTitle('Internal audit', '2026-03-15', '2027-03-15'));
        self::assertSame('Audit 2027 HR', Rules::defaultTitle('Audit 2027 HR', '2026-03-15', '2027-03-15'));
        self::assertSame('Audit A.5.20260 - 2027', Rules::defaultTitle('Audit A.5.20260', '2026-01-01', '2027-01-01'));
        self::assertSame(255, mb_strlen(Rules::defaultTitle(str_repeat('x', 300), null, '2027-01-01')));
        self::assertSame('', Rules::cleanTitle(['array']));
    }

    public function testDueDatesMoveLikeTheAudit(): void
    {
        self::assertSame('2027-02-15', Rules::shiftDate('2026-02-15', '2026-03-01', '2027-03-01'));
        self::assertSame('2026-01-31', Rules::shiftDate('2026-02-10', '2026-03-01', '2026-02-19'));
        self::assertNull(Rules::shiftDate(null, '2026-03-01', '2027-03-01'));
        self::assertNull(Rules::shiftDate('2026-02-15', null, '2027-03-01'));
    }

    public function testNewAuditStartsFromScratch(): void
    {
        $source = [
            'id' => 12, 'title' => 'Old', 'objectives' => 'Obj', 'scope' => 'Scope', 'criteria' => 'Crit',
            'audit_type' => 'internal', 'suppliers_id' => 0, 'lead_auditor' => '', 'certification_standard' => 'ISO',
            'entities_id' => 3, 'is_recursive' => 1, 'users_id' => 7, 'users_id_responsible' => 8,
            'risk_categories' => 'people', 'status' => 'completed', 'actual_date' => '2026-03-20',
            'conclusion' => 'Done', 'body_report_date' => '2026-04-01', 'planned_date' => '2026-03-15',
        ];
        $input = Rules::auditInput($source, ['scope', 'people'], ['title' => ' New ', 'planned_date' => '2027-03-15']);
        self::assertSame('New', $input['title']);
        self::assertSame('2027-03-15', $input['planned_date']);
        self::assertSame('planned', $input['status']);
        self::assertSame(12, $input[Rules::SOURCE_FIELD]);
        self::assertSame(7, $input['users_id']);
        self::assertSame('people', $input['risk_categories']);
        self::assertSame('Crit', $input['criteria']);
        self::assertSame(3, $input['entities_id']);
        foreach (['id', 'actual_date', 'conclusion', 'body_report_date'] as $field) {
            self::assertArrayNotHasKey($field, $input, $field);
        }

        $bare = Rules::auditInput($source, [], ['title' => 'New', 'planned_date' => 'bad']);
        self::assertNull($bare['planned_date']);
        self::assertArrayNotHasKey('users_id', $bare);
        self::assertArrayNotHasKey('risk_categories', $bare);
    }

    public function testCertificationBodyAuditChangesTypeAndBody(): void
    {
        $source = ['id' => 4, 'audit_type' => 'initial', 'suppliers_id' => 5, 'lead_auditor' => 'Old lead'];
        $input = Rules::auditInput($source, [], [
            'title' => 'S1', 'planned_date' => '2027-01-10', 'audit_type' => 'surveillance_1',
            'suppliers_id' => '9', 'lead_auditor' => 'New lead',
        ]);
        self::assertSame('surveillance_1', $input['audit_type']);
        self::assertSame(9, $input['suppliers_id']);
        self::assertSame('New lead', $input['lead_auditor']);

        $kept = Rules::auditInput($source, [], ['title' => 'S1', 'planned_date' => '2027-01-10', 'audit_type' => '']);
        self::assertSame('initial', $kept['audit_type']);
        self::assertSame(5, $kept['suppliers_id']);
    }
}
