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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\ContinuityPlanTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules;
use Html;
use Session;

/**
 * Issue #1083 : tests d'un plan de continuité ou de reprise (onglet « Tests du plan »), preuve que le
 * plan fonctionne (A.5.30). Chaque test a une date, un type (ceux des exercices de reprise, réglables),
 * un résultat, et éventuellement l'exercice de crise du plugin pendant lequel il a eu lieu. Les écarts
 * constatés deviennent une non-conformité, à la demande de l'utilisateur (droit de créer des
 * non-conformités), rattachée au test.
 *
 * Les tests de reprise des processus couverts (BusinessProcessRecovery) restent sur chaque processus et
 * sont repris en lecture dans la fiche du plan, sans être recopiés.
 *
 * Enregistrer ou retirer un test exige de pouvoir modifier le plan ; un exercice n'est nommé que s'il
 * est lisible.
 */
final class ContinuityPlanTesting extends CommonGLPI
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public static function getTypeName($nb = 0)
    {
        return _n('Plan test', 'Plan tests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-test-pipe';
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> résultat => [classes, icône, libellé]
     */
    public static function resultLabels(): array
    {
        return [
            Rules::RESULT_PASSED  => [
                'bg-success-subtle text-success-emphasis', 'ti-circle-check', __('Objectives met', 'grcmanager'),
            ],
            Rules::RESULT_PARTIAL => [
                'bg-warning-subtle text-warning-emphasis', 'ti-circle-half-2', __('Partially met', 'grcmanager'),
            ],
            Rules::RESULT_FAILED  => [
                'bg-danger-subtle text-danger-emphasis', 'ti-circle-x', __('Objectives missed', 'grcmanager'),
            ],
        ];
    }

    /**
     * Tests du plan, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPlan(ContinuityPlan $plan): array
    {
        global $DB;

        $labels = self::resultLabels();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => ContinuityPlanTables::TESTS,
                'WHERE' => [ContinuityPlan::FK => (int) $plan->getID()],
                'ORDER' => ['test_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $exerciseId = (int) $row[BusinessProcessRecovery::EXERCISE_FIELD];
            $exercise = BusinessProcessRecovery::readableExercise($exerciseId);
            $action = self::readableAction((string) $row['itemtype'], (int) $row['items_id']);
            $result = $labels[(string) $row['result']] ?? $labels[Rules::RESULT_PARTIAL];
            $rows[] = [
                'id'            => (int) $row['id'],
                'test_date'     => (string) $row['test_date'],
                'date'          => Html::convDate((string) $row['test_date']),
                'type'          => BusinessProcessRecovery::typeLabel((string) $row['test_type']),
                'result'        => (string) $row['result'],
                'result_class'  => $result[0],
                'result_icon'   => $result[1],
                'result_label'  => $result[2],
                'exercise'      => $exercise !== null ? BusinessProcessRecovery::exerciseLabel($exercise) : '',
                'exercise_url'  => $exercise !== null ? CrisisExercise::getFormURLWithID($exerciseId) : '',
                'summary'       => (string) $row['summary'],
                'gaps'          => (string) $row['gaps'],
                'has_action'    => (string) $row['itemtype'] !== '',
                'action'        => $action,
                'by'            => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
            ];
        }

        return $rows;
    }

    /**
     * Action créée depuis un test, si la personne peut la lire.
     *
     * @return array{name: string, url: string}|null
     */
    private static function readableAction(string $itemtype, int $itemsId): ?array
    {
        if ($itemtype !== Nonconformity::class || $itemsId <= 0 || !Nonconformity::canView()) {
            return null;
        }
        $item = new Nonconformity();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return null;
        }

        return ['name' => (string) $item->fields['title'], 'url' => Nonconformity::getFormURLWithID($itemsId)];
    }

    /**
     * Enregistre un test du plan (l'appelant a vérifié le droit de modifier le plan).
     *
     * @param array<string, mixed> $input
     * @return int identifiant du test, 0 si refusé
     */
    public static function add(ContinuityPlan $plan, array $input): int
    {
        global $DB;

        $date = CrisisScenarioRules::latestDate((string) ($input['test_date'] ?? ''));
        if ($date === null || strcmp($date, date('Y-m-d')) > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Give the date of the test (today or earlier).', 'grcmanager')),
                false,
                ERROR
            );

            return 0;
        }
        $type = (string) ($input['test_type'] ?? '');
        if (!isset(BusinessProcessRecovery::typeChoices()[$type])) {
            $type = '';
        }
        $exerciseId = (int) ($input[BusinessProcessRecovery::EXERCISE_FIELD] ?? 0);
        if ($exerciseId > 0 && BusinessProcessRecovery::readableExercise($exerciseId) === null) {
            $exerciseId = 0;
        }
        $now = date('Y-m-d H:i:s');
        $DB->insert(ContinuityPlanTables::TESTS, [
            ContinuityPlan::FK                      => (int) $plan->getID(),
            'test_date'                             => $date,
            'test_type'                             => $type,
            'result'                                => Rules::normalizeResult((string) ($input['result'] ?? '')),
            BusinessProcessRecovery::EXERCISE_FIELD => $exerciseId,
            'summary'                               => trim((string) ($input['summary'] ?? '')),
            'gaps'                                  => trim((string) ($input['gaps'] ?? '')),
            'users_id'                              => (int) Session::getLoginUserID(),
            'date_creation'                         => $now,
            'date_mod'                              => $now,
        ]);
        $testId = (int) $DB->insertId();
        AuditLog::record('continuity_plan_test', ContinuityPlan::class, (int) $plan->getID(), 0, 'success', [
            'test' => $testId, 'date' => $date,
        ]);

        return $testId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function row(ContinuityPlan $plan, int $testId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => ContinuityPlanTables::TESTS,
            'WHERE' => ['id' => $testId, ContinuityPlan::FK => (int) $plan->getID()],
        ])->current();

        return is_array($row) ? $row : null;
    }

    /** Retire un test sans action créée (l'appelant a vérifié le droit de modifier le plan). */
    public static function remove(ContinuityPlan $plan, int $testId): bool
    {
        global $DB;

        $row = self::row($plan, $testId);
        if ($row === null || (string) $row['itemtype'] !== '') {
            return false;
        }
        $DB->delete(ContinuityPlanTables::TESTS, ['id' => $testId]);

        return true;
    }

    /**
     * Crée, à la demande, une non-conformité depuis les écarts d'un test, dans l'entité du plan, et la
     * rattache au test. Les contrôles de droits et d'entité des non-conformités s'appliquent.
     *
     * @return int identifiant de la non-conformité, 0 si refusé
     */
    public static function createNonconformity(ContinuityPlan $plan, int $testId): int
    {
        global $DB;

        $row = self::row($plan, $testId);
        if (
            $row === null || (string) $row['itemtype'] !== '' || trim((string) $row['gaps']) === ''
            || !Nonconformity::canCreate()
        ) {
            return 0;
        }
        $title = mb_substr(sprintf(
            __('Gap found when testing the plan "%1$s" on %2$s', 'grcmanager'),
            (string) $plan->fields['name'],
            Html::convDate((string) $row['test_date'])
        ), 0, 255);
        $item = new Nonconformity();
        $itemId = (int) $item->add([
            'title'                 => $title,
            'description'           => trim((string) $row['gaps'] . "\n\n" . (string) $row['summary']),
            'entities_id'           => (int) $plan->fields['entities_id'],
            'requirement_reference' => 'A.5.30',
            'severity'              => (string) $row['result'] === Rules::RESULT_FAILED ? 'major' : 'minor',
            'users_id'              => (int) $plan->fields['users_id'],
        ]);
        if ($itemId <= 0) {
            return 0;
        }
        $DB->update(ContinuityPlanTables::TESTS, [
            'itemtype' => Nonconformity::class,
            'items_id' => $itemId,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $testId]);

        return $itemId;
    }

    // ------------------------------------------------------------------
    // Onglet du plan
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof ContinuityPlan || $item->isNewItem() || !ContinuityPlan::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            countElementsInTable(ContinuityPlanTables::TESTS, [ContinuityPlan::FK => (int) $item->getID()]),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof ContinuityPlan || $item->isNewItem() || !$item->canViewItem()) {
            return false;
        }
        $planId = (int) $item->getID();
        $tests = self::forPlan($item);
        $last = Rules::lastTest($tests);
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/continuity_plan_tests.html.twig', [
            'plan_id'       => $planId,
            'tests'         => $tests,
            'test_status'   => Rules::testStatus($last['test_date'] ?? null, date('Y-m-d')),
            'can_update'    => (bool) $item->can($planId, UPDATE),
            'can_nc'        => Nonconformity::canCreate(),
            'types'         => BusinessProcessRecovery::typeChoices(),
            'results'       => array_map(static fn (array $label): string => $label[2], self::resultLabels()),
            'exercises'     => [0 => '-----'] + BusinessProcessRecovery::exerciseChoices(),
            'processes'     => $item->coveredProcesses(),
            'action'        => ContinuityPlan::getFormURL(),
            'today'         => date('Y-m-d'),
        ]);

        return true;
    }
}
