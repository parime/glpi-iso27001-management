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

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Grcmanager\Compatibility\Base\TrainingBase;
use GlpiPlugin\Grcmanager\Services\Training\TrainingRenewalService;
use GlpiPlugin\Grcmanager\Services\Training\TrainingScore;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * Security awareness training tracking (ISO 27001 clauses 7.2 "competence" and 7.3 "awareness"):
 * one row per training session/campaign (title, format, target audience, date delivered), with
 * per-participant completion tracking recorded on the many-to-many link table
 * glpi_plugin_grcmanager_trainings_users (real GLPI `User`s, never a parallel user concept of this
 * plugin's own), same "direct $DB access, not a real CommonDBRelation" simplification already
 * assumed for every other many-to-many link in this plugin family (see TECH_DEBT.md Sprint 3/4),
 * extended here to also carry two extra columns per pair (`completion_status`/`completion_date`)
 * rather than being a bare link, since an auditor needs to see who specifically completed what and
 * when, not just an aggregate headcount.
 *
 * A training can optionally require periodic renewal (`renewal_period_months` > 0): a participant
 * whose last completion is older than that window is "overdue for renewal", the definition shared
 * by the dashboard card (DashboardCardService::trainingOverdueRenewalCount()) and the daily Cron
 * reminder below (cronRenewaldue()), so the two can never drift apart on what counts as overdue.
 */
class PluginGrcmanagerTraining extends TrainingBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    private const PARTICIPANTS_TABLE = 'glpi_plugin_grcmanager_trainings_users';

    /**
     * GLPI notification event name (see inc/notificationtargettraining.class.php and
     * src/Services/Training/TrainingRenewalService.php), same single-source-of-truth pattern as
     * PluginGrcmanagerRisk::REVIEW_DUE_EVENT.
     */
    public const RENEWAL_DUE_EVENT = 'training_renewal_due';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_trainings';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Training', 'Trainings', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-school';
    }

    /**
     * @return array<string, string>
     */
    public static function getFormats(): array
    {
        return [
            'in_person' => __('In person', 'grcmanager'),
            'e_learning' => __('E-learning', 'grcmanager'),
            'other' => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCompletionStatuses(): array
    {
        return [
            'pending'   => __('Pending', 'grcmanager'),
            'completed' => _x('feminine', 'Completed', 'grcmanager'),
            'exempted'  => __('Exempted', 'grcmanager'),
        ];
    }

    /**
     * Independent axis from `completion_status` above: a training can be "completed" with no
     * evaluation attached at all (`not_applicable`, the default - existing rows are never
     * retroactively marked passed/failed), or completed AND assessed (quiz, practical test...).
     * Kept as a separate column rather than folded into `completion_status` because the two
     * questions are genuinely independent: "did they attend" vs. "did they demonstrate the
     * competency", and a training with no formal assessment should never be forced to say either.
     *
     * @return array<string, string>
     */
    public static function getAssessmentResults(): array
    {
        return [
            'not_applicable' => _x('no assessment', 'Not assessed', 'grcmanager'),
            'passed'         => __('Passed', 'grcmanager'),
            'failed'         => __('Failed', 'grcmanager'),
        ];
    }

    /**
     * Normalizes `renewal_period_months`/`is_mandatory` posted from the form, and syncs the
     * participant set + per-participant completion status/date the same "single normalize
     * function called from both prepare hooks" convention as PluginGrcmanagerAudit's own
     * normalizeInput(). The actual sync happens in post_addItem()/post_updateItem() below, once a
     * real ID exists, not here (mirrors PluginGrcmanagerAudit::syncLinkedControls() timing exactly).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (isset($input['renewal_period_months'])) {
            $input['renewal_period_months'] = max(0, (int) $input['renewal_period_months']);
        }

        if (isset($input['is_mandatory'])) {
            $input['is_mandatory'] = empty($input['is_mandatory']) ? 0 : 1;
        }

        // Issue #139 : note maximale du quiz et seuil de réussite (en %).
        if (isset($input['max_score'])) {
            $input['max_score'] = max(0, (int) $input['max_score']);
        }
        if (isset($input['pass_threshold'])) {
            $input['pass_threshold'] = min(100, max(0, (int) $input['pass_threshold']));
        }

        // Issue #139 : une session se rattache à une formation principale, jamais à elle-même ni à
        // une autre session (on remonte alors à la formation principale de celle-ci).
        if (isset($input['plugin_grcmanager_trainings_id'])) {
            $input['plugin_grcmanager_trainings_id'] = self::mainTrainingId(
                (int) $input['plugin_grcmanager_trainings_id'],
                (int) ($input['id'] ?? ($this->fields['id'] ?? 0))
            );
        }

        return $input;
    }

    /**
     * Formation principale à laquelle rattacher une session : $parentId, ou sa propre formation
     * principale si c'est déjà une session ; 0 si invalide ou si c'est la formation elle-même.
     */
    public static function mainTrainingId(int $parentId, int $selfId = 0): int
    {
        $parent = new self();
        if ($parentId <= 0 || $parentId === $selfId || !$parent->getFromDB($parentId)) {
            return 0;
        }
        $grandParent = (int) ($parent->fields['plugin_grcmanager_trainings_id'] ?? 0);
        if ($grandParent > 0) {
            return $grandParent === $selfId ? 0 : $grandParent;
        }
        // Une formation qui a déjà des sessions reste une formation principale.
        if ($selfId > 0 && countElementsInTable(self::getTable(), ['plugin_grcmanager_trainings_id' => $selfId]) > 0) {
            return 0;
        }

        return $parentId;
    }

    /**
     * Formations principales, pour le choix du rattachement d'une session.
     *
     * @return array<int, string>
     */
    public static function getMainTrainings(int $excludeId = 0): array
    {
        global $DB;

        $trainings = [0 => __('None (main training)', 'grcmanager')];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_grcmanager_trainings_id' => 0, ['NOT' => ['id' => $excludeId]]],
                'ORDER'  => 'title',
            ]) as $row
        ) {
            $trainings[(int) $row['id']] = (string) $row['title'];
        }

        return $trainings;
    }

    /**
     * Sessions rattachées à une formation principale.
     *
     * @return list<array<string, mixed>>
     */
    public static function getSessions(int $mainId): array
    {
        global $DB;

        $sessions = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_trainings_id' => $mainId],
                'ORDER' => ['date_delivered', 'id'],
            ]) as $row
        ) {
            $participants = self::getParticipants((int) $row['id']);
            $sessions[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'date'         => $row['date_delivered'] ? Html::convDate($row['date_delivered']) : '',
                'trainer'      => $row['users_id_trainer'] ? getUserName((int) $row['users_id_trainer']) : '',
                'location'     => (string) ($row['location'] ?? ''),
                'participants' => count($participants),
                'rate'         => TrainingScore::sessionRate(array_column($participants, 'assessment_result')),
                'url'          => self::getFormURLWithID((int) $row['id']),
            ];
        }

        return $sessions;
    }

    /**
     * Taux de réussite de la formation : participants évalués de la formation principale et de
     * toutes ses sessions.
     *
     * @return array{passed: int, assessed: int, rate: ?int}
     */
    public static function getProgramRate(int $mainId): array
    {
        $results = array_column(self::getParticipants($mainId), 'assessment_result');
        foreach (self::getSessions($mainId) as $session) {
            $results = array_merge($results, array_column(self::getParticipants($session['id']), 'assessment_result'));
        }

        return TrainingScore::sessionRate($results);
    }

    /**
     * Issue #139 : une formation principale supprimée définitivement libère ses sessions, qui
     * deviennent des formations principales.
     */
    public function cleanDBonPurge()
    {
        global $DB;

        $DB->update(self::getTable(), ['plugin_grcmanager_trainings_id' => 0], [
            'plugin_grcmanager_trainings_id' => $this->getID(),
        ]);
        foreach (self::getParticipantIds($this->getID()) as $usersId) {
            PluginGrcmanagerTrainingQuiz::deleteCopyOf($this->getID(), $usersId);
        }
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        // Issue #139 : feuille d'émargement, support et quiz de la session.
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(PluginGrcmanagerTrainingSession::class, $tabs, $options)
            ->addStandardTab(PluginGrcmanagerTrainingQuiz::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function post_addItem()
    {
        parent::post_addItem();

        $this->syncParticipants((int) $this->fields['id']);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        $this->syncParticipants((int) $this->fields['id']);
    }

    /**
     * Applies the form's `participants` multi-select (the full intended attendee set) and the
     * per-row `participant_status`/`participant_date` arrays (only present for participants
     * already linked before this save, see showForm()) in one pass: newly selected users are
     * inserted as "pending", deselected users are removed (their completion history is lost, a
     * deliberate simplification documented in TECH_DEBT.md Sprint 6, same spirit as
     * PluginGrcmanagerAudit's own syncLinkedControls() delete-then-reinsert approach), and every
     * remaining participant's status/date is updated from the posted arrays when present.
     */
    private function syncParticipants(int $trainingId): void
    {
        global $DB;

        if (!array_key_exists('participants', $this->input)) {
            return;
        }

        $selectedIds = array_unique(array_filter(
            array_map('intval', (array) $this->input['participants']),
            static fn ($id) => $id > 0
        ));

        $existingIds = self::getParticipantIds($trainingId);

        foreach (array_diff($existingIds, $selectedIds) as $removedId) {
            PluginGrcmanagerTrainingQuiz::deleteCopyOf($trainingId, $removedId);
            $DB->delete(self::PARTICIPANTS_TABLE, [
                'plugin_grcmanager_trainings_id' => $trainingId,
                'users_id'                       => $removedId,
            ]);
        }

        foreach (array_diff($selectedIds, $existingIds) as $addedId) {
            $DB->insert(self::PARTICIPANTS_TABLE, [
                'plugin_grcmanager_trainings_id' => $trainingId,
                'users_id'                       => $addedId,
                'completion_status'              => 'pending',
                'assessment_result'              => 'not_applicable',
                'date_creation'                  => date('Y-m-d H:i:s'),
            ]);
        }

        $statuses = (array) ($this->input['participant_status'] ?? []);
        $dates    = (array) ($this->input['participant_date'] ?? []);
        $results  = (array) ($this->input['participant_result'] ?? []);
        $scores   = (array) ($this->input['participant_score'] ?? []);
        $maxScore = (int) ($this->fields['max_score'] ?? 0);
        $threshold = (int) ($this->fields['pass_threshold'] ?? 0);

        foreach (array_intersect($selectedIds, $existingIds) as $userId) {
            if (!isset($statuses[$userId])) {
                continue;
            }

            // Statut et date validés comme le résultat : un statut inconnu fausserait les taux
            // (maturité, tableau de bord) et une date invalide ferait échouer l'enregistrement en
            // mode SQL strict.
            $status = (string) $statuses[$userId];
            if (!array_key_exists($status, self::getCompletionStatuses())) {
                continue;
            }
            $date   = trim((string) ($dates[$userId] ?? ''));
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            if ($date !== '' && ($parsed === false || $parsed->format('Y-m-d') !== $date)) {
                $date = '';
            }
            $result = (string) ($results[$userId] ?? 'not_applicable');
            if (!array_key_exists($result, self::getAssessmentResults())) {
                $result = 'not_applicable';
            }

            if ($status === 'completed' && $date === '') {
                $date = date('Y-m-d');
            }

            // Issue #139 : formation notée, le résultat se déduit de la note et du seuil.
            $score = null;
            if (TrainingScore::isScored($maxScore, $threshold)) {
                $score = TrainingScore::parse((string) ($scores[$userId] ?? ''), $maxScore);
                $result = TrainingScore::result($score, $maxScore, $threshold) ?? 'not_applicable';
            }

            $DB->update(self::PARTICIPANTS_TABLE, [
                'completion_status' => $status,
                'completion_date'   => $date !== '' ? $date : null,
                'assessment_result' => $result,
                'score'             => $score,
            ], [
                'plugin_grcmanager_trainings_id' => $trainingId,
                'users_id'                       => $userId,
            ]);
        }
    }

    /**
     * @return array<int, int> the linked participant user IDs.
     */
    public static function getParticipantIds(int $trainingId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => self::PARTICIPANTS_TABLE,
                'WHERE'  => ['plugin_grcmanager_trainings_id' => $trainingId],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /**
     * @return array<int, array{
     *     users_id: int, name: string, completion_status: string, completion_date: ?string,
     *     assessment_result: string, score: ?float,
     * }>
     */
    public static function getParticipants(int $trainingId): array
    {
        global $DB;

        $participants = [];

        $rows = $DB->request([
            'SELECT'     => [
                'links.users_id', 'links.completion_status', 'links.completion_date',
                'links.assessment_result', 'links.score', 'u.name',
            ],
            'FROM'       => self::PARTICIPANTS_TABLE . ' AS links',
            'INNER JOIN' => [
                'glpi_users AS u' => [
                    'FKEY' => ['links' => 'users_id', 'u' => 'id'],
                ],
            ],
            'WHERE'      => ['links.plugin_grcmanager_trainings_id' => $trainingId],
            'ORDER'      => 'u.name ASC',
        ]);

        foreach ($rows as $row) {
            $participants[] = [
                'users_id'          => (int) $row['users_id'],
                'name'              => (string) $row['name'],
                'completion_status' => (string) $row['completion_status'],
                'completion_date'   => $row['completion_date'] !== null ? (string) $row['completion_date'] : null,
                'assessment_result' => (string) ($row['assessment_result'] ?? 'not_applicable'),
                'score'             => $row['score'] !== null ? (float) $row['score'] : null,
            ];
        }

        return $participants;
    }

    /**
     * @return array<int, string> user id => display name, for every user overdue for renewal on
     *                            this specific training (completed, renewal required, past the
     *                            renewal window). Shared by TrainingRenewalService (which decides
     *                            whether to raise a notification for this training at all) and
     *                            inc/notificationtargettraining.class.php (which resolves the
     *                            actual per-training recipient list), so the two never drift apart
     *                            on the definition.
     */
    public static function getOverdueParticipants(int $trainingId): array
    {
        global $DB;

        $overdue = [];

        $rows = $DB->request([
            'SELECT'     => ['links.users_id', 'u.name'],
            'FROM'       => self::PARTICIPANTS_TABLE . ' AS links',
            'INNER JOIN' => [
                'glpi_users AS u' => [
                    'FKEY' => ['links' => 'users_id', 'u' => 'id'],
                ],
                self::getTable() . ' AS t' => [
                    'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                ],
            ],
            'WHERE'      => [
                'links.plugin_grcmanager_trainings_id' => $trainingId,
                'links.completion_status'              => 'completed',
                't.renewal_period_months'              => ['>', 0],
                new QueryExpression(
                    'DATE_ADD(links.completion_date, INTERVAL t.renewal_period_months MONTH) < CURDATE()'
                ),
            ],
        ]);

        foreach ($rows as $row) {
            $overdue[(int) $row['users_id']] = (string) $row['name'];
        }

        return $overdue;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'title',
            'name'     => __('Title', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'format',
            'name'     => __('Format', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'target_audience',
            'name'     => __('Target audience', 'grcmanager'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'date_delivered',
            'name'     => __('Completion date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'is_mandatory',
            'name'     => __('Mandatory', 'grcmanager'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'renewal_period_months',
            'name'     => __('Renewal (months)', 'grcmanager'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family.
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // Issue #139 : formateur et lieu de la session.
        $tab[] = [
            'id'        => 9,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id_trainer',
            'name'      => __('Trainer', 'grcmanager'),
            'datatype'  => 'dropdown',
            'right'     => 'all',
        ];
        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'location',
            'name'     => __('Location or link', 'grcmanager'),
            'datatype' => 'string',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'format':
                return self::formatBadge($values[$field] ?? null);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widget for `format`, same lesson as every other fixed-enum column in
     * this plugin family (see PluginGrcmanagerAudit::getSpecificValueToSelect()).
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'format':
                return Dropdown::showFromArray($name, self::getFormats(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function formatBadge(?string $value): string
    {
        $map = [
            'in_person'  => ['bg-blue-lt', 'ti-users', __('In person', 'grcmanager')],
            'e_learning' => ['bg-azure-lt', 'ti-device-laptop', __('E-learning', 'grcmanager')],
            'other'      => ['bg-secondary-lt', 'ti-dots', __('Other', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }


    /**
     * Participants ayant échoué à cette session (issue #139), proposés pour une session de
     * rattrapage.
     *
     * @return list<int>
     */
    public static function getRetakeParticipantIds(int $trainingId): array
    {
        global $DB;

        if ($trainingId <= 0) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => self::PARTICIPANTS_TABLE,
                'WHERE'  => ['plugin_grcmanager_trainings_id' => $trainingId, 'assessment_result' => 'failed'],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    public static function getReportURL(int $id): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/training.report.php?id=' . $id;
    }

    /**
     * Issue #139, phase 2 : rapport PDF d'une session (émargement avec une colonne de signature,
     * notes, résultats et taux de réussite).
     */
    public static function renderSessionReport(self $training): string
    {
        $maxScore = (int) ($training->fields['max_score'] ?? 0);
        $statuses = self::getCompletionStatuses();
        $results = self::getAssessmentResults();
        $participants = self::getParticipants($training->getID());
        $rows = [];
        foreach ($participants as $p) {
            $rows[] = [
                'name'         => getUserName($p['users_id']),
                'status_label' => $statuses[$p['completion_status']] ?? $p['completion_status'],
                'date_label'   => $p['completion_date'] !== null ? Html::convDate($p['completion_date']) : '',
                'score_label'  => $p['score'] !== null && $maxScore > 0
                    ? TrainingScore::format($p['score'], $maxScore)
                    : '',
                'result_label' => $p['assessment_result'] === 'failed'
                    ? __('To retake', 'grcmanager')
                    : ($results[$p['assessment_result']] ?? ''),
            ];
        }

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/training_session_report.html.twig', [
            'report_title'       => sprintf(__('Training session: %s', 'grcmanager'), $training->fields['title']),
            'generated_at_label' => sprintf(__(
                'Generated on %s',
                'grcmanager'
            ), Html::convDateTime(date('Y-m-d H:i:s'))),
            'training'           => $training->fields,
            'format'             => self::getFormats()[$training->fields['format']] ?? '',
            'date_delivered'     => Html::convDate($training->fields['date_delivered']),
            'is_scored'          => TrainingScore::isScored(
                $maxScore,
                (int) ($training->fields['pass_threshold'] ?? 0)
            ),
            'participants'       => $rows,
            'session_rate'       => TrainingScore::sessionRate(array_column($participants, 'assessment_result')),
        ]);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $participants = $this->isNewID($ID) ? [] : self::getParticipants((int) $ID);
        $participantIds = array_column($participants, 'users_id');
        // Issue #139 : session de rattrapage proposée, rien n'est enregistré avant « Ajouter ».
        $retakeOf = $this->isNewItem() ? (int) ($options['retake_of'] ?? 0) : 0;
        if ($retakeOf > 0 && ($source = new self())->getFromDB($retakeOf)) {
            $copied = [
                'title', 'format', 'target_audience', 'is_mandatory', 'renewal_period_months',
                'max_score', 'pass_threshold',
            ];
            foreach ($copied as $field) {
                $this->fields[$field] = $source->fields[$field];
            }
            $this->fields['title'] = sprintf(__('%s - retake', 'grcmanager'), $source->fields['title']);
            $participantIds = self::getRetakeParticipantIds($retakeOf);
            $this->fields['plugin_grcmanager_trainings_id'] = self::mainTrainingId($retakeOf);
        }
        // Issue #139 : nouvelle session d'une formation, rien n'est enregistré avant « Ajouter ».
        $sessionOf = $this->isNewItem() ? (int) ($options['session_of'] ?? 0) : 0;
        if ($sessionOf > 0 && ($main = new self())->getFromDB($sessionOf)) {
            $copied = [
                'title', 'format', 'target_audience', 'is_mandatory', 'renewal_period_months',
                'max_score', 'pass_threshold', 'description',
            ];
            foreach ($copied as $field) {
                $this->fields[$field] = $main->fields[$field];
            }
            $this->fields['plugin_grcmanager_trainings_id'] = self::mainTrainingId($sessionOf);
        }
        $mainId = (int) ($this->fields['plugin_grcmanager_trainings_id'] ?? 0);
        $maxScore = (int) ($this->fields['max_score'] ?? 0);
        foreach ($participants as &$participant) {
            $hasScore = $participant['score'] !== null && $maxScore > 0;
            $participant['score_label'] = $hasScore ? TrainingScore::format($participant['score'], $maxScore) : '';
            $participant['score_input'] = $participant['score'] !== null
                ? TrainingScore::formatValue($participant['score'])
                : '';
        }
        unset($participant);

        TemplateRenderer::getInstance()->display('@grcmanager/training_form.html.twig', [
            'item'                => $this,
            'params'              => $options,
            'formats'             => self::getFormats(),
            // Nouvelle formation : obligatoire par défaut, comme la valeur par défaut en base.
            'is_mandatory'        => $this->isNewItem()
                ? (($retakeOf <= 0 && $sessionOf <= 0) || !empty($this->fields['is_mandatory']))
                : !empty($this->fields['is_mandatory']),
            'participant_ids'     => $participantIds,
            'participants'        => $participants,
            'user_label'          => User::getTypeName(1),
            'completion_statuses' => self::getCompletionStatuses(),
            'assessment_results'  => self::getAssessmentResults(),
            'is_scored'           => TrainingScore::isScored($maxScore, (int) ($this->fields['pass_threshold'] ?? 0)),
            'session_rate'        => TrainingScore::sessionRate(array_column($participants, 'assessment_result')),
            'retake_count'        => count(self::getRetakeParticipantIds((int) $ID)),
            'retake_url'          => self::getFormURL() . '?retake_of=' . (int) $ID,
            'is_retake'           => $retakeOf > 0,
            'is_new_session'      => $sessionOf > 0,
            'main_trainings'      => self::getMainTrainings((int) $ID),
            'main_url'            => $mainId > 0 ? self::getFormURLWithID($mainId) : '',
            'report_url'          => self::getReportURL((int) $ID),
        ]);

        return true;
    }

    /**
     * GLPI Cron entry point, same structure as PluginGrcmanagerRisk::cronReviewreminder(). One
     * NotificationEvent per training that currently has at least one participant overdue for
     * renewal (see TrainingRenewalService, inc/notificationtargettraining.class.php, which resolves
     * the actual per-training recipient list from PluginGrcmanagerTraining::getOverdueParticipants()).
     *
     * @return int 0 if no training had an overdue participant, 1 otherwise
     */
    public static function cronRenewaldue(CronTask $task): int
    {
        $result = (new TrainingRenewalService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            '%d formation(s) avec au moins un participant en retard de renouvellement, '
            . '%d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
