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
use GlpiPlugin\Grcmanager\Compatibility\Base\ManagementReviewBase;

/**
 * Management review record (ISO 27001 clause 9.3): evidence that top management periodically
 * reviews the whole ISMS, with attendees, the agenda/topics actually covered, and the decisions
 * or action items that came out of it.
 *
 * Attendees are a many-to-many link to real GLPI `User`s
 * (glpi_plugin_grcmanager_managementreviews_users), same "direct $DB access, not a real
 * CommonDBRelation" simplification already assumed for every other many-to-many link in this
 * plugin family (see TECH_DEBT.md Sprint 3/4, and PluginGrcmanagerAudit::getLinkedControls()/
 * syncLinkedControls() for the closest prior art: a plain link table with no extra columns).
 * Decisions/action items are kept as a single free-text field rather than hard-linked to the
 * existing CAPA/non-conformity mechanism (PluginGrcmanagerNonconformity): a management review
 * decision is not always a corrective action tied to an audit finding (it might be a budget
 * approval, a policy change, a risk acceptance...), forcing every decision through the CAPA
 * workflow would misrepresent what clause 9.3 actually asks for. See TECH_DEBT.md Sprint 6.
 *
 * Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2) adds a light many-to-many
 * link to `PluginGrcmanagerObjective` (glpi_plugin_grcmanager_managementreviews_objectives, same
 * "direct $DB access, not a real CommonDBRelation" convention as the attendees link above):
 * ISO 27001 clause 9.3 lists "the extent to which the information security objectives have been
 * met" among the required management review inputs, and issue #32 itself calls out that a
 * management review is exactly where the ISMS's trajectory toward its objectives gets discussed.
 * Kept separate from `decisions` (free text) rather than folded into it: an objective reviewed
 * here is a structured reference to a real PluginGrcmanagerObjective row (clickable, filterable),
 * not prose.
 */
class PluginGrcmanagerManagementReview extends ManagementReviewBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    private const ATTENDEES_TABLE = 'glpi_plugin_grcmanager_managementreviews_users';

    private const OBJECTIVES_TABLE = 'glpi_plugin_grcmanager_managementreviews_objectives';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_managementreviews';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Revue de direction', 'Revues de direction', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-users-group';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'planned'   => __('Planifiée', 'grcmanager'),
            'completed' => __('Terminée', 'grcmanager'),
        ];
    }

    /**
     * Auto-populates `review_date` the first time a save sets `status` to "completed" without
     * providing one explicitly, same "auto-populate a real date rather than force an extra manual
     * step" lesson as PluginGrcmanagerAudit's own `actual_date` (see normalizeInput() there).
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
        $status     = (string) ($input['status'] ?? ($this->fields['status'] ?? 'planned'));
        $reviewDate = $input['review_date'] ?? ($this->fields['review_date'] ?? null);

        if ($status === 'completed' && empty($reviewDate)) {
            $input['review_date'] = date('Y-m-d');
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();

        if (array_key_exists('attendees', $this->input)) {
            self::syncAttendees((int) $this->fields['id'], (array) $this->input['attendees']);
        }

        if (array_key_exists('linked_objectives', $this->input)) {
            self::syncLinkedObjectives((int) $this->fields['id'], (array) $this->input['linked_objectives']);
        }
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        if (array_key_exists('attendees', $this->input)) {
            self::syncAttendees((int) $this->fields['id'], (array) $this->input['attendees']);
        }

        if (array_key_exists('linked_objectives', $this->input)) {
            self::syncLinkedObjectives((int) $this->fields['id'], (array) $this->input['linked_objectives']);
        }
    }

    /**
     * @return array<int, string> user id => display name, for every attendee of this review.
     */
    public static function getAttendees(int $reviewId): array
    {
        global $DB;

        $attendees = [];

        $rows = $DB->request([
            'SELECT'     => ['links.users_id', 'u.name'],
            'FROM'       => self::ATTENDEES_TABLE . ' AS links',
            'INNER JOIN' => [
                'glpi_users AS u' => [
                    'FKEY' => ['links' => 'users_id', 'u' => 'id'],
                ],
            ],
            'WHERE'      => ['links.plugin_grcmanager_managementreviews_id' => $reviewId],
            'ORDER'      => 'u.name ASC',
        ]);

        foreach ($rows as $row) {
            $attendees[(int) $row['users_id']] = (string) $row['name'];
        }

        return $attendees;
    }

    /**
     * @return array<int, int> the linked attendee user IDs only, for pre-selecting the form's
     *                          multi-select.
     */
    public static function getAttendeeIds(int $reviewId): array
    {
        return array_keys(self::getAttendees($reviewId));
    }

    /**
     * Replaces the full set of attendees linked to this review with exactly $userIds (delete then
     * re-insert), same simplification as PluginGrcmanagerAudit::syncLinkedControls().
     *
     * @param array<int, int> $userIds
     */
    private static function syncAttendees(int $reviewId, array $userIds): void
    {
        global $DB;

        $DB->delete(self::ATTENDEES_TABLE, ['plugin_grcmanager_managementreviews_id' => $reviewId]);

        $userIds = array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0));

        foreach ($userIds as $userId) {
            $DB->insert(self::ATTENDEES_TABLE, [
                'plugin_grcmanager_managementreviews_id' => $reviewId,
                'users_id'                                => $userId,
                'date_creation'                           => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return array<int, string> objective id => title, for every objective linked (as "discussed")
     *         to this review.
     */
    public static function getLinkedObjectives(int $reviewId): array
    {
        global $DB;

        $objectives = [];

        $rows = $DB->request([
            'SELECT'     => ['objectives.id', 'objectives.title'],
            'FROM'       => self::OBJECTIVES_TABLE . ' AS links',
            'INNER JOIN' => [
                PluginGrcmanagerObjective::getTable() . ' AS objectives' => [
                    'FKEY' => [
                        'links'      => 'plugin_grcmanager_objectives_id',
                        'objectives' => 'id',
                    ],
                ],
            ],
            'WHERE'      => ['links.plugin_grcmanager_managementreviews_id' => $reviewId],
        ]);

        foreach ($rows as $row) {
            $objectives[(int) $row['id']] = $row['title'];
        }

        return $objectives;
    }

    /**
     * @return array<int, int> the linked objective IDs only, for pre-selecting the form's
     *                          multi-select.
     */
    public static function getLinkedObjectiveIds(int $reviewId): array
    {
        return array_keys(self::getLinkedObjectives($reviewId));
    }

    /**
     * Replaces the full set of objectives linked to this review with exactly $objectiveIds (delete
     * then re-insert), same simplification as syncAttendees() above.
     *
     * @param array<int, int> $objectiveIds
     */
    private static function syncLinkedObjectives(int $reviewId, array $objectiveIds): void
    {
        global $DB;

        $DB->delete(self::OBJECTIVES_TABLE, ['plugin_grcmanager_managementreviews_id' => $reviewId]);

        $objectiveIds = array_unique(
            array_filter(array_map('intval', $objectiveIds), static fn ($id) => $id > 0)
        );

        foreach ($objectiveIds as $objectiveId) {
            $DB->insert(self::OBJECTIVES_TABLE, [
                'plugin_grcmanager_managementreviews_id' => $reviewId,
                'plugin_grcmanager_objectives_id'        => $objectiveId,
                'date_creation'                          => date('Y-m-d H:i:s'),
            ]);
        }
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
            'field'    => 'status',
            'name'     => __('Statut', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'review_date',
            'name'     => __('Date de revue', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'agenda',
            'name'     => __('Ordre du jour', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'decisions',
            'name'     => __('Décisions et actions', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family.
        $tab[] = [
            'id'       => 6,
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
            case 'status':
                return self::statusBadge($values[$field] ?? null);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widget for `status`, same lesson as every other fixed-enum column in
     * this plugin family.
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
            case 'status':
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'planned'   => ['bg-secondary-lt', 'ti-calendar-event', __('Planifiée', 'grcmanager')],
            'completed' => ['bg-green-lt', 'ti-check', __('Terminée', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);

        // Issue #32 (clause 6.2/9.3) : objectifs ISMS effectivement abordés lors de cette revue.
        $objectiveTitles = [];
        foreach (
            $DB->request(['SELECT' => ['id', 'title'], 'FROM' => PluginGrcmanagerObjective::getTable()]) as $row
        ) {
            $objectiveTitles[(int) $row['id']] = $row['title'];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/management_review_form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'statuses'          => self::getStatuses(),
            'attendees'         => $this->isNewID($ID) ? [] : self::getAttendeeIds((int) $ID),
            'objectives'        => $objectiveTitles,
            'linked_objectives' => $this->isNewID($ID) ? [] : self::getLinkedObjectiveIds((int) $ID),
        ]);

        return true;
    }
}
