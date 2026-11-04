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
use GlpiPlugin\Grcmanager\Compatibility\Base\EbiosStudyBase;
use GlpiPlugin\Grcmanager\Services\Risk\EbiosRules;
use Log;
use Session;
use Supplier;

/**
 * Issue #376 : étude EBIOS Risk Manager, méthode d'appréciation et de traitement du risque de
 * l'ANSSI (sous licence ouverte). Une étude suit cinq ateliers, un onglet chacun. Registre sensible,
 * comme les risques qu'elle alimente : même droit, et registre global comme le registre des risques.
 */
class EbiosStudy extends EbiosStudyBase
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public const FOREIGN_KEY = EbiosElement::STUDY_FK;

    /** Classes des éléments par atelier, pour l'avancement et le nettoyage. */
    private const ELEMENTS = [
        1 => [EbiosValue::class, EbiosFearedEvent::class, EbiosSupport::class],
        2 => [EbiosRiskSource::class],
        3 => [EbiosStakeholder::class, EbiosStrategicScenario::class],
        4 => [EbiosOperationalScenario::class],
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosstudies';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('EBIOS RM study', 'EBIOS RM studies', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-sitemap';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'draft'    => __('In progress', 'grcmanager'),
            'approved' => __('Approved', 'grcmanager'),
            'archived' => __('Archived', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getSeverityLabels(): array
    {
        return [
            1 => __('G1 - Minor', 'grcmanager'),
            2 => __('G2 - Significant', 'grcmanager'),
            3 => __('G3 - Serious', 'grcmanager'),
            4 => __('G4 - Critical', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            1 => __('1 - Low', 'grcmanager'),
            2 => __('2 - Moderate', 'grcmanager'),
            3 => __('3 - High', 'grcmanager'),
            4 => __('4 - Very high', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getLikelihoodLabels(): array
    {
        return [
            1 => __('V1 - Unlikely', 'grcmanager'),
            2 => __('V2 - Likely', 'grcmanager'),
            3 => __('V3 - Very likely', 'grcmanager'),
            4 => __('V4 - Almost certain', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getWorkshopLabels(): array
    {
        return [
            1 => __('Workshop 1: security baseline', 'grcmanager'),
            2 => __('Workshop 2: risk sources', 'grcmanager'),
            3 => __('Workshop 3: strategic scenarios', 'grcmanager'),
            4 => __('Workshop 4: operational scenarios', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/ebios_study_form.html.twig', [
            'item'     => $this,
            'params'   => $options,
            'statuses' => self::getStatuses(),
            'progress' => $this->isNewItem() ? null : EbiosRules::progress(self::countElements((int) $ID)),
            'labels'   => self::getWorkshopLabels(),
        ]);

        return true;
    }

    /**
     * @return array<int, int> Atelier => nombre d'éléments.
     */
    public static function countElements(int $studyId): array
    {
        $counts = [];
        foreach (self::ELEMENTS as $workshop => $classes) {
            $counts[$workshop] = 0;
            foreach ($classes as $class) {
                $counts[$workshop] += countElementsInTable($class::getTable(), [self::FOREIGN_KEY => $studyId]);
            }
        }

        return $counts;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof self) || $item->isNewItem()) {
            return '';
        }
        $counts = self::countElements((int) $item->getID());
        $tabs = [];
        foreach (self::getWorkshopLabels() as $workshop => $label) {
            $tabs[$workshop] = self::createTabEntry(
                $label,
                $counts[$workshop] ?? 0,
                $item::class,
                'ti ti-circle-number-' . $workshop
            );
        }

        return $tabs;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof self)) {
            return false;
        }
        $studyId = (int) $item->getID();
        $common = [
            'item'     => $item,
            'can_edit' => $item->canUpdateItem(),
            'form_url' => self::getElementFormURL(),
            'levels'   => self::getLevelLabels(),
        ];
        $renderer = TemplateRenderer::getInstance();
        if ((int) $tabnum === 1) {
            $renderer->display('@grcmanager/tabs/ebios_workshop1.html.twig', $common + self::workshopOneData($studyId));

            return true;
        }
        if ((int) $tabnum === 2) {
            $sources = [];
            foreach (EbiosRiskSource::getForStudy($studyId) as $source) {
                $source['relevance'] = EbiosRules::sourceRelevance(
                    (int) $source['motivation'],
                    (int) $source['resources'],
                    (int) $source['activity']
                );
                $sources[] = $source;
            }
            $renderer->display('@grcmanager/tabs/ebios_workshop2.html.twig', $common + ['sources' => $sources]);

            return true;
        }
        if ((int) $tabnum === 3) {
            $data = self::workshopThreeData($studyId);
            $renderer->display('@grcmanager/tabs/ebios_workshop3.html.twig', $common + $data);

            return true;
        }
        if ((int) $tabnum === 4) {
            $data = self::workshopFourData($studyId);
            $renderer->display('@grcmanager/tabs/ebios_workshop4.html.twig', $common + $data);

            return true;
        }

        return false;
    }

    /**
     * Parties prenantes classées par menace décroissante, et scénarios stratégiques avec leurs libellés.
     *
     * @return array<string, mixed>
     */
    private static function workshopThreeData(int $studyId): array
    {
        $stakeholders = [];
        foreach (EbiosStakeholder::getForStudy($studyId) as $row) {
            $row['threat'] = EbiosRules::threatLevel(
                (int) $row['dependence'],
                (int) $row['penetration'],
                (int) $row['maturity'],
                (int) $row['trust']
            );
            $row['zone'] = EbiosRules::threatZone($row['threat']);
            $row['supplier_url'] = (int) $row['suppliers_id'] > 0
                ? Supplier::getFormURLWithID((int) $row['suppliers_id'])
                : '';
            $stakeholders[(int) $row['id']] = $row;
        }
        uasort($stakeholders, static fn(array $a, array $b): int => $b['threat'] <=> $a['threat']);

        $sources = [];
        foreach (EbiosRiskSource::getForStudy($studyId) as $source) {
            $sources[(int) $source['id']] = $source;
        }
        $events = self::eventsById($studyId);
        $scenarios = [];
        foreach (EbiosStrategicScenario::getForStudy($studyId) as $row) {
            $source = $sources[(int) $row[EbiosRiskSource::getForeignKeyField()]] ?? [];
            $event = $events[(int) $row[EbiosFearedEvent::getForeignKeyField()]] ?? [];
            $stakeholder = $stakeholders[(int) $row[EbiosStakeholder::getForeignKeyField()]] ?? [];
            $scenarios[] = $row + [
                'source_name'      => (string) ($source['name'] ?? ''),
                'source_objective' => (string) ($source['objective'] ?? ''),
                'source_retained'  => (int) ($source['is_retained'] ?? 0),
                'event_name'       => (string) ($event['name'] ?? ''),
                'severity'         => (int) ($event['severity'] ?? 1),
                'stakeholder_name' => (string) ($stakeholder['name'] ?? ''),
            ];
        }

        return [
            'stakeholders' => array_values($stakeholders),
            'scenarios'    => $scenarios,
            'sources'      => array_filter($sources, static fn(array $s): bool => (int) $s['is_retained'] === 1),
            'events'       => $events,
            'categories'   => EbiosStakeholder::getCategories(),
            'severities'   => self::getSeverityLabels(),
        ];
    }

    /**
     * Scénarios opérationnels avec leur gravité et leur niveau de risque, et la grille de ces niveaux.
     *
     * @return array<string, mixed>
     */
    private static function workshopFourData(int $studyId): array
    {
        $events = self::eventsById($studyId);
        $strategic = [];
        foreach (EbiosStrategicScenario::getForStudy($studyId) as $row) {
            $event = $events[(int) $row[EbiosFearedEvent::getForeignKeyField()]] ?? [];
            $strategic[(int) $row['id']] = $row + ['severity' => (int) ($event['severity'] ?? 1)];
        }
        $supports = [];
        foreach (EbiosSupport::getForStudy($studyId) as $support) {
            $supports[(int) $support['id']] = $support;
        }
        $scenarios = [];
        foreach (EbiosOperationalScenario::getForStudy($studyId) as $row) {
            $parent = $strategic[(int) $row[EbiosStrategicScenario::getForeignKeyField()]] ?? [];
            $severity = (int) ($parent['severity'] ?? 1);
            $scenarios[] = $row + [
                'strategic_name' => (string) ($parent['name'] ?? ''),
                'severity'       => $severity,
                'level'          => EbiosRules::riskLevel($severity, (int) $row['likelihood']),
                'support_name'   => (string) ($supports[(int) $row[EbiosSupport::getForeignKeyField()]]['name'] ?? ''),
            ];
        }
        $pairs = [];
        foreach ($scenarios as $scenario) {
            $pairs[] = ['severity' => $scenario['severity'], 'likelihood' => (int) $scenario['likelihood']];
        }

        return [
            'scenarios'   => $scenarios,
            'strategic'   => $strategic,
            'supports'    => $supports,
            'matrix'      => EbiosRules::riskMatrix($pairs),
            'severities'  => self::getSeverityLabels(),
            'likelihoods' => self::getLikelihoodLabels(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function eventsById(int $studyId): array
    {
        $events = [];
        foreach (EbiosFearedEvent::getForStudy($studyId) as $event) {
            $events[(int) $event['id']] = $event;
        }

        return $events;
    }

    /**
     * Valeurs métier avec leurs événements redoutés et leurs biens supports.
     *
     * @return array<string, mixed>
     */
    private static function workshopOneData(int $studyId): array
    {
        $values = [];
        foreach (EbiosValue::getForStudy($studyId) as $value) {
            $values[(int) $value['id']] = $value + ['events' => [], 'supports' => []];
        }
        foreach (EbiosFearedEvent::getForStudy($studyId) as $event) {
            $values[(int) $event['plugin_grcmanager_ebiosvalues_id']]['events'][] = $event;
        }
        foreach (EbiosSupport::getForStudy($studyId) as $support) {
            $values[(int) $support['plugin_grcmanager_ebiosvalues_id']]['supports'][] = $support;
        }
        $processes = [0 => \Dropdown::EMPTY_VALUE];
        foreach ((new BusinessProcess())->find([], ['name ASC']) as $row) {
            $process = new BusinessProcess();
            if ($process->getFromDB((int) $row['id']) && $process->canViewItem()) {
                $processes[(int) $row['id']] = (string) $row['name'];
            }
        }

        return [
            'values'     => array_values($values),
            'severities' => self::getSeverityLabels(),
            'itemtypes'  => BusinessProcessItem::getItemtypes(),
            'processes'  => $processes,
        ];
    }

    public static function getElementFormURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/ebioselement.form.php';
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
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('The name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (isset($input['status']) && !array_key_exists((string) $input['status'], self::getStatuses())) {
            unset($input['status']);
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        foreach (self::ELEMENTS as $classes) {
            foreach ($classes as $class) {
                (new $class())->deleteByCriteria([self::FOREIGN_KEY => $this->getID()]); // nosemgrep
            }
        }
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => $table, 'field' => 'status', 'name' => __('Status'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Owner', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'scope', 'name' => __('Scope', 'grcmanager'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            return htmlescape(self::getStatuses()[$values[$field]] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            return \Dropdown::showFromArray($name, self::getStatuses(), [
                'value' => $values[$field] ?? '', 'display' => false,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
