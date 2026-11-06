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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessProcessRecoveryBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use Html;
use Log;
use Session;

/**
 * Issue #377, troisième étape : stratégie de reprise d'un processus métier (ISO 22301 8.4.4,
 * ISO 27001 A.5.30) et temps de reprise mesurés.
 *
 * - La stratégie (mode dégradé, solution de secours, procédure) est portée par le processus et se
 *   modifie avec lui (droit de la continuité et entité), depuis l'onglet « Stratégie de reprise ».
 * - Chaque ligne de cette classe est un test : date, temps de reprise mesuré, et éventuellement
 *   l'exercice de crise (ou la cellule de crise) pendant lequel il a été mesuré. Le dernier test est
 *   comparé au RTO du processus ; un écart est signalé dans la BIA, l'onglet, la salle de crise et le
 *   plan de continuité en PDF.
 *
 * Enregistrer un test exige de pouvoir modifier le processus ; le rattacher à un exercice exige en
 * plus de pouvoir lire l'incident de cet exercice. Un processus ou un exercice que la personne ne
 * peut pas lire n'est jamais nommé.
 */
class BusinessProcessRecovery extends BusinessProcessRecoveryBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const EXERCISE_FIELD = 'plugin_grcmanager_crisisexercises_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessprocessrecoveries';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Recovery test', 'Recovery tests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-lifebuoy';
    }

    public static function formatMinutes(int $minutes): string
    {
        $parts = RecoveryStrategyRules::split($minutes);
        if ($parts['hours'] === 0) {
            return sprintf(__('%d min', 'grcmanager'), $parts['minutes']);
        }

        return sprintf(__('%1$d h %2$02d min', 'grcmanager'), $parts['hours'], $parts['minutes']);
    }

    /**
     * Exercice (ou cellule) de crise que la personne peut lire : droit des incidents et incident lisible.
     */
    public static function readableExercise(int $id): ?CrisisExercise
    {
        if ($id <= 0 || !CrisisExercise::canView()) {
            return null;
        }
        $exercise = new CrisisExercise();
        if (!$exercise->getFromDB($id)) {
            return null;
        }
        $incident = $exercise->getIncident();

        return $incident !== null && $incident->canViewItem() ? $exercise : null;
    }

    /**
     * Libellé d'un exercice lisible : scénario (exercice) ou nom de l'incident (crise réelle), et date.
     */
    public static function exerciseLabel(CrisisExercise $exercise): string
    {
        $incident = $exercise->getIncident();
        $scenario = (string) $exercise->fields['scenario'];
        $name = !empty($incident?->fields['is_exercise'])
            ? (CrisisExercise::getScenarios()[$scenario]['label'] ?? $scenario)
            : sprintf(__('Crisis team: %s', 'grcmanager'), (string) ($incident?->fields['name'] ?? ''));

        return sprintf('%s (%s)', $name, Html::convDate(substr((string) $exercise->fields['started_at'], 0, 10)));
    }

    /**
     * Exercices et cellules de crise lisibles, les plus récents d'abord.
     *
     * @return array<int, string> identifiant => libellé
     */
    public static function exerciseChoices(): array
    {
        global $DB;

        if (!CrisisExercise::canView()) {
            return [];
        }
        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => CrisisExercise::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(CrisisExercise::getTable(), '', '', false),
                'ORDER'  => 'started_at DESC',
                'LIMIT'  => 50,
            ]) as $row
        ) {
            $exercise = self::readableExercise((int) $row['id']);
            if ($exercise !== null) {
                $choices[(int) $row['id']] = self::exerciseLabel($exercise);
            }
        }

        return $choices;
    }

    /**
     * Processus que la personne peut modifier, pour enregistrer un test depuis la salle de crise.
     *
     * @return array<int, string>
     */
    public static function processChoices(): array
    {
        if (!BusinessProcess::canUpdate()) {
            return [];
        }
        $choices = [];
        $process = new BusinessProcess();
        $criteria = getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true);
        foreach ($process->find($criteria, ['name ASC'], 500) as $row) {
            if ($process->getFromDB((int) $row['id']) && $process->canUpdateItem()) {
                $choices[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $choices;
    }

    /**
     * Tests d'un processus, du plus récent au plus ancien. L'exercice n'est nommé que s'il est lisible.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForProcess(BusinessProcess $process): array
    {
        $rto = (int) ($process->fields['rto_hours'] ?? 0);
        $rows = [];
        foreach (
            (new self())->find(
                ['plugin_grcmanager_businessprocesses_id' => (int) $process->getID()],
                ['test_date DESC', 'id DESC']
            ) as $row
        ) {
            $exerciseId = (int) $row[self::EXERCISE_FIELD];
            $exercise = self::readableExercise($exerciseId);
            $minutes = (int) $row['measured_minutes'];
            $rows[] = [
                'id'              => (int) $row['id'],
                'date'            => Html::convDate((string) $row['test_date']),
                'minutes'         => $minutes,
                'duration'        => self::formatMinutes($minutes),
                'exceeds'         => RecoveryStrategyRules::exceedsRto($rto, $minutes),
                'exercise'        => $exercise !== null ? self::exerciseLabel($exercise) : '',
                'exercise_url'    => $exercise !== null ? CrisisExercise::getFormURLWithID($exerciseId) : '',
                'exercise_hidden' => $exerciseId > 0 && $exercise === null,
                'by'              => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'comment'         => (string) $row['comment'],
            ];
        }

        return $rows;
    }

    /**
     * Temps de reprise mesuré au dernier test du processus, en minutes, ou null s'il n'a jamais été testé.
     */
    public static function lastMeasured(int $processId): ?int
    {
        $last = RecoveryStrategyRules::lastTest(
            (new self())->find(['plugin_grcmanager_businessprocesses_id' => $processId])
        );

        return $last === null ? null : (int) $last['measured_minutes'];
    }

    /**
     * Stratégie de reprise d'un processus lisible : textes, tests, dernier test et contrôles.
     *
     * @return array<string, mixed>
     */
    public static function strategy(BusinessProcess $process): array
    {
        $texts = [
            'degraded_mode'      => (string) ($process->fields['degraded_mode'] ?? ''),
            'backup_solution'    => (string) ($process->fields['backup_solution'] ?? ''),
            'recovery_procedure' => (string) ($process->fields['recovery_procedure'] ?? ''),
        ];
        $tests = self::getForProcess($process);
        $last = $tests[0] ?? null;

        return $texts + [
            'tests'  => $tests,
            'last'   => $last,
            'issues' => RecoveryStrategyRules::strategyIssues(
                (int) ($process->fields['rto_hours'] ?? 0),
                array_values($texts),
                $last === null ? null : (int) $last['minutes']
            ),
        ];
    }

    /**
     * Tests enregistrés pendant un exercice, limités aux processus lisibles (les autres sont comptés).
     *
     * @return array{rows: list<array<string, mixed>>, hidden: int}
     */
    public static function forExercise(CrisisExercise $exercise): array
    {
        if (!BusinessProcess::canView()) {
            return ['rows' => [], 'hidden' => 0];
        }
        $durations = BusinessProcess::getDurationOptions();
        $process = new BusinessProcess();
        $rows = [];
        $hidden = 0;
        foreach ((new self())->find([self::EXERCISE_FIELD => (int) $exercise->getID()], ['id ASC']) as $row) {
            if (
                !$process->getFromDB((int) $row['plugin_grcmanager_businessprocesses_id'])
                || !$process->canViewItem()
            ) {
                $hidden++;
                continue;
            }
            $rto = (int) $process->fields['rto_hours'];
            $minutes = (int) $row['measured_minutes'];
            $rows[] = [
                'name'     => (string) $process->fields['name'],
                'url'      => $process->getLinkURL(),
                'rto'      => $durations[$rto] ?? '',
                'duration' => self::formatMinutes($minutes),
                'exceeds'  => RecoveryStrategyRules::exceedsRto($rto, $minutes),
                'comment'  => (string) $row['comment'],
            ];
        }

        return ['rows' => $rows, 'hidden' => $hidden];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !BusinessProcess::canView()) {
            return '';
        }

        return self::createTabEntry(
            __('Recovery strategy', 'grcmanager'),
            countElementsInTable(self::getTable(), ['plugin_grcmanager_businessprocesses_id' => $item->getID()]),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (
            !$item instanceof BusinessProcess || $item->isNewItem() || !BusinessProcess::canView()
            || !$item->canViewItem()
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_recovery.html.twig', [
            'item'      => $item,
            'strategy'  => self::strategy($item),
            'issues'    => BusinessProcess::getIssueLabels(),
            'durations' => BusinessProcess::getDurationOptions(),
            'exercises' => $item->canUpdateItem() ? self::exerciseChoices() : [],
            'can_edit'  => $item->canUpdateItem(),
            'process_url' => BusinessProcess::getFormURL(),
            'form_url'  => self::getFormURL(),
            'today'     => date('Y-m-d'),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $process = new BusinessProcess();
        if (
            !$process->getFromDB((int) ($input['plugin_grcmanager_businessprocesses_id'] ?? 0))
            || !$process->canUpdateItem()
        ) {
            return false;
        }
        $minutes = RecoveryStrategyRules::sanitizeMinutes($input['measured_minutes'] ?? 0);
        if ($minutes <= 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Enter the measured recovery time, in minutes.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }
        $exerciseId = (int) ($input[self::EXERCISE_FIELD] ?? 0);
        $exercise = self::readableExercise($exerciseId);
        if ($exerciseId > 0 && $exercise === null) {
            return false;
        }
        $date = RecoveryStrategyRules::sanitizeDate($input['test_date'] ?? null)
            ?? ($exercise !== null
                ? substr((string) $exercise->fields['started_at'], 0, 10)
                : date('Y-m-d'));

        return [
            'plugin_grcmanager_businessprocesses_id' => (int) $process->getID(),
            self::EXERCISE_FIELD                     => $exercise !== null ? $exerciseId : 0,
            'test_date'                              => $date,
            'measured_minutes'                       => $minutes,
            'users_id'                               => (int) Session::getLoginUserID(),
            'comment'                                => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255),
        ];
    }

    public function prepareInputForUpdate($input)
    {
        // Un test mesuré ne se réécrit pas : il se retire et se saisit de nouveau.
        return false;
    }

    public function post_addItem()
    {
        $this->history(Log::HISTORY_ADD_SUBITEM);
    }

    public function post_purgeItem()
    {
        $this->history(Log::HISTORY_DELETE_SUBITEM);
    }

    private function history(int $action): void
    {
        $label = sprintf(
            '%s %s : %s',
            self::getTypeName(1),
            (string) $this->fields['test_date'],
            self::formatMinutes((int) $this->fields['measured_minutes'])
        );
        Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            $action === Log::HISTORY_ADD_SUBITEM ? [0, '', $label] : [0, $label, ''],
            self::class,
            $action
        );
    }
}
