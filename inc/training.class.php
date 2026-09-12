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

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Grcmanager\Services\Training\TrainingRenewalService;

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
class PluginGrcmanagerTraining extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

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
        return _n('Formation', 'Formations', $nb, 'grcmanager');
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
            'in_person' => __('Présentiel', 'grcmanager'),
            'e_learning' => __('E-learning', 'grcmanager'),
            'other' => __('Autre', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCompletionStatuses(): array
    {
        return [
            'pending'   => __('En attente', 'grcmanager'),
            'completed' => __('Terminée', 'grcmanager'),
            'exempted'  => __('Dispensé', 'grcmanager'),
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
            'not_applicable' => __('Sans évaluation', 'grcmanager'),
            'passed'         => __('Réussie', 'grcmanager'),
            'failed'         => __('Échouée', 'grcmanager'),
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

        return $input;
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

        foreach (array_intersect($selectedIds, $existingIds) as $userId) {
            if (!isset($statuses[$userId])) {
                continue;
            }

            $status = (string) $statuses[$userId];
            $date   = trim((string) ($dates[$userId] ?? ''));
            $result = (string) ($results[$userId] ?? 'not_applicable');
            if (!array_key_exists($result, self::getAssessmentResults())) {
                $result = 'not_applicable';
            }

            if ($status === 'completed' && $date === '') {
                $date = date('Y-m-d');
            }

            $DB->update(self::PARTICIPANTS_TABLE, [
                'completion_status' => $status,
                'completion_date'   => $date !== '' ? $date : null,
                'assessment_result' => $result,
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
     *     assessment_result: string,
     * }>
     */
    public static function getParticipants(int $trainingId): array
    {
        global $DB;

        $participants = [];

        $rows = $DB->request([
            'SELECT'     => [
                'links.users_id', 'links.completion_status', 'links.completion_date',
                'links.assessment_result', 'u.name',
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
            'name'     => __('Titre', 'grcmanager'),
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
            'name'     => __('Public cible', 'grcmanager'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'date_delivered',
            'name'     => __('Date de réalisation', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'is_mandatory',
            'name'     => __('Obligatoire', 'grcmanager'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'renewal_period_months',
            'name'     => __('Renouvellement (mois)', 'grcmanager'),
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
            'in_person'  => ['bg-blue-lt', 'ti-users', __('Présentiel', 'grcmanager')],
            'e_learning' => ['bg-azure-lt', 'ti-device-laptop', __('E-learning', 'grcmanager')],
            'other'      => ['bg-secondary-lt', 'ti-dots', __('Autre', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    public static function completionStatusBadge(?string $value): string
    {
        $map = [
            'pending'   => ['bg-yellow-lt', 'ti-clock', __('En attente', 'grcmanager')],
            'completed' => ['bg-green-lt', 'ti-check', __('Terminée', 'grcmanager')],
            'exempted'  => ['bg-secondary-lt', 'ti-forbid-2', __('Dispensé', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>' . __('Titre', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo Html::input('title', ['value' => $this->fields['title'] ?? '', 'size' => 80]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Format', 'grcmanager') . '</td><td>';
        Dropdown::showFromArray('format', self::getFormats(), [
            'value' => $this->fields['format'] ?? 'in_person',
        ]);
        echo '</td>';

        echo '<td>' . __('Date de réalisation', 'grcmanager') . '</td><td>';
        Html::showDateField('date_delivered', ['value' => $this->fields['date_delivered'] ?? '']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Public cible', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo Html::input('target_audience', [
            'value' => $this->fields['target_audience'] ?? '',
            'size'  => 80,
        ]);
        echo '<small class="form-hint">' . __(
            'Texte libre (ex. "Tout le personnel", "Équipe technique", nom d\'un groupe ou service).',
            'grcmanager'
        ) . '</small>';
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Obligatoire', 'grcmanager') . '</td><td>';
        Html::showCheckbox([
            'name'    => 'is_mandatory',
            'checked' => (bool) ($this->fields['is_mandatory'] ?? true),
        ]);
        echo '</td>';

        echo '<td>' . __('Renouvellement (mois)', 'grcmanager') . '</td><td>';
        echo Html::input('renewal_period_months', [
            'value' => $this->fields['renewal_period_months'] ?? 0,
            'size'  => 5,
        ]);
        echo '<small class="form-hint">' . __('0 = pas de renouvellement requis.', 'grcmanager') . '</small>';
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Description', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo '<textarea name="description" class="form-control" rows="3">'
            . htmlescape($this->fields['description'] ?? '') . '</textarea>';
        echo '</td></tr>';

        $participants = $this->isNewID($ID) ? [] : self::getParticipants((int) $ID);

        echo '<tr class="tab_bg_1"><td>' . __('Participants', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        // Unlike Dropdown::showFromArray() (see PluginGrcmanagerAudit::showForm()'s own
        // Dropdown::showFromArray() calls for `linked_controls`/`risk_categories`), User::dropdown()
        // reads the multi-select's preselected values from the singular `value` option, not
        // `values`: it internally overwrites 'values' with 'value' as soon as 'multiple' is true
        // (`$p['values'] = $p['value'] ?? [];`, see GLPI core src/User.php), so passing 'values'
        // here silently produced an empty string instead of an array and crashed
        // Html::jsAjaxDropdown() with a TypeError, confirmed live against GLPI 11 real.
        User::dropdown([
            'name'     => 'participants',
            'value'    => array_column($participants, 'users_id'),
            'multiple' => true,
            'width'    => '100%',
            'right'    => 'all',
        ]);
        echo '<small class="form-hint">' . __(
            'Enregistrez pour ajouter les nouveaux participants sélectionnés : leur suivi '
            . 'individuel apparaît ci-dessous après enregistrement.',
            'grcmanager'
        ) . '</small>';
        echo '</td></tr>';

        if ($participants !== []) {
            echo '<tr class="tab_bg_1"><td colspan="4">';
            echo '<table class="table card-table"><thead><tr>';
            echo '<th>' . User::getTypeName(1) . '</th>';
            echo '<th>' . __('Statut de réalisation', 'grcmanager') . '</th>';
            echo '<th>' . __('Date de réalisation', 'grcmanager') . '</th>';
            echo '<th>' . __('Évaluation', 'grcmanager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($participants as $participant) {
                $userId = $participant['users_id'];
                echo '<tr><td>' . htmlescape($participant['name']) . '</td><td>';
                Dropdown::showFromArray("participant_status[{$userId}]", self::getCompletionStatuses(), [
                    'value' => $participant['completion_status'],
                ]);
                echo '</td><td>';
                Html::showDateField("participant_date[{$userId}]", [
                    'value' => $participant['completion_date'] ?? '',
                ]);
                echo '</td><td>';
                Dropdown::showFromArray("participant_result[{$userId}]", self::getAssessmentResults(), [
                    'value' => $participant['assessment_result'],
                ]);
                echo '</td></tr>';
            }

            echo '</tbody></table>';
            echo '</td></tr>';
        }

        $this->showFormButtons($options);

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
