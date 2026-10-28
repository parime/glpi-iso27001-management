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
use GlpiPlugin\Grcmanager\Compatibility\Base\ObjectiveBase;
use GlpiPlugin\Grcmanager\Services\Objective\ObjectiveStatuses;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * ISMS security objective (issue #32, ISO 27001 clause 6.2): the plugin's 15-card dashboard
 * (DashboardCardService) shows the ISMS's CURRENT state, a snapshot, but clause 6.2 explicitly
 * requires the organization to SET measurable objectives (e.g. "réduire de 20% les
 * non-conformités récurrentes d'ici fin d'année") and demonstrate progress toward them over time —
 * a trajectory, which is what this class and its measurement history
 * (PluginGrcmanagerObjectiveMeasurement) exist to hold.
 *
 * `target_value`/`target_description` are two independent, both-optional target fields rather than
 * forcing every objective into a single numeric target: some objectives genuinely have a number to
 * hit ("réduire de 20%"), others are qualitative only ("obtenir la certification ISO 27001",
 * "déployer le MFA sur tous les comptes à privilèges") and have nothing meaningful to put in a
 * `target_value` column. Whichever is set (or both) is display-only context for the human reading
 * the measurement history below; this class does not itself compute "on track" vs "at risk" from
 * the numbers (see `status`, entered manually like every other status column in this plugin
 * family — RiskAssessmentTrait::getStatuses(), \GlpiPlugin\Grcmanager\Audit::getStatuses()... — an
 * automatic "distance to target" computation was judged premature for a first version, especially
 * for the qualitative-only case where no such computation is even possible, see TECH_DEBT.md).
 *
 * Progress itself lives in `PluginGrcmanagerObjectiveMeasurement`
 * (glpi_plugin_grcmanager_objectivemeasurements), a simple manually-entered time series ("as of
 * this review, we're at X"), deliberately NOT auto-computed from other plugin data (e.g. pulling a
 * live non-conformity count): matches this plugin's own "une version minimale et testée vaut mieux
 * qu'une version élaborée et non testée" philosophy (TECH_DEBT.md Sprint 2), a future issue can
 * wire up auto-computed measurements if that is ever wanted. Shown as a plain chronological table
 * on this class' own `showForm()` (see showMeasurementHistory() below), not a chart: an honest way
 * to show "trajectory" without a new charting dependency, consistent with every other manual-HTML
 * form in this plugin (TECH_DEBT.md Sprint 1).
 */
class PluginGrcmanagerObjective extends ObjectiveBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_objectives';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ISMS objective', 'ISMS objectives', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-target-arrow';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            ObjectiveStatuses::NOT_STARTED => __('Not started', 'grcmanager'),
            ObjectiveStatuses::ON_TRACK    => __('On track', 'grcmanager'),
            ObjectiveStatuses::AT_RISK     => __('At risk', 'grcmanager'),
            ObjectiveStatuses::ACHIEVED    => __('Achieved', 'grcmanager'),
            ObjectiveStatuses::MISSED      => __('Missed', 'grcmanager'),
        ];
    }

    /**
     * Never trusts `status` from the request as-is, same defensive posture as
     * PluginGrcmanagerAssetClassification::sanitizeLevels(): an invalid/tampered value always
     * falls back to the safe default rather than being stored as garbage.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sanitizeStatus(array $input): array
    {
        if (array_key_exists('status', $input) && !ObjectiveStatuses::isValid($input['status'])) {
            $input['status'] = ObjectiveStatuses::NOT_STARTED;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->sanitizeStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->sanitizeStatus($input);
    }

    /**
     * Whether this objective has a numeric target to plot measurements against (see class
     * docblock): drives both the required-ness of a new measurement's `value`
     * (ObjectiveMeasurementValidator) and the "Valeur" column header hint on the history table.
     */
    public function hasNumericTarget(): bool
    {
        $targetValue = $this->fields['target_value'] ?? null;

        return $targetValue !== null && $targetValue !== '';
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
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'target_date',
            'name'     => __('Due date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'target_value',
            'name'     => __('Target value', 'grcmanager'),
            'datatype' => 'decimal',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'target_description',
            'name'     => __('Qualitative target', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
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

    public static function statusBadge(?string $value): string
    {
        $map = [
            ObjectiveStatuses::NOT_STARTED => ['bg-secondary-lt', 'ti-player-stop', __('Not started', 'grcmanager')],
            ObjectiveStatuses::ON_TRACK    => ['bg-green-lt', 'ti-trending-up', __('On track', 'grcmanager')],
            ObjectiveStatuses::AT_RISK     => ['bg-orange-lt', 'ti-alert-triangle', __('At risk', 'grcmanager')],
            ObjectiveStatuses::ACHIEVED    => ['bg-teal-lt', 'ti-trophy', __('Achieved', 'grcmanager')],
            ObjectiveStatuses::MISSED      => ['bg-red-lt', 'ti-x', __('Missed', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    public function showForm($ID, array $options = []): bool
    {
        global $CFG_GLPI;

        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/objective_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'statuses'       => self::getStatuses(),
            'default_status' => ObjectiveStatuses::NOT_STARTED,
        ]);

        // La saisie de mesures n'a de sens que pour un objectif déjà enregistré (il lui faut un id
        // réel auquel rattacher glpi_plugin_grcmanager_objectivemeasurements). Historique simple,
        // sans dépendance de graphique (voir le docblock de cette classe).
        if (!$this->isNewID($ID)) {
            TemplateRenderer::getInstance()->display('@grcmanager/objective_measurements.html.twig', [
                'objective_id' => (int) $ID,
                // Mêmes droits que front/objectivemeasurement.form.php : ajouter = CREATE,
                // supprimer = PURGE.
                'can_edit'     => Session::haveRight(self::$rightname, CREATE),
                'can_purge'    => Session::haveRight(self::$rightname, PURGE),
                'action'       => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/objectivemeasurement.form.php',
                'today'        => date('Y-m-d'),
                'numeric'      => $this->hasNumericTarget(),
                'measurements' => PluginGrcmanagerObjectiveMeasurement::getMeasurements((int) $ID),
            ]);
        }

        return true;
    }
}
