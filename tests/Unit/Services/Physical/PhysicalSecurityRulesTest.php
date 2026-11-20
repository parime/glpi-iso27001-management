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

namespace GlpiPlugin\Grcmanager\Tests\Services\Physical;

use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #778 : règles de l'évaluation de la sécurité physique d'un site.
 */
final class PhysicalSecurityRulesTest extends TestCase
{
    public function testFourteenPhysicalControls(): void
    {
        $this->assertCount(14, Rules::CONTROL_CODES);
        $this->assertTrue(Rules::isControlCode('A.7.14'));
        $this->assertFalse(Rules::isControlCode('A.8.1'));
        $this->assertSame(['A.7.2', 'A.7.10'], Rules::sortCodes(['A.7.10', 'A.7.2']));
    }

    public function testNormalization(): void
    {
        $this->assertSame(Rules::STATE_PARTIAL, Rules::normalizeState('partial'));
        $this->assertSame(Rules::STATE_NOT_ASSESSED, Rules::normalizeState('<script>'));
        $this->assertSame(Rules::STATE_NOT_ASSESSED, Rules::normalizeState(['compliant']));
        $this->assertSame(Rules::STATUS_IN_PROGRESS, Rules::normalizeStatus('closed'));
        $this->assertSame(Rules::ZONE_CONTROLLED, Rules::normalizeZoneType('roof'));
        $this->assertSame(['badge', 'cctv'], Rules::normalizeAccessMeans(['cctv', 'badge', 'badge', 'laser', ['x']]));
        $this->assertSame([], Rules::normalizeAccessMeans('badge'));
        $this->assertSame(['key'], Rules::decodeAccessMeans(Rules::encodeAccessMeans(['key', 'nope'])));
        $this->assertSame([], Rules::decodeAccessMeans('not json'));
        $this->assertSame('2026-02-28', Rules::normalizeDate('2026-02-28 10:00:00'));
        $this->assertNull(Rules::normalizeDate('28/02/2026'));
        $this->assertSame(10, mb_strlen(Rules::cleanText(str_repeat('é', 20), 10)));
    }

    public function testNextAssessmentIsTwelveMonthsLater(): void
    {
        $this->assertSame('2027-03-02', Rules::nextAssessmentDate('2026-03-02'));
        $this->assertNull(Rules::nextAssessmentDate(''));
    }

    public function testDueStatusAndReminder(): void
    {
        $today = '2026-11-20';
        $this->assertSame(Rules::DUE_NONE, Rules::dueStatus(null, $today));
        $this->assertSame(Rules::DUE_OK, Rules::dueStatus('2027-01-01', $today));
        $this->assertSame(Rules::DUE_SOON, Rules::dueStatus('2026-12-15', $today));
        $this->assertSame(Rules::DUE_SOON, Rules::dueStatus($today, $today), 'Le jour même : à faire.');
        $this->assertSame(Rules::DUE_OVERDUE, Rules::dueStatus('2026-11-19', $today));

        $this->assertFalse(Rules::shouldRemind('2027-06-01', null, $today), 'Échéance lointaine.');
        $this->assertTrue(Rules::shouldRemind('2026-12-01', null, $today));
        $this->assertFalse(Rules::shouldRemind('2026-12-01', '2026-11-01 08:00:00', $today), 'Rappel récent.');
        $this->assertTrue(Rules::shouldRemind('2026-10-01', '2026-10-21 08:00:00', $today), 'Rappel de 30 jours.');
    }

    public function testSummaryAndScore(): void
    {
        $summary = Rules::summary([
            Rules::STATE_COMPLIANT, Rules::STATE_COMPLIANT, Rules::STATE_PARTIAL, Rules::STATE_NONCOMPLIANT,
            Rules::STATE_NOT_APPLICABLE, Rules::STATE_NOT_ASSESSED,
        ]);
        $this->assertSame(6, $summary['total']);
        $this->assertSame(5, $summary['applicable']);
        $this->assertSame(5, $summary['assessed']);
        $this->assertSame(50, $summary['score'], '(2 + 0,5) / 5 applicables.');
        $this->assertSame(2, $summary['findings']);
        $this->assertNull(Rules::summary([Rules::STATE_NOT_APPLICABLE])['score'], 'Rien d\'applicable.');
        $this->assertNull(Rules::summary([])['score']);
        $this->assertNull(
            Rules::summary([Rules::STATE_NOT_ASSESSED, Rules::STATE_NOT_APPLICABLE])['score'],
            'Rien d\'évalué : pas de score.'
        );
        $this->assertSame(0, Rules::summary([Rules::STATE_NONCOMPLIANT, Rules::STATE_NOT_ASSESSED])['score']);
    }

    public function testFindingPreset(): void
    {
        $this->assertSame(
            ['finding_type' => 'nonconformity', 'severity' => 'major'],
            Rules::findingPreset('noncompliant')
        );
        $this->assertSame('minor', Rules::findingPreset('partial')['severity'] ?? null);
        $this->assertNull(Rules::findingPreset('compliant'));
        $this->assertTrue(Rules::needsAction('partial'));
        $this->assertFalse(Rules::needsAction('not_applicable'));
    }

    public function testZoneWarnings(): void
    {
        $this->assertSame([], Rules::zoneWarnings(Rules::ZONE_PUBLIC, [], false), 'Zone publique.');
        $this->assertSame(
            [Rules::ZONE_WARNING_NO_ACCESS_CONTROL, Rules::ZONE_WARNING_NO_INSTRUCTIONS],
            Rules::zoneWarnings(Rules::ZONE_TECHNICAL, ['laser'], false)
        );
        $this->assertSame([], Rules::zoneWarnings(Rules::ZONE_SENSITIVE, ['badge'], true));
    }
}
