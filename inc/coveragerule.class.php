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
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Grcmanager\Compatibility\Base\CoverageRuleBase;
use GlpiPlugin\Grcmanager\Services\Evidence\CoverageRules;

/**
 * Issue #164 : règle de couverture des preuves. « Tous les pare-feu doivent avoir leur politique de
 * filtrage » : un type d'actif (et éventuellement un sous-type), une catégorie de document GLPI
 * requise, et la mesure de l'Annexe A ou l'exigence du SMSI qu'elle prouve. Le taux de couverture
 * est calculé à la lecture : actifs du type qui ont au moins un document de la catégorie, sur
 * l'ensemble des actifs du type, avec la liste des actifs non couverts.
 *
 * Registre global, comme les autres registres du plugin ; les actifs comptés sont ceux des entités
 * actives de la personne qui consulte.
 */
class PluginGrcmanagerCoverageRule extends CoverageRuleBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Actifs non couverts listés au plus. */
    private const MAX_UNCOVERED = 200;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_coveragerules';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evidence coverage', 'Evidence coverage', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-donut';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    public static function getItemtypeLabels(): array
    {
        $labels = [];
        foreach (\GlpiPlugin\Grcmanager\Risk::getLinkableItemtypes() as $itemtype) {
            if (is_a($itemtype, CommonDBTM::class, true)) {
                $labels[$itemtype] = $itemtype::getTypeName(Session::getPluralNumber());
            }
        }

        return $labels;
    }

    /**
     * Classe du sous-type d'un type d'actif (ex. NetworkEquipmentType), null s'il n'en a pas.
     */
    public static function getSubtypeClass(string $itemtype): ?string
    {
        global $DB;

        $class = $itemtype . 'Type';
        if (
            !is_a($itemtype, CommonDBTM::class, true) || !is_a($class, CommonDropdown::class, true)
            || !$DB->fieldExists($itemtype::getTable(), CoverageRules::typeField($itemtype))
        ) {
            return null;
        }

        return $class;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $itemtype = (string) ($this->fields['itemtype'] ?? '');

        TemplateRenderer::getInstance()->display('@grcmanager/coverage_rule_form.html.twig', [
            'item'       => $this,
            'params'     => $options,
            'itemtypes'  => self::getItemtypeLabels(),
            'type_class' => $itemtype !== '' ? self::getSubtypeClass($itemtype) : null,
            'controls'   => PluginGrcmanagerAccessReview::getControlChoices(),
            'requirements' => self::getRequirementChoices(),
        ]);

        return true;
    }

    /**
     * Exigences du SMSI (articles 4 à 10) : la liste AJAX native ne peut pas les charger, elles
     * n'ont pas de champ « name ».
     *
     * @return array<int, string>
     */
    public static function getRequirementChoices(): array
    {
        global $DB;

        $titles = PluginGrcmanagerIsmsRequirement::getClauseTitles();
        $choices = [];
        $criteria = ['SELECT' => ['id', 'code'], 'FROM' => PluginGrcmanagerIsmsRequirement::getTable()];
        foreach ($DB->request($criteria) as $row) {
            $code = (string) $row['code'];
            $choices[(int) $row['id']] = $code . ' - ' . ($titles[$code] ?? '');
        }
        natsort($choices);

        return [0 => Dropdown::EMPTY_VALUE] + $choices;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input)
    {
        $merged = $input + $this->fields;
        $errors = [];
        if (trim((string) ($merged['name'] ?? '')) === '') {
            $errors[] = __('Give the rule a name.', 'grcmanager');
        }
        if (!array_key_exists((string) ($merged['itemtype'] ?? ''), self::getItemtypeLabels())) {
            $errors[] = __('Choose the asset type to cover.', 'grcmanager');
        }
        if ((int) ($merged['documentcategories_id'] ?? 0) <= 0) {
            $errors[] = __('Choose the category of the required document.', 'grcmanager');
        }
        foreach ($errors as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        }
        if ($errors !== []) {
            return false;
        }
        // Sous-type sans objet si le type d'actif change.
        $itemtypeChanged = isset($input['itemtype']) && $input['itemtype'] !== ($this->fields['itemtype'] ?? null);
        if ($itemtypeChanged && !$this->isNewItem()) {
            $input['types_id'] = 0;
        }

        return $input;
    }

    /**
     * @return array{total: int, covered: int, rate: ?int, level: string,
     *     uncovered: list<array{name: string, url: string}>, truncated: bool}
     */
    public function computeCoverage(): array
    {
        global $DB;

        $itemtype = (string) $this->fields['itemtype'];
        $empty = ['total' => 0, 'covered' => 0, 'rate' => null, 'level' => CoverageRules::LEVEL_NONE,
            'uncovered' => [], 'truncated' => false];
        if (!is_a($itemtype, CommonDBTM::class, true)) {
            return $empty;
        }
        $table = $itemtype::getTable();
        $where = getEntitiesRestrictCriteria($table, '', '', (new $itemtype())->maybeRecursive());
        foreach (['is_deleted' => 0, 'is_template' => 0] as $field => $value) {
            if ($DB->fieldExists($table, $field)) {
                $where["$table.$field"] = $value;
            }
        }
        if ((int) ($this->fields['types_id'] ?? 0) > 0 && self::getSubtypeClass($itemtype) !== null) {
            $where["$table." . CoverageRules::typeField($itemtype)] = (int) $this->fields['types_id'];
        }

        $documented = new QuerySubQuery([
            'SELECT'     => 'glpi_documents_items.items_id',
            'FROM'       => 'glpi_documents_items',
            'INNER JOIN' => [
                'glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']],
            ],
            'WHERE'      => [
                'glpi_documents_items.itemtype'       => $itemtype,
                'glpi_documents.documentcategories_id' => (int) $this->fields['documentcategories_id'],
                'glpi_documents.is_deleted'            => 0,
            ],
        ]);

        $total = count($DB->request(['SELECT' => ["$table.id"], 'FROM' => $table, 'WHERE' => $where]));
        $covered = count($DB->request([
            'SELECT' => ["$table.id"],
            'FROM'   => $table,
            'WHERE'  => $where + ['AND' => ["$table.id" => $documented]],
        ]));
        // Les noms des actifs non couverts ne sont listés qu'à qui peut lire ce type d'actif ; les
        // autres ne voient que les nombres (revue de sécurité 2.11.0).
        $canListNames = $itemtype::canView();
        $uncovered = [];
        if ($canListNames) {
            $rows = $DB->request([
                'SELECT' => ["$table.id", "$table.name"],
                'FROM'   => $table,
                'WHERE'  => $where + ['NOT' => ["$table.id" => $documented]],
                'ORDER'  => "$table.name",
                'LIMIT'  => self::MAX_UNCOVERED,
            ]);
            foreach ($rows as $row) {
                $uncovered[] = [
                    'name' => (string) ($row['name'] ?: '#' . $row['id']),
                    'url'  => $itemtype::getFormURLWithID((int) $row['id']) . '&forcetab=Document_Item$1',
                ];
            }
        }
        $rate = CoverageRules::rate($covered, $total);

        return [
            'total'     => $total,
            'covered'   => $covered,
            'rate'      => $rate,
            'level'     => CoverageRules::level($rate),
            'uncovered' => $uncovered,
            'truncated' => $canListNames && $total - $covered > count($uncovered),
        ];
    }

    /**
     * Règles qui prouvent une mesure ou une exigence, avec leur couverture.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForItem(CommonDBTM $item): array
    {
        $field = $item instanceof PluginGrcmanagerControl
            ? 'plugin_grcmanager_controls_id'
            : 'plugin_grcmanager_ismsrequirements_id';
        $rules = [];
        foreach ((new self())->find([$field => $item->getID(), 'is_active' => 1], ['name']) as $row) {
            $rule = new self();
            $rule->fields = $row;
            $rules[] = [
                'name'     => $row['name'],
                'url'      => self::getFormURLWithID((int) $row['id']),
                'coverage' => $rule->computeCoverage(),
            ];
        }

        return $rules;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!Session::haveRight(self::RIGHTNAME, READ) || !$item instanceof CommonDBTM || $item->isNewItem()) {
            return '';
        }
        if ($item instanceof self) {
            return self::createTabEntry(__('Coverage rate', 'grcmanager'));
        }
        if ($item instanceof PluginGrcmanagerControl || $item instanceof PluginGrcmanagerIsmsRequirement) {
            return self::createTabEntry(self::getTypeName(1), count(self::getForItem($item)));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            TemplateRenderer::getInstance()->display('@grcmanager/coverage_result.html.twig', [
                'coverage' => $item->computeCoverage(),
            ]);

            return true;
        }
        if ($item instanceof PluginGrcmanagerControl || $item instanceof PluginGrcmanagerIsmsRequirement) {
            TemplateRenderer::getInstance()->display('@grcmanager/coverage_tab.html.twig', [
                'rules'   => self::getForItem($item),
                'new_url' => Session::haveRight(self::RIGHTNAME, CREATE) ? self::getFormURL() : '',
            ]);

            return true;
        }

        return false;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'name', 'name' => __('Nom'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'itemtype', 'name' => __('Asset type', 'grcmanager'),
            'datatype' => 'itemtypename', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => 'glpi_documentcategories', 'field' => 'name',
            'name' => __('Required document category', 'grcmanager'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 5, 'table' => PluginGrcmanagerControl::getTable(), 'field' => 'code',
            'name' => _x('measure', 'Annex A control', 'grcmanager'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 6, 'table' => PluginGrcmanagerIsmsRequirement::getTable(), 'field' => 'code',
            'name' => __('ISMS requirement', 'grcmanager'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 7, 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Active'),
            'datatype' => 'bool',
        ];

        return $tab;
    }
}
