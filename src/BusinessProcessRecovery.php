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

use CommonDBTM;
use CommonGLPI;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\BusinessProcessRecoveryBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryExerciseRules;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
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
 *
 * Issue #733 : chaque test porte aussi son type d'exercice (liste réglable), la perte de données
 * mesurée (RPO mesuré, comparée au RPO du processus et à celui de l'élément concerné) et,
 * facultativement, l'élément concerné parmi les dépendances du processus. Le programme de tests
 * (fréquence par processus, couverture pluriannuelle) et l'historique sur l'élément s'en servent.
 * Un élément que la personne ne peut pas lire n'est jamais nommé.
 */
class BusinessProcessRecovery extends BusinessProcessRecoveryBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const EXERCISE_FIELD = 'plugin_grcmanager_crisisexercises_id';

    /** Issue #733 : types d'exercice réglés (JSON), dans le contexte de configuration du plugin. */
    public const TYPES_CONFIG_KEY = 'recovery_exercise_types';

    /** Issue #733 : tests listés au plus sur un élément. */
    public const MAX_ITEM_TESTS = 100;

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

    // ------------------------------------------------------------------
    // Issue #733 : types d'exercice
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public static function defaultTypeLabels(): array
    {
        return [
            RecoveryExerciseRules::TYPE_TABLETOP          => __('Tabletop exercise', 'grcmanager'),
            RecoveryExerciseRules::TYPE_FUNCTIONAL        => __('Functional exercise', 'grcmanager'),
            RecoveryExerciseRules::TYPE_TECHNICAL_RESTORE => __('Technical restore test', 'grcmanager'),
            RecoveryExerciseRules::TYPE_FULL_SCALE        => __('Full-scale exercise', 'grcmanager'),
        ];
    }

    /**
     * Types d'exercice proposés : liste réglée dans la configuration du plugin, sinon types par défaut.
     *
     * @return list<string>
     */
    public static function exerciseTypes(): array
    {
        $values = Config::getConfigurationValues(PolicyApprovalConfig::CONTEXT, [self::TYPES_CONFIG_KEY]);
        $raw = (string) ($values[self::TYPES_CONFIG_KEY] ?? '');
        $decoded = $raw === '' ? null : json_decode($raw, true);
        $types = is_array($decoded) ? RecoveryExerciseRules::sanitizeTypes($decoded) : [];

        return $types !== [] ? $types : RecoveryExerciseRules::DEFAULT_TYPES;
    }

    /**
     * @param list<string> $types
     */
    public static function saveExerciseTypes(array $types): void
    {
        $types = RecoveryExerciseRules::sanitizeTypes($types);
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [
            self::TYPES_CONFIG_KEY => (string) json_encode($types),
        ]);
        AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => [self::TYPES_CONFIG_KEY => $types],
        ]);
    }

    /**
     * Libellé d'un type : traduit pour un type par défaut, tel que saisi sinon.
     */
    public static function typeLabel(string $type): string
    {
        return self::defaultTypeLabels()[$type] ?? $type;
    }

    /**
     * Types proposés à la saisie, plus le type actuel s'il n'est plus proposé (jamais perdu en silence).
     *
     * @return array<string, string>
     */
    public static function typeChoices(string $current = ''): array
    {
        $choices = [];
        foreach (self::exerciseTypes() as $type) {
            $choices[$type] = self::typeLabel($type);
        }
        if ($current !== '' && !isset($choices[$current])) {
            $choices[$current] = self::typeLabel($current);
        }

        return $choices;
    }

    /**
     * Réglage des types (configuration du plugin) : types par défaut cochés ou non, autres types.
     *
     * @return array{defaults: list<array{key: string, label: string, checked: bool}>, others: string}
     */
    public static function typesConfigForm(): array
    {
        $types = self::exerciseTypes();
        $defaults = [];
        foreach (self::defaultTypeLabels() as $key => $label) {
            $defaults[] = ['key' => $key, 'label' => $label, 'checked' => in_array($key, $types, true)];
        }

        return ['defaults' => $defaults, 'others' => implode("\n", RecoveryExerciseRules::customTypes($types))];
    }

    // ------------------------------------------------------------------
    // Issue #733 : élément concerné d'un test
    // ------------------------------------------------------------------

    /**
     * Élément lisible d'un type géré par les dépendances des processus, ou null.
     */
    private static function readableItem(string $itemtype, int $itemsId): ?CommonDBTM
    {
        if ($itemsId <= 0 || !in_array($itemtype, BusinessProcessItem::getItemtypes(), true)) {
            return null;
        }
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return null;
        }

        return $item;
    }

    private static function itemLabel(CommonDBTM $item): string
    {
        return sprintf('%s - %s', $item::getTypeName(1), $item->getName());
    }

    /**
     * Éléments dont dépend le processus et que la personne peut lire, pour désigner l'élément testé.
     *
     * @return array<string, string> « Itemtype:id » => libellé
     */
    public static function assetChoices(BusinessProcess $process): array
    {
        $choices = [];
        foreach (BusinessProcessItem::getForProcess((int) $process->getID()) as $link) {
            $item = self::readableItem((string) $link['itemtype'], (int) $link['items_id']);
            if ($item !== null) {
                $choices[$item::class . ':' . $item->getID()] = self::itemLabel($item);
            }
        }
        asort($choices);

        return $choices;
    }

    /**
     * Élément choisi pour un test : une dépendance lisible du processus.
     *
     * @return array{0: string, 1: int}|null ['', 0] sans élément, null si le choix est refusé
     */
    private static function chosenAsset(BusinessProcess $process, mixed $value): ?array
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $value === '0') {
            return ['', 0];
        }
        if (!array_key_exists($value, self::assetChoices($process))) {
            return null;
        }
        [$itemtype, $itemsId] = explode(':', $value, 2);

        return [$itemtype, (int) $itemsId];
    }

    /**
     * Résultat d'une ligne de test face aux objectifs du processus (RTO, RPO) et, si un élément est
     * désigné, à ceux de l'élément (classification C/I/D).
     *
     * @param array<string, mixed> $row ligne de test
     * @return array<string, mixed>
     */
    private static function evaluate(array $row, int $rtoHours, int $rpoHours): array
    {
        $minutes = (int) $row['measured_minutes'];
        $rpo = $row['measured_rpo_minutes'] === null || $row['measured_rpo_minutes'] === ''
            ? null : (int) $row['measured_rpo_minutes'];
        $exceedsRto = RecoveryStrategyRules::exceedsRto($rtoHours, $minutes);
        $exceedsRpo = RecoveryExerciseRules::exceedsRpo($rpoHours, $rpo);
        $assetExceeds = false;
        $itemtype = (string) ($row['itemtype'] ?? '');
        $itemsId = (int) ($row['items_id'] ?? 0);
        if ($itemtype !== '' && $itemsId > 0) {
            $objectives = AssetClassification::getByItem($itemtype, $itemsId);
            if ($objectives !== null) {
                $assetExceeds = RecoveryStrategyRules::exceedsRto((int) $objectives['rto_hours'], $minutes)
                    || RecoveryExerciseRules::exceedsRpo((int) $objectives['rpo_hours'], $rpo);
            }
        }

        return [
            'minutes'       => $minutes,
            'duration'      => self::formatMinutes($minutes),
            'exceeds'       => $exceedsRto,
            'rpo_minutes'   => $rpo,
            'rpo_duration'  => $rpo === null ? '' : self::formatMinutes($rpo),
            'rpo_exceeds'   => $exceedsRpo,
            'asset_exceeds' => $assetExceeds,
            'result'        => RecoveryExerciseRules::testResult($exceedsRto || $assetExceeds, $exceedsRpo),
        ];
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
     * Tests d'un processus, du plus récent au plus ancien. L'exercice et l'élément ne sont nommés que
     * s'ils sont lisibles.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForProcess(BusinessProcess $process): array
    {
        $rto = (int) ($process->fields['rto_hours'] ?? 0);
        $rpo = (int) ($process->fields['rpo_hours'] ?? 0);
        $rows = [];
        foreach (
            (new self())->find(
                ['plugin_grcmanager_businessprocesses_id' => (int) $process->getID()],
                ['test_date DESC', 'id DESC']
            ) as $row
        ) {
            $exerciseId = (int) $row[self::EXERCISE_FIELD];
            $exercise = self::readableExercise($exerciseId);
            $item = self::readableItem((string) $row['itemtype'], (int) $row['items_id']);
            $rows[] = [
                'id'              => (int) $row['id'],
                'date'            => Html::convDate((string) $row['test_date']),
                'test_date'       => (string) $row['test_date'],
                'type'            => self::typeLabel((string) $row['exercise_type']),
                'exercise'        => $exercise !== null ? self::exerciseLabel($exercise) : '',
                'exercise_url'    => $exercise !== null ? CrisisExercise::getFormURLWithID($exerciseId) : '',
                'exercise_hidden' => $exerciseId > 0 && $exercise === null,
                'asset'           => $item !== null ? self::itemLabel($item) : '',
                'asset_url'       => $item?->getLinkURL() ?? '',
                'by'              => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'comment'         => (string) $row['comment'],
            ] + self::evaluate($row, $rto, $rpo);
        }

        return $rows;
    }

    /**
     * Dernier test du processus (ligne brute), ou null s'il n'a jamais été testé.
     *
     * @return array<string, mixed>|null
     */
    public static function lastTest(int $processId): ?array
    {
        return RecoveryStrategyRules::lastTest(
            (new self())->find(['plugin_grcmanager_businessprocesses_id' => $processId])
        );
    }

    /**
     * Temps de reprise mesuré au dernier test du processus, en minutes, ou null s'il n'a jamais été testé.
     */
    public static function lastMeasured(int $processId): ?int
    {
        $last = self::lastTest($processId);

        return $last === null ? null : (int) $last['measured_minutes'];
    }

    /**
     * Issue #733 : perte de données mesurée au dernier test, en minutes, ou null (jamais testé ou non
     * mesurée).
     */
    public static function lastMeasuredRpo(int $processId): ?int
    {
        $last = self::lastTest($processId);

        return $last === null ? null : RecoveryExerciseRules::sanitizeRpoMinutes($last['measured_rpo_minutes']);
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
        $issues = RecoveryStrategyRules::strategyIssues(
            (int) ($process->fields['rto_hours'] ?? 0),
            array_values($texts),
            $last === null ? null : (int) $last['minutes']
        );
        if ($last !== null && $last['rpo_exceeds']) {
            $issues[] = 'recovery_above_rpo';
        }

        return $texts + [
            'tests'     => $tests,
            'last'      => $last,
            'issues'    => $issues,
            'programme' => self::processProgramme($process, $tests, date('Y-m-d')),
        ];
    }

    /**
     * Issue #733 : programme de tests d'un processus : fréquence, prochaine échéance et couverture
     * pluriannuelle.
     *
     * @param list<array<string, mixed>> $tests tests évalués (getForProcess()), du plus récent au plus ancien
     * @return array<string, mixed>
     */
    public static function processProgramme(BusinessProcess $process, array $tests, string $today): array
    {
        $default = BusinessProcessReminder::frequency(BusinessProcessReminder::RECOVERY_REVIEW);
        $own = RecoveryExerciseRules::sanitizeFrequencyMonths($process->fields['recovery_test_months'] ?? 0);
        $due = BusinessProcessReminder::recoveryDueDate(
            $tests[0]['test_date'] ?? null,
            RecoveryExerciseRules::effectiveMonths($own, $default),
            (string) ($process->fields['date_creation'] ?? '')
        );
        $years = RecoveryExerciseRules::coverageYears((int) substr($today, 0, 4));

        return [
            'own_months'     => $own,
            'default_months' => $default,
            'months'         => RecoveryExerciseRules::effectiveMonths($own, $default),
            'due_date'       => $due,
            'due_label'      => $due === null ? '' : Html::convDate($due),
            'due_status'     => ControlTestRules::dueStatus($due, $today),
            'years'          => $years,
            'coverage'       => RecoveryExerciseRules::yearlyCoverage($tests, $years),
        ];
    }

    /**
     * Issue #733 : programme de tests de reprise de tous les processus lisibles (entités de la
     * session) : prochaine échéance et couverture pluriannuelle. Lecture seule : rien n'est modifié.
     *
     * @return array{years: list<int>, rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function programme(string $today): array
    {
        $years = RecoveryExerciseRules::coverageYears((int) substr($today, 0, 4));
        $result = [
            'years'  => $years,
            'rows'   => [],
            'counts' => ['total' => 0, 'overdue' => 0, 'untested_year' => 0, 'failed' => 0],
        ];
        if (!BusinessProcess::canView()) {
            return $result;
        }
        $process = new BusinessProcess();
        $criteria = getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true);
        foreach ((new BusinessProcess())->find($criteria, ['name ASC'], 1000) as $row) {
            if (!$process->getFromDB((int) $row['id']) || !$process->canViewItem()) {
                continue;
            }
            $tests = self::getForProcess($process);
            $programme = self::processProgramme($process, $tests, $today);
            $current = $programme['coverage'][end($years)] ?? RecoveryExerciseRules::RESULT_UNTESTED;
            $result['counts']['total']++;
            if ($programme['due_status'] === ControlTestRules::DUE_OVERDUE) {
                $result['counts']['overdue']++;
            }
            if ($current === RecoveryExerciseRules::RESULT_UNTESTED) {
                $result['counts']['untested_year']++;
            }
            if (($tests[0]['result'] ?? '') === RecoveryExerciseRules::RESULT_FAILED) {
                $result['counts']['failed']++;
            }
            $result['rows'][] = $programme + [
                'name'  => (string) $process->fields['name'],
                'url'   => BusinessProcess::getFormURLWithID((int) $process->getID())
                    . '&forcetab=' . rawurlencode(self::class . '$1'),
                'last'  => $tests[0] ?? null,
                'count' => count($tests),
            ];
        }

        return $result;
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
            $rpo = (int) $process->fields['rpo_hours'];
            $item = self::readableItem((string) $row['itemtype'], (int) $row['items_id']);
            $rows[] = [
                'name'    => (string) $process->fields['name'],
                'url'     => $process->getLinkURL(),
                'rto'     => $durations[$rto] ?? '',
                'rpo'     => $durations[$rpo] ?? '',
                'type'    => self::typeLabel((string) $row['exercise_type']),
                'asset'   => $item !== null ? self::itemLabel($item) : '',
                'comment' => (string) $row['comment'],
            ] + self::evaluate($row, $rto, $rpo);
        }

        return ['rows' => $rows, 'hidden' => $hidden];
    }

    /**
     * Issue #733 : historique des tests de reprise d'un élément : tests qui le désignent et tests des
     * processus lisibles qui en dépendent, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public static function forItem(string $itemtype, int $itemsId): array
    {
        global $DB;

        if (!BusinessProcess::canView()) {
            return [];
        }
        $processIds = [];
        foreach ((new BusinessProcessItem())->find(['itemtype' => $itemtype, 'items_id' => $itemsId]) as $link) {
            $processIds[] = (int) $link['plugin_grcmanager_businessprocesses_id'];
        }
        $where = ['itemtype' => $itemtype, 'items_id' => $itemsId];
        if ($processIds !== []) {
            $where = ['OR' => [
                $where,
                ['plugin_grcmanager_businessprocesses_id' => array_values(array_unique($processIds))],
            ]];
        }
        $durations = BusinessProcess::getDurationOptions();
        $process = new BusinessProcess();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['test_date DESC', 'id DESC'],
                'LIMIT' => self::MAX_ITEM_TESTS,
            ]) as $row
        ) {
            if (
                !$process->getFromDB((int) $row['plugin_grcmanager_businessprocesses_id'])
                || !$process->canViewItem()
            ) {
                continue;
            }
            $rto = (int) $process->fields['rto_hours'];
            $rpo = (int) $process->fields['rpo_hours'];
            $exercise = self::readableExercise((int) $row[self::EXERCISE_FIELD]);
            $rows[] = [
                'date'     => Html::convDate((string) $row['test_date']),
                'process'  => (string) $process->fields['name'],
                'url'      => $process->getLinkURL(),
                'rto'      => $durations[$rto] ?? '',
                'rpo'      => $durations[$rpo] ?? '',
                'type'     => self::typeLabel((string) $row['exercise_type']),
                'exercise' => $exercise !== null ? self::exerciseLabel($exercise) : '',
                'direct'   => (string) $row['itemtype'] === $itemtype && (int) $row['items_id'] === $itemsId,
            ] + self::evaluate($row, $rto, $rpo);
        }

        return $rows;
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
            // Issue #733 : type d'exercice, élément concerné et fréquence de test.
            'types'     => $item->canUpdateItem() ? self::typeChoices() : [],
            'assets'    => $item->canUpdateItem() ? self::assetChoices($item) : [],
            'due_labels' => self::dueLabels(),
            'result_labels' => self::resultLabels(),
            'max_frequency' => RecoveryExerciseRules::MAX_FREQUENCY_MONTHS,
            'can_edit'  => $item->canUpdateItem(),
            'process_url' => BusinessProcess::getFormURL(),
            'form_url'  => self::getFormURL(),
            'today'     => date('Y-m-d'),
        ]);

        return true;
    }

    /**
     * Issue #733 : libellés et badges des échéances du programme.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> état => [classes, icône, libellé]
     */
    public static function dueLabels(): array
    {
        return [
            ControlTestRules::DUE_OVERDUE => [
                'bg-danger-subtle text-danger-emphasis', 'ti-alert-triangle', __('Overdue', 'grcmanager'),
            ],
            ControlTestRules::DUE_SOON    => [
                'bg-warning-subtle text-warning-emphasis', 'ti-clock', __('Due soon', 'grcmanager'),
            ],
            ControlTestRules::DUE_PLANNED => [
                'bg-success-subtle text-success-emphasis', 'ti-calendar-check', __('Planned', 'grcmanager'),
            ],
            ControlTestRules::DUE_NONE    => [
                'bg-secondary-subtle text-secondary-emphasis', 'ti-calendar-off', __('Not scheduled', 'grcmanager'),
            ],
        ];
    }

    /**
     * Issue #733 : libellés et badges du résultat d'un test ou d'une année de la couverture.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> résultat => [classes, icône, libellé]
     */
    public static function resultLabels(): array
    {
        return [
            RecoveryExerciseRules::RESULT_PASSED   => [
                'bg-success-subtle text-success-emphasis', 'ti-circle-check', __('Objectives met', 'grcmanager'),
            ],
            RecoveryExerciseRules::RESULT_FAILED   => [
                'bg-danger-subtle text-danger-emphasis', 'ti-circle-x', __('Objectives missed', 'grcmanager'),
            ],
            RecoveryExerciseRules::RESULT_UNTESTED => [
                'bg-secondary-subtle text-secondary-emphasis', 'ti-circle-dashed', __('Not tested', 'grcmanager'),
            ],
        ];
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
        // Issue #733 : élément concerné, parmi les dépendances lisibles du processus.
        $asset = self::chosenAsset($process, $input['asset'] ?? '');
        if ($asset === null) {
            return false;
        }
        // Issue #733 : type choisi, sinon celui de l'exercice.
        $type = RecoveryExerciseRules::chooseType($input['exercise_type'] ?? '', self::exerciseTypes());
        if ($type === '' && $exercise !== null) {
            $type = (string) ($exercise->fields['exercise_type'] ?? '');
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
            'measured_rpo_minutes'                   => RecoveryExerciseRules::sanitizeRpoMinutes(
                $input['measured_rpo_minutes'] ?? null
            ),
            'exercise_type'                          => $type,
            'itemtype'                               => $asset[0],
            'items_id'                               => $asset[1],
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
