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

use CronTask;
use GlpiPlugin\Grcmanager\Compatibility\Base\RiskTreatmentActionBase;
use GlpiPlugin\Grcmanager\Services\Risk\OverdueTreatmentActionService;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Session;

/**
 * One concrete action carrying out a risk's `mitigate`/`transfer` treatment decision (issue #31,
 * ISO 27001 clause 8.3/6.1.3: the treatment plan must actually be IMPLEMENTED and TRACKED, not just
 * decided). A real child itemtype/table (glpi_plugin_grcmanager_risktreatmentactions), not a flat
 * set of fields on Risk itself: unlike a non-conformity's CAPA (exactly one
 * corrective action + one preventive action, see PluginGrcmanagerNonconformity), a real risk
 * treatment plan is very often more than one action ("patch the system" AND "add monitoring" AND
 * "train staff"), each independently tracked to completion with its own owner/due date/status - a
 * genuine one-to-many relationship this plugin already has a comfortable convention for (see
 * PluginGrcmanagerObjectiveMeasurement, issue #32, the closest existing template: a child itemtype
 * that only ever exists as a row added/deleted inline from its parent's own form, with no menu
 * entry, no own search screen, no rawSearchOptions() of its own).
 *
 * `plugin_grcmanager_risks_id` is not a real GLPI foreign key (no native `ON DELETE CASCADE` here),
 * same simplification assumed for PluginGrcmanagerObjectiveMeasurement's own
 * `plugin_grcmanager_objectives_id` (see TECH_DEBT.md issue #32) - EXCEPT that
 * Risk::post_purgeItem() already exists (issue #25, cleaning up
 * glpi_plugin_grcmanager_risks_items) and is extended here to also delete this itemtype's own rows,
 * since the marginal cost is a single extra $DB->delete() call and, unlike a measurement history
 * kept for its own sake, an orphaned treatment action would otherwise never be visible or
 * cleanable again (no menu entry, no own search screen).
 *
 * A treatment plan is only relevant for `treatment` = `mitigate`/`transfer` (see
 * TreatmentPlanRules::isTreatmentPlanRelevant(), and Risk::showForm()/
 * showTreatmentPlan() for where this itemtype is actually surfaced): `accept` has no treatment plan
 * by definition, `avoid` eliminates the risk source entirely, nothing ongoing to track either.
 */
class RiskTreatmentAction extends RiskTreatmentActionBase
{
    // Registre sensible : droit dédié, voir Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /**
     * GLPI notification event name (see inc/notificationtargetrisktreatmentaction.class.php and
     * src/Services/Risk/OverdueTreatmentActionService.php), same single-source-of-truth pattern as
     * PluginGrcmanagerNonconformity::CAPA_OVERDUE_EVENT.
     */
    public const OVERDUE_EVENT = 'treatment_action_overdue';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_risktreatmentactions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk treatment action', 'Risk treatment actions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            TreatmentPlanRules::STATUS_PLANNED     => __('Planned', 'grcmanager'),
            TreatmentPlanRules::STATUS_IN_PROGRESS => __('In progress', 'grcmanager'),
            TreatmentPlanRules::STATUS_DONE        => __('Done', 'grcmanager'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateAndNormalize($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateAndNormalize($input);
    }

    /**
     * Refuses an action with no real parent risk (same defensive posture as
     * PluginGrcmanagerObjectiveMeasurement::prepareInputForAdd()'s own "Objectif introuvable."
     * check), normalizes `status` against TreatmentPlanRules::ALLOWED_STATUSES, and auto-stamps
     * `completion_date` with today as soon as `status` reaches `done` without one already set -
     * same "auto-populate a real date rather than force an extra manual step" choice as
     * PluginGrcmanagerNonconformity's own `closure_verification_date`.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateAndNormalize(array $input)
    {
        $riskId = (int) ($input['plugin_grcmanager_risks_id'] ?? ($this->fields['plugin_grcmanager_risks_id'] ?? 0));

        $risk = new Risk();
        if ($riskId <= 0 || !$risk->getFromDB($riskId)) {
            Session::addMessageAfterRedirect(__('Risk not found.', 'grcmanager'), false, ERROR);

            return false;
        }

        $input['status'] = TreatmentPlanRules::normalizeStatus(
            $input['status'] ?? ($this->fields['status'] ?? null)
        );

        // Html::showDateField()'s native <input type="date"> submits '' (not absent) when left
        // empty, and due_date is a nullable DATE column: an empty string reaches MySQL as-is and
        // strict mode rejects it ("Incorrect date value: '' for column `due_date`"), so a due date
        // is not actually optional in practice without this. completion_date has no such path: no
        // form field ever submits it directly, it is only ever auto-stamped below.
        if (array_key_exists('due_date', $input) && $input['due_date'] === '') {
            $input['due_date'] = null;
        }

        $completionDate = $input['completion_date'] ?? ($this->fields['completion_date'] ?? null);
        if ($input['status'] === TreatmentPlanRules::STATUS_DONE && empty($completionDate)) {
            $input['completion_date'] = date('Y-m-d');
        }

        return $input;
    }

    public static function statusBadge(?string $value): string
    {
        $map = [
            TreatmentPlanRules::STATUS_PLANNED     =>
                ['bg-secondary-lt', 'ti-player-play', __('Planned', 'grcmanager')],
            TreatmentPlanRules::STATUS_IN_PROGRESS => ['bg-blue-lt', 'ti-tool', __('In progress', 'grcmanager')],
            TreatmentPlanRules::STATUS_DONE        => ['bg-green-lt', 'ti-check', __('Done', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    /**
     * @return array<int, array<string, mixed>> raw DB rows for every treatment action of one risk,
     *         soonest due date first, for Risk::showTreatmentPlan().
     */
    public static function getActionsForRisk(int $riskId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
                'ORDER' => 'due_date ASC, id ASC',
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Used by Risk's own closure validation (a risk being mitigated/transferred
     * cannot be closed with zero recorded treatment actions, see its own docblock) - a plain COUNT
     * rather than getActionsForRisk() to avoid fetching every row just to check "at least one".
     */
    public static function countForRisk(int $riskId): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
        ])->current()['c'];
    }

    /**
     * GLPI Cron entry point, registered via CronTask::Register() in the plugin installer
     * (src/Install/Installer.php), same structure as
     * PluginGrcmanagerNonconformity::cronOverduecapa(). Finds treatment actions whose due date has
     * passed and that are not yet marked done, and raises a real GLPI Notification for each (see
     * OverdueTreatmentActionService, inc/notificationtargetrisktreatmentaction.class.php).
     *
     * @return int 0 if no treatment action was overdue, 1 otherwise
     */
    public static function cronOverduetreatmentaction(CronTask $task): int
    {
        $result = (new OverdueTreatmentActionService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            '%d action(s) de traitement de risque en retard, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
