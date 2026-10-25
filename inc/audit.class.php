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
use GlpiPlugin\Grcmanager\Compatibility\Base\AuditBase;

/**
 * Internal audit program (ISO 27001 clause 9.2): one row per planned/performed internal audit.
 * Scoped either to specific Annex A controls (many-to-many via
 * glpi_plugin_grcmanager_audits_controls, same "direct $DB access, not a real CommonDBRelation"
 * pattern PluginGrcmanagerControl already uses for its own link to the risk register, see
 * TECH_DEBT.md Sprint 3) and/or to one or more risk categories (stored as a comma-separated list
 * of PluginGrcmanagerRisk::getCategories() keys, resolved through the same enum, never a second
 * copy of the category list).
 *
 * Findings raised during an audit are recorded as PluginGrcmanagerNonconformity rows linked back
 * to this audit (inc/nonconformity.class.php), the "finding -> corrective/preventive action ->
 * closure" workflow ISO 27001 clause 10 requires.
 */
class PluginGrcmanagerAudit extends AuditBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    private const LINK_TABLE = 'glpi_plugin_grcmanager_audits_controls';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_audits';
    }

    /**
     * Le titre tient lieu de nom : sans cela, l'en-tête de la fiche et les listes déroulantes
     * affichaient « N/A » (même correctif que PluginGrcmanagerPolicy::getNameField()).
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Audit interne', 'Audits internes', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-check';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'planned'     => __('Planifié', 'grcmanager'),
            'in_progress' => __('En cours', 'grcmanager'),
            'completed'   => __('Terminé', 'grcmanager'),
            'cancelled'   => __('Annulé', 'grcmanager'),
        ];
    }

    /**
     * @param string $csv comma-separated PluginGrcmanagerRisk category keys, e.g. "people,technical"
     * @return array<int, string>
     */
    public static function splitRiskCategories(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /**
     * Auto-populates `actual_date` the first time a save sets `status` to "completed" without
     * providing one explicitly, so a real end date is always recorded once an audit is actually
     * finished, without forcing an extra manual step (the audit list's own "En attente" filter on
     * `actual_date IS NULL` would otherwise stay wrong for every completed audit whose auditor
     * forgot to fill it in). Also normalizes `risk_categories` posted as a multi-select array
     * (`risk_categories[]` in the form) into the single comma-separated string the column stores.
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
        if (isset($input['risk_categories']) && is_array($input['risk_categories'])) {
            $input['risk_categories'] = implode(',', array_filter(array_map('trim', $input['risk_categories'])));
        }

        $status     = (string) ($input['status'] ?? ($this->fields['status'] ?? 'planned'));
        $actualDate = $input['actual_date'] ?? ($this->fields['actual_date'] ?? null);

        if ($status === 'completed' && empty($actualDate)) {
            $input['actual_date'] = date('Y-m-d');
        }

        return $input;
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
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Auditeur', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'planned_date',
            'name'     => __('Date planifiée', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'actual_date',
            'name'     => __('Date réalisée', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'risk_categories',
            'name'     => __('Catégories de risque couvertes', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'scope',
            'name'     => __('Périmètre', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'conclusion',
            'name'     => __('Conclusion', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family.
        $tab[] = [
            'id'       => 9,
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

            case 'risk_categories':
                return self::riskCategoriesBadges((string) ($values[$field] ?? ''));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widgets for status/risk_categories, same lesson as
     * PluginGrcmanagerRisk::getSpecificValueToSelect()/PluginGrcmanagerControl::getSpecificValueToSelect().
     * For `risk_categories` (a comma-separated column), the filter searches for a single category
     * key via GLPI's default "contains" search type, matching if that category is anywhere in the
     * stored list; not a genuine multi-value AND/OR filter, a deliberate simplification for a
     * first version (see TECH_DEBT.md Sprint 4).
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

            case 'risk_categories':
                return Dropdown::showFromArray($name, PluginGrcmanagerRisk::getCategories(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'planned'     => ['bg-secondary-lt', 'ti-calendar-event', __('Planifié', 'grcmanager')],
            'in_progress' => ['bg-blue-lt', 'ti-tool', __('En cours', 'grcmanager')],
            'completed'   => ['bg-green-lt', 'ti-check', __('Terminé', 'grcmanager')],
            'cancelled'   => ['bg-dark-lt', 'ti-x', __('Annulé', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    private static function riskCategoriesBadges(string $csv): string
    {
        $categories = self::splitRiskCategories($csv);

        if ($categories === []) {
            return '';
        }

        $labels = PluginGrcmanagerRisk::getCategories();
        $badges = [];

        foreach ($categories as $category) {
            $label    = $labels[$category] ?? $category;
            $badges[] = '<span class="badge bg-azure-lt">' . htmlescape($label) . '</span>';
        }

        return implode(' ', $badges);
    }

    /**
     * @return array<int, string> control id => "code - intitulé", for every control in this
     *                            audit's scope.
     */
    public static function getLinkedControls(int $auditId): array
    {
        global $DB;

        $controls = [];

        $rows = $DB->request([
            'SELECT'     => ['controls.id', 'controls.code'],
            'FROM'       => self::LINK_TABLE . ' AS links',
            'INNER JOIN' => [
                PluginGrcmanagerControl::getTable() . ' AS controls' => [
                    'FKEY' => [
                        'links'    => 'plugin_grcmanager_controls_id',
                        'controls' => 'id',
                    ],
                ],
            ],
            'WHERE'      => ['links.plugin_grcmanager_audits_id' => $auditId],
            'ORDER'      => 'controls.code ASC',
        ]);

        foreach ($rows as $row) {
            $controls[(int) $row['id']] = $row['code'] . ' - ' . PluginGrcmanagerControl::getControlTitle($row['code']);
        }

        return $controls;
    }

    /**
     * @return array<int, int> the linked control IDs only, for pre-selecting the form's multi-select.
     */
    public static function getLinkedControlIds(int $auditId): array
    {
        return array_keys(self::getLinkedControls($auditId));
    }

    /**
     * Replaces the full set of controls linked to this audit with exactly $controlIds (delete then
     * re-insert), same simplification (and same rationale: low cardinality) as
     * PluginGrcmanagerControl::syncLinkedRisks().
     *
     * @param array<int, int> $controlIds
     */
    private static function syncLinkedControls(int $auditId, array $controlIds): void
    {
        global $DB;

        $DB->delete(self::LINK_TABLE, ['plugin_grcmanager_audits_id' => $auditId]);

        $controlIds = array_unique(array_filter(array_map('intval', $controlIds), static fn ($id) => $id > 0));

        foreach ($controlIds as $controlId) {
            $DB->insert(self::LINK_TABLE, [
                'plugin_grcmanager_audits_id'   => $auditId,
                'plugin_grcmanager_controls_id' => $controlId,
                'date_creation'                 => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function post_addItem()
    {
        parent::post_addItem();

        if (array_key_exists('linked_controls', $this->input)) {
            self::syncLinkedControls((int) $this->fields['id'], (array) $this->input['linked_controls']);
        }
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        if (array_key_exists('linked_controls', $this->input)) {
            self::syncLinkedControls((int) $this->fields['id'], (array) $this->input['linked_controls']);
        }
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);

        $controlTitles = [];
        $controlRows   = $DB->request(['SELECT' => ['id', 'code'], 'FROM' => PluginGrcmanagerControl::getTable()]);
        foreach ($controlRows as $row) {
            $controlTitles[(int) $row['id']] = $row['code'] . ' - '
                . PluginGrcmanagerControl::getControlTitle($row['code']);
        }
        asort($controlTitles);

        TemplateRenderer::getInstance()->display('@grcmanager/audit_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'statuses'        => self::getStatuses(),
            'categories'      => PluginGrcmanagerRisk::getCategories(),
            'risk_categories' => $this->isNewID($ID)
                ? []
                : self::splitRiskCategories((string) ($this->fields['risk_categories'] ?? '')),
            'controls'        => $controlTitles,
            'linked_controls' => $this->isNewID($ID) ? [] : self::getLinkedControlIds((int) $ID),
        ]);

        return true;
    }
}
