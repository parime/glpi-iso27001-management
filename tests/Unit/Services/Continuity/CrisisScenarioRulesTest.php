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

use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioDefaults as Defaults;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #776 : règles des scénarios de crise sécurité de l'information (A.5.29).
 */
final class CrisisScenarioRulesTest extends TestCase
{
    public function testCriticalProcessHasAnRtoOf24HoursOrLess(): void
    {
        $this->assertTrue(Rules::isCritical(['rto_hours' => 4]));
        $this->assertTrue(Rules::isCritical(['rto_hours' => 24]));
        $this->assertFalse(Rules::isCritical(['rto_hours' => 48]));
        $this->assertFalse(Rules::isCritical(['rto_hours' => 0]), 'RTO non défini : signalé ailleurs.');
        $this->assertFalse(Rules::isCritical([]));
    }

    public function testLatestDateIgnoresEmptyAndInvalidDates(): void
    {
        $this->assertNull(Rules::latestDate(null, '', '0000-00-00', 'demain', '2026-02-30'));
        $this->assertSame('2026-05-04', Rules::latestDate('2026-01-10', '2026-05-04 10:30:00', null));
        $this->assertSame('2026-01-10', Rules::latestDate('2026-01-10'));
    }

    public function testExerciseStatus(): void
    {
        $this->assertSame(Rules::EXERCISE_NEVER, Rules::exerciseStatus(null, '2026-11-20'));
        $this->assertSame(Rules::EXERCISE_OK, Rules::exerciseStatus('2026-01-15', '2026-11-20'));
        $this->assertSame(Rules::EXERCISE_OK, Rules::exerciseStatus('2025-11-20', '2026-11-20'), 'Il y a 365 jours.');
        $this->assertSame(Rules::EXERCISE_OVERDUE, Rules::exerciseStatus('2025-11-19', '2026-11-20'));
    }

    public function testGapsOfAScenario(): void
    {
        $complete = [
            'degraded_mode' => 'Accès limités', 'role_management' => 'a', 'role_ciso' => 'b', 'role_it' => 'c',
        ];
        $this->assertSame([], Rules::gaps($complete, 2, true, Rules::EXERCISE_OK));
        $this->assertSame(
            [
                Rules::GAP_NO_PROCESS, Rules::GAP_NO_DEGRADED_MODE, Rules::GAP_NO_ROLES, Rules::GAP_NO_PLAYBOOK,
                Rules::GAP_NEVER_EXERCISED,
            ],
            Rules::gaps(['degraded_mode' => '  ', 'role_ciso' => 'b'], 0, false, Rules::EXERCISE_NEVER)
        );
        $this->assertSame(
            [Rules::GAP_EXERCISE_OVERDUE],
            Rules::gaps($complete, 1, true, Rules::EXERCISE_OVERDUE)
        );
        // Lot 2 : bilan du dernier exercice non validé (#732), processus critique jamais testé (#733).
        $this->assertSame(
            [Rules::GAP_REVIEW_PENDING, Rules::GAP_RECOVERY_UNTESTED],
            Rules::gaps($complete, 1, true, Rules::EXERCISE_OK, true, 1)
        );
        $this->assertSame([], Rules::gaps($complete, 1, true, Rules::EXERCISE_OK, false, 0));
    }

    public function testOfficialContactsToInformDependOnTheSituation(): void
    {
        $this->assertSame(['on_security_incident'], Rules::contactFlags('ransomware'));
        $this->assertSame(['on_security_incident'], Rules::contactFlags(''));
        $this->assertSame(['on_security_incident', 'on_data_breach'], Rules::contactFlags('data_breach'));
    }

    public function testCoverageSummary(): void
    {
        $processes = [
            ['id' => 1, 'rto_hours' => 4],
            ['id' => 2, 'rto_hours' => 24],
            ['id' => 3, 'rto_hours' => 72],
            ['id' => 4, 'rto_hours' => 0],
        ];
        $counts = [1 => 2, 3 => 1];
        $scenarios = [
            [
                'is_active' => true, 'exercise_status' => Rules::EXERCISE_OK, 'gaps' => [],
                'default_key' => 'ransomware', 'drill' => ['validated' => true],
            ],
            [
                'is_active' => true, 'exercise_status' => Rules::EXERCISE_OK,
                'gaps' => [Rules::GAP_REVIEW_PENDING, Rules::GAP_RECOVERY_UNTESTED], 'default_key' => '',
                'drill' => ['validated' => false],
            ],
            [
                'is_active' => true, 'exercise_status' => Rules::EXERCISE_NEVER,
                'gaps' => [Rules::GAP_NEVER_EXERCISED], 'default_key' => '',
            ],
            [
                'is_active' => true, 'exercise_status' => Rules::EXERCISE_OVERDUE,
                'gaps' => [Rules::GAP_EXERCISE_OVERDUE], 'default_key' => 'data_breach',
            ],
            [
                'is_active' => false, 'exercise_status' => Rules::EXERCISE_NEVER, 'gaps' => [],
                'default_key' => 'denial_of_service',
            ],
        ];
        $this->assertSame([['id' => 2, 'rto_hours' => 24]], Rules::uncoveredCriticalProcesses($processes, $counts));
        $this->assertSame([
            'critical'           => 2,
            'critical_uncovered' => 1,
            'scenarios'          => 4,
            'never_exercised'    => 1,
            'overdue'            => 1,
            'review_pending'     => 1,
            'recovery_untested'  => 1,
            'reviews_validated'  => 1,
            'with_gaps'          => 3,
            'missing_defaults'   => ['privileged_accounts', 'supplier_outage'],
        ], Rules::summary($processes, $counts, $scenarios));
    }

    public function testProcessScopeFollowsTheEntitiesOfTheScenario(): void
    {
        // Même entité.
        $this->assertTrue(Rules::processInScope(5, false, 5, false, [0], [0]));
        // Processus d'une sous-entité : seulement si le scénario est récursif.
        $this->assertTrue(Rules::processInScope(7, false, 5, true, [0], [0, 5]));
        $this->assertFalse(Rules::processInScope(7, false, 5, false, [0], [0, 5]));
        // Processus récursif d'une entité parente.
        $this->assertTrue(Rules::processInScope(0, true, 5, false, [0], []));
        $this->assertFalse(Rules::processInScope(0, false, 5, false, [0], []));
        // Entité sœur : jamais.
        $this->assertFalse(Rules::processInScope(6, true, 5, true, [0], [0]));
    }

    public function testDefaultCatalogueReusesExistingObjects(): void
    {
        $this->assertCount(5, Defaults::KEYS);
        $this->assertTrue(Defaults::exists('ransomware'));
        $this->assertFalse(Defaults::exists('meteorite'));
        foreach (array_keys(Defaults::PLAYBOOKS + Defaults::EXERCISES + Defaults::SITUATIONS) as $key) {
            $this->assertContains($key, Defaults::KEYS);
        }
        // Scénarios d'exercice fournis par CrisisExercise.
        foreach (Defaults::EXERCISES as $exercise) {
            $this->assertContains($exercise, ['ransomware', 'data_breach', 'outage']);
        }
    }
}
