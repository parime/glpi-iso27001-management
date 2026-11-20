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

use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #773 : programme d'audit pluriannuel, indépendance et compétence des auditeurs.
 */
final class ProgrammeRulesTest extends TestCase
{
    public function testCycleIsThreeYearsAndBounded(): void
    {
        self::assertSame(2025, Rules::cycleStart(null, 2026), 'par défaut : année précédente');
        self::assertSame(2024, Rules::cycleStart('2024', 2026));
        self::assertSame(2016, Rules::cycleStart(1990, 2026), 'borne basse');
        self::assertSame(2028, Rules::cycleStart(2099, 2026), 'borne haute');
        self::assertSame([2025, 2026, 2027], Rules::cycleYears(2025));
        self::assertSame(['2025-01-01', '2027-12-31'], Rules::cycleBounds(2025));
        self::assertSame('2026-03-01', Rules::auditDate('2026-03-01', '2026-02-01'));
        self::assertSame('2026-02-01', Rules::auditDate(null, '2026-02-01'));
        self::assertSame('', Rules::auditDate('', null));
    }

    public function testProcessMustBelongToTheEntityOfTheAudit(): void
    {
        // Arbre : 0 > 1 > 2, et 0 > 3. Audit dans l'entité 1.
        $chain = [1, 0];
        $subtree = [1, 2];
        self::assertTrue(Rules::processFitsAudit(1, false, $chain, $subtree, false), 'même entité');
        self::assertFalse(Rules::processFitsAudit(0, false, $chain, $subtree, false), 'parent non récursif');
        self::assertTrue(Rules::processFitsAudit(0, true, $chain, $subtree, false), 'parent récursif');
        self::assertFalse(Rules::processFitsAudit(2, false, $chain, $subtree, false), 'audit non récursif');
        self::assertTrue(Rules::processFitsAudit(2, false, $chain, $subtree, true), 'sous-entité, audit récursif');
        self::assertFalse(Rules::processFitsAudit(3, true, $chain, $subtree, true), 'entité sœur');
    }

    public function testStateOfAudits(): void
    {
        $today = '2026-11-20';
        self::assertSame(Rules::STATE_NONE, Rules::state([], $today));
        self::assertSame(Rules::STATE_LATE, Rules::state([['status' => 'planned', 'date' => '2026-06-01']], $today));
        self::assertSame(Rules::STATE_PLANNED, Rules::state([['status' => 'planned', 'date' => '2026-12-01']], $today));
        $running = [['status' => 'in_progress', 'date' => '2026-01-01']];
        self::assertSame(Rules::STATE_PLANNED, Rules::state($running, $today));
        self::assertSame(Rules::STATE_PLANNED, Rules::state([
            ['status' => 'planned', 'date' => '2026-06-01'],
            ['status' => 'planned', 'date' => '2027-02-01'],
        ], $today), 'un audit à venir rattrape un audit en retard');
        self::assertSame(Rules::STATE_DONE, Rules::state([
            ['status' => 'planned', 'date' => '2027-02-01'],
            ['status' => 'completed', 'date' => '2025-02-01'],
        ], $today));
    }

    public function testMatrixCoverageAndAlerts(): void
    {
        $processes = [10 => 'Gestion des accès', 20 => 'Sauvegardes', 30 => 'Gestion des fournisseurs', 40 => 'Veille'];
        $audits = [
            ['id' => 1, 'title' => 'Accès', 'status' => 'completed', 'date' => '2025-04-10', 'processes' => [10]],
            ['id' => 2, 'title' => 'Copies', 'status' => 'planned', 'date' => '2027-03-01', 'processes' => [20, 10]],
            ['id' => 3, 'title' => 'Fournisseurs', 'status' => 'planned', 'date' => '2026-02-01', 'processes' => [30]],
            ['id' => 4, 'title' => 'Annulé', 'status' => 'cancelled', 'date' => '2026-05-01', 'processes' => [40]],
            ['id' => 5, 'title' => 'Hors cycle', 'status' => 'completed', 'date' => '2024-05-01', 'processes' => [40]],
            ['id' => 6, 'title' => 'Sans processus', 'status' => 'planned', 'date' => '2026-12-01', 'processes' => []],
        ];
        $matrix = Rules::matrix($processes, $audits, 2025, '2026-11-20');

        self::assertSame([2025, 2026, 2027], $matrix['years']);
        self::assertSame(4, $matrix['total']);
        self::assertSame(1, $matrix['covered']);
        self::assertSame(1, $matrix['planned']);
        self::assertSame(1, $matrix['late']);
        self::assertSame(1, $matrix['uncovered']);
        self::assertSame(25, $matrix['percent']);
        self::assertSame([6], array_column($matrix['unassigned'], 'id'));

        // Non couverts en tête, puis en retard, prévus, audités.
        self::assertSame([40, 30, 20, 10], array_column($matrix['rows'], 'id'));
        $access = $matrix['rows'][3];
        self::assertSame(Rules::STATE_DONE, $access['cells'][2025]['state']);
        self::assertSame(Rules::STATE_NONE, $access['cells'][2026]['state']);
        self::assertSame(Rules::STATE_PLANNED, $access['cells'][2027]['state']);
        self::assertSame([], $matrix['rows'][0]['cells'][2026]['audits'], 'audit annulé ignoré');

        $empty = Rules::matrix([], [], 2025, '2026-11-20');
        self::assertSame(0, $empty['percent']);
    }

    public function testIndependenceConflicts(): void
    {
        $assignments = [
            10 => [
                ['itemtype' => 'User', 'items_id' => 5, 'role' => 'responsible'],
                ['itemtype' => 'User', 'items_id' => 6, 'role' => 'consulted'],
            ],
            20 => [
                ['itemtype' => 'Group', 'items_id' => 100, 'role' => 'accountable'],
                ['itemtype' => 'User', 'items_id' => 5, 'role' => 'informed'],
            ],
        ];
        $conflicts = Rules::conflicts([5, 6, 7, 5, 0], [7 => [100], 6 => [200]], $assignments);
        self::assertSame([
            ['users_id' => 5, 'process_id' => 10, 'role' => 'responsible', 'groups_id' => 0],
            ['users_id' => 7, 'process_id' => 20, 'role' => 'accountable', 'groups_id' => 100],
        ], $conflicts, 'consulté et informé ne sont pas des conflits');

        self::assertSame('7:20', Rules::conflictKey(7, 20));
        self::assertSame([$conflicts[1]], Rules::unjustified($conflicts, ['5:10']));
        self::assertSame([], Rules::conflicts([], [], $assignments));
    }

    public function testGuardedChangesAndJustification(): void
    {
        self::assertTrue(Rules::isGuardedChange('planned', 'in_progress', false));
        self::assertTrue(Rules::isGuardedChange('in_progress', 'completed', false));
        self::assertTrue(Rules::isGuardedChange('in_progress', 'in_progress', true), 'changement d\'auditeur en cours');
        self::assertFalse(Rules::isGuardedChange('in_progress', 'in_progress', false), 'autre modification');
        self::assertFalse(Rules::isGuardedChange('planned', 'planned', true));
        self::assertFalse(Rules::isGuardedChange('in_progress', 'cancelled', false));

        self::assertSame(
            "Petite équipe\nrevue par un tiers",
            Rules::cleanJustification("  Petite équipe\r\nrevue par un tiers ")
        );
        self::assertSame('', Rules::cleanJustification(['x']));
        self::assertFalse(Rules::isValidJustification('court'));
        self::assertTrue(Rules::isValidJustification('Équipe de deux personnes'));
    }

    public function testCompetence(): void
    {
        $today = '2026-11-20';
        $training = static fn (
            string $status,
            ?string $date,
            string $assessment = 'not_applicable',
            int $months = 0
        ): array => [
            'title'                 => 'Auditeur interne ISO 27001',
            'completion_status'     => $status,
            'completion_date'       => $date,
            'assessment_result'     => $assessment,
            'renewal_period_months' => $months,
        ];
        $status = static fn (array $rows, bool $configured = true): string
            => Rules::competence($rows, $configured, $today)['status'];

        self::assertSame(Rules::COMPETENCE_NOT_CONFIGURED, $status([$training('completed', '2026-01-01')], false));
        self::assertSame(Rules::COMPETENCE_MISSING, $status([]));
        self::assertSame(Rules::COMPETENCE_MISSING, $status([$training('pending', null)]));
        self::assertSame(Rules::COMPETENCE_MISSING, $status([$training('completed', '2026-01-01', 'failed')]));
        self::assertSame(Rules::COMPETENCE_QUALIFIED, $status([$training('completed', '2026-01-01', 'passed')]));
        self::assertSame(Rules::COMPETENCE_QUALIFIED, $status([$training('exempted', null)]));
        self::assertSame(Rules::COMPETENCE_EXPIRED, $status([$training('completed', '2024-01-01', 'passed', 24)]));
        $mixed = Rules::competence([
            $training('completed', '2024-01-01', 'passed', 24),
            $training('completed', '2025-12-01', 'passed', 24),
        ], true, $today);
        self::assertSame(Rules::COMPETENCE_QUALIFIED, $mixed['status']);
        self::assertSame([false, true], array_column($mixed['trainings'], 'valid'));
    }

    public function testAuditChecks(): void
    {
        $facts = static fn (array $extra = []): array => $extra + [
            'objectives'     => 'Vérifier la revue des accès',
            'scope'          => 'Siège Exemple',
            'criteria'       => 'Politique de contrôle d\'accès',
            'processes'      => 1,
            'competences'    => [Rules::COMPETENCE_QUALIFIED],
            'open_conflicts' => 0,
            'status'         => 'in_progress',
            'delivered'      => false,
        ];

        $ready = Rules::auditChecks($facts());
        self::assertSame(Rules::CHECK_KEYS, array_keys($ready['checks']));
        self::assertSame(Rules::CHECK_PENDING, $ready['checks']['delivery']);
        self::assertSame(0, $ready['gaps']);

        $empty = Rules::auditChecks($facts([
            'objectives'  => '  ',
            'criteria'    => '',
            'processes'   => 0,
            'competences' => [],
            'status'      => 'completed',
        ]));
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['objectives']);
        self::assertSame(Rules::CHECK_OK, $empty['checks']['scope']);
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['criteria']);
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['processes']);
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['auditors']);
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['competence']);
        self::assertSame(Rules::CHECK_PENDING, $empty['checks']['independence']);
        self::assertSame(Rules::CHECK_MISSING, $empty['checks']['delivery']);
        self::assertSame(6, $empty['gaps']);

        $competence = static fn (array $statuses): string
            => Rules::auditChecks($facts(['competences' => $statuses]))['checks']['competence'];
        self::assertSame(Rules::CHECK_NOT_APPLICABLE, $competence([Rules::COMPETENCE_NOT_CONFIGURED]));
        self::assertSame(
            Rules::CHECK_MISSING,
            $competence([Rules::COMPETENCE_QUALIFIED, Rules::COMPETENCE_MISSING])
        );
        self::assertSame(
            Rules::CHECK_WARNING,
            $competence([Rules::COMPETENCE_QUALIFIED, Rules::COMPETENCE_EXPIRED])
        );

        $conflict = Rules::auditChecks($facts(['open_conflicts' => 1, 'delivered' => true]));
        self::assertSame(Rules::CHECK_MISSING, $conflict['checks']['independence']);
        self::assertSame(Rules::CHECK_OK, $conflict['checks']['delivery']);
        self::assertSame(1, $conflict['gaps']);
    }

    /** Lot 3 : processus visés par une mesure de l'Annexe A auditée. */
    public function testScopeProcessesIncludeAuditedControls(): void
    {
        $processCodes = [
            10 => ['A.5.15', 'A.8.2'],
            11 => ['A.8.13'],
            12 => [],
            13 => ['A.8.2', 'A.5.15', 'A.5.18'],
        ];
        $scope = Rules::scopeProcesses([11], $processCodes, ['A.8.2', 'A.5.15', 'A.8.13']);
        self::assertSame([11 => [], 10 => ['A.5.15', 'A.8.2'], 13 => ['A.5.15', 'A.8.2']], $scope);
        self::assertSame([11 => []], Rules::scopeProcesses([11], $processCodes, []), 'aucune mesure auditée');
        self::assertSame([], Rules::scopeProcesses([], $processCodes, ['A.7.1']));
    }

    /** Lot 3 : date proposée pour un audit planifié depuis le programme. */
    public function testPlannedDateFromTheProgramme(): void
    {
        self::assertNull(Rules::plannedDate(2025, '2026-11-20'), 'année passée');
        self::assertSame('2026-12-20', Rules::plannedDate(2026, '2026-11-20'));
        self::assertSame('2026-12-31', Rules::plannedDate(2026, '2026-12-15'), 'au plus le 31 décembre');
        self::assertSame('2027-06-30', Rules::plannedDate(2027, '2026-11-20'));
        self::assertTrue(Rules::canPlanCell(Rules::STATE_NONE, 2027, '2026-11-20'));
        self::assertTrue(Rules::canPlanCell(Rules::STATE_LATE, 2026, '2026-11-20'));
        self::assertFalse(Rules::canPlanCell(Rules::STATE_PLANNED, 2027, '2026-11-20'));
        self::assertFalse(Rules::canPlanCell(Rules::STATE_DONE, 2026, '2026-11-20'));
        self::assertFalse(Rules::canPlanCell(Rules::STATE_NONE, 2025, '2026-11-20'));
    }
}
