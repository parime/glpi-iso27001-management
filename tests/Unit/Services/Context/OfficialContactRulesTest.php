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

    public function testSummary(): void
    {
        $summary = Rules::summary([
            ['contact_verified_date' => '2026-10-01'],
            ['contact_verified_date' => '2024-10-01'],
            ['contact_verified_date' => null],
        ], '2026-11-20');
        $this->assertSame(['total' => 3, 'verified' => 1, 'to_verify' => 2], $summary);
    }
}
