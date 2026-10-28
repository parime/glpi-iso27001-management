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

use GlpiPlugin\Grcmanager\Compatibility\Base\ObjectiveMeasurementBase;
use GlpiPlugin\Grcmanager\Services\Objective\ObjectiveMeasurementValidator;

/**
 * One manually-entered data point in an ISMS objective's progress-over-time history (issue #32,
 * ISO 27001 clause 6.2), see PluginGrcmanagerObjective's own docblock for the full rationale.
 * `plugin_grcmanager_objectives_id` is not a real GLPI foreign key (no native `ON DELETE CASCADE`
 * here, resolved by this class' own getMeasurements()/deleteAllForObjective()), same simplification
 * already assumed for \GlpiPlugin\Grcmanager\Nonconformity's own `plugin_grcmanager_audits_id` (see
 * TECH_DEBT.md Sprint 4).
 *
 * Never surfaced through its own menu entry, search screen, or `showForm()`: it only ever exists
 * as a child row added/deleted inline from its parent objective's own form
 * (PluginGrcmanagerObjective::showMeasurementHistory(), front/objectivemeasurement.form.php),
 * exactly like \GlpiPlugin\Grcmanager\ManagementReview's attendees or \GlpiPlugin\Grcmanager\Training's
 * participants, except those two are plain link tables while a measurement genuinely carries its
 * own data (date, value, comment) and so needs its own real itemtype/table rather than a link
 * table with no extra columns.
 */
class PluginGrcmanagerObjectiveMeasurement extends ObjectiveMeasurementBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_objectivemeasurements';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ISMS objective measurement', 'ISMS objective measurements', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-timeline';
    }

    /**
     * Enforces ObjectiveMeasurementValidator's rule server-side: a quantitative objective
     * (target_value set) needs a numeric value on every measurement, a qualitative-only objective
     * accepts a value-less measurement only if it carries a real comment. Never trusted from the
     * client alone (same posture as every other server-side validation in this plugin family, see
     * PluginGrcmanagerControl::validateAndMarkReviewed()).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $objectiveId = (int) ($input['plugin_grcmanager_objectives_id'] ?? 0);

        $objective = new PluginGrcmanagerObjective();
        if ($objectiveId <= 0 || !$objective->getFromDB($objectiveId)) {
            Session::addMessageAfterRedirect(
                __('Objective not found.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        $rawValue = $input['value'] ?? null;
        $value    = ($rawValue === null || $rawValue === '') ? null : (float) $rawValue;
        $comment  = (string) ($input['comment'] ?? '');

        if (!ObjectiveMeasurementValidator::isValid($objective->hasNumericTarget(), $value, $comment)) {
            Session::addMessageAfterRedirect(
                $objective->hasNumericTarget()
                    ? __('A numeric value is required for a measurement of this quantitative objective.', 'grcmanager')
                    : __(
                        'A comment is required for a measurement with no numeric value (qualitative objective).',
                        'grcmanager'
                    ),
                false,
                ERROR
            );

            return false;
        }

        $input['value'] = $value;

        if (empty($input['measurement_date'])) {
            $input['measurement_date'] = date('Y-m-d');
        }

        return $input;
    }

    /**
     * @return array<int, array<string, mixed>> raw DB rows for every measurement of one objective,
     *         oldest first (chronological trajectory, see PluginGrcmanagerObjective::
     *         showMeasurementHistory()).
     */
    public static function getMeasurements(int $objectiveId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_objectives_id' => $objectiveId],
                'ORDER' => 'measurement_date ASC, id ASC',
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }
}
