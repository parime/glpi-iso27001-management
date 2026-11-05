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

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessProcessBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\BusinessImpactRules;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use Log;
use Session;

/**
 * Issue #377 : registre des processus métier (ISO 22301 8.2, ISO 27001 A.5.29 et A.5.30).
 *
 * Un processus a un responsable, un service, une criticité (intitulé « Criticité métier » du cœur
 * de GLPI, le même que celui des informations financières des actifs), une durée maximale
 * d'interruption admissible (DMIA), un RTO et un RPO. Il dépend d'éléments GLPI (applications du
 * cœur, ordinateurs, logiciels, fournisseurs…) : voir BusinessProcessItem. Son analyse d'impact sur
 * l'activité (BIA) est notée par durée d'interruption : voir BusinessImpact.
 *
 * Droit dédié (Installer::CONTINUITY_RIGHT), contrôlé avec l'entité du processus.
 */
class BusinessProcess extends BusinessProcessBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessprocesses';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Business process', 'Business processes', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-factory-2';
    }

    public function maybeRecursive()
    {
        return true;
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
     * Durées proposées pour la DMIA, le RTO et le RPO, et pour les durées d'interruption de la BIA :
     * celles des objectifs de reprise des actifs (AssetClassification), plus 2 semaines et 1 mois.
     *
     * @return array<int, string> heures => libellé
     */
    public static function getDurationOptions(): array
    {
        return AssetClassification::getRecoveryOptions() + [
            336 => __('2 weeks', 'grcmanager'),
            720 => __('1 month', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(BusinessImpact::class, $tabs, $options)
            ->addStandardTab(BusinessProcessItem::class, $tabs, $options)
            ->addStandardTab(BusinessProcessLink::class, $tabs, $options)
            ->addStandardTab(BusinessProcessRisk::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/business_process_form.html.twig', [
            'item'      => $this,
            'params'    => $options,
            'durations' => [0 => Dropdown::EMPTY_VALUE] + self::getDurationOptions(),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        // Le droit est contrôlé sur l'entité actuelle du processus : un formulaire forgé ne doit pas
        // pouvoir l'envoyer dans une entité à laquelle la personne n'a pas accès.
        unset($input['entities_id']);

        return $this->checkInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__('The business process name is required.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }
        foreach (['mtpd_hours', 'rto_hours', 'rpo_hours'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = BusinessImpactRules::sanitizeDuration($input[$field]);
            }
        }
        if (array_key_exists('bia_date', $input) && (string) $input['bia_date'] === '') {
            $input['bia_date'] = null;
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        (new BusinessProcessItem())->deleteByCriteria(['plugin_grcmanager_businessprocesses_id' => $this->getID()]);
        (new BusinessImpact())->deleteByCriteria(['plugin_grcmanager_businessprocesses_id' => $this->getID()]);
        // Dépendances entre processus, dans les deux sens, et liens vers le registre des risques.
        (new BusinessProcessLink())->deleteByCriteria(['OR' => [
            'plugin_grcmanager_businessprocesses_id' => $this->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $this->getID(),
        ]]);
        (new BusinessProcessRisk())->deleteByCriteria(['plugin_grcmanager_businessprocesses_id' => $this->getID()]);
    }

    /**
     * Objectifs de reprise des éléments classés (onglet « Classification C/I/D » des actifs).
     *
     * @return array<string, array{rto: int, rpo: int}>
     */
    private static function assetObjectives(): array
    {
        global $DB;

        $objectives = [];
        foreach (
            $DB->request([
                'SELECT' => ['itemtype', 'items_id', 'rto_hours', 'rpo_hours'],
                'FROM'   => AssetClassification::getTable(),
            ]) as $row
        ) {
            $objectives[ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id'])] = [
                'rto' => (int) $row['rto_hours'],
                'rpo' => (int) $row['rpo_hours'],
            ];
        }

        return $objectives;
    }

    /**
     * Criticité métier de l'élément, telle que saisie dans ses informations financières (cœur).
     */
    private static function itemCriticity(string $itemtype, int $itemsId): string
    {
        if (!\Infocom::canApplyOn($itemtype)) {
            return '';
        }
        $infocom = new \Infocom();
        if (!$infocom->getFromDBforDevice($itemtype, $itemsId)) {
            return '';
        }
        return self::criticityName((int) ($infocom->fields['businesscriticities_id'] ?? 0));
    }

    private static function criticityName(int $criticity): string
    {
        return $criticity > 0 ? (string) Dropdown::getDropdownName('glpi_businesscriticities', $criticity) : '';
    }

    /**
     * Analyse d'un processus : grille de la BIA, cohérence de ses objectifs, éléments dont il dépend
     * (directement, ou par l'analyse d'impact de GLPI) et ceux qui ne tiennent pas son RTO ou son RPO.
     * Seuls les éléments que la personne peut lire sont décrits ; les autres sont comptés.
     *
     * @return array<string, mixed>
     */
    public static function analyze(self $process): array
    {
        $processId = (int) $process->getID();
        $grid = BusinessImpactRules::grid(BusinessImpact::getRows($processId));
        $mtpd = (int) ($process->fields['mtpd_hours'] ?? 0);
        $rto = (int) ($process->fields['rto_hours'] ?? 0);
        $rpo = (int) ($process->fields['rpo_hours'] ?? 0);

        // Dépendances directes (profondeur 0), puis ce dont elles dépendent selon GLPI.
        $depths = [];
        $links = [];
        foreach (BusinessProcessItem::getForProcess($processId) as $link) {
            $key = ImpactGraph::key((string) $link['itemtype'], (int) $link['items_id']);
            $depths[$key] = 0;
            $links[$key] = $link;
        }
        $edges = ImpactAnalysis::loadEdges();
        foreach (array_keys($links) as $key) {
            foreach (ImpactGraph::upstream($edges, $key) as $upstream => $depth) {
                if (!isset($depths[$upstream]) || $depths[$upstream] > $depth) {
                    $depths[$upstream] = $depth;
                }
            }
        }

        $allObjectives = self::assetObjectives();
        $classifiable = AssetClassification::getItemtypes();
        $dependencies = [];
        $objectives = [];
        $hidden = 0;
        foreach ($depths as $key => $depth) {
            $node = ImpactAnalysis::describe($key);
            if (!$node['visible']) {
                $hidden++;
                continue;
            }
            [$itemtype, $itemsId] = ImpactGraph::split($key);
            // Tout élément classable est contrôlé : actifs, et depuis la troisième étape de l'issue
            // #377 applications, instances de bases de données et fournisseurs. Les autres éléments
            // atteints par l'analyse d'impact de GLPI (réseau, câbles…) n'ont pas d'objectif.
            if (in_array($itemtype, $classifiable, true)) {
                $objectives[$key] = $allObjectives[$key] ?? ['rto' => 0, 'rpo' => 0];
            }
            $dependencies[$key] = $node + [
                'key'       => $key,
                'itemtype'  => $itemtype,
                'items_id'  => $itemsId,
                'depth'     => $depth,
                'link_id'   => (int) ($links[$key]['id'] ?? 0),
                'comment'   => (string) ($links[$key]['comment'] ?? ''),
                'criticity' => self::itemCriticity($itemtype, $itemsId),
                'issues'    => [],
            ];
        }
        foreach (BusinessImpactRules::dependencyIssues($rto, $rpo, $objectives) as $issue) {
            // « RTO non défini » vise ici l'élément, pas le processus.
            $dependencies[$issue['key']]['issues'][] = $issue['issue'] === 'rto_missing'
                ? 'asset_rto_missing' : $issue['issue'];
        }
        uasort(
            $dependencies,
            static fn (array $a, array $b): int => [$a['depth'], $a['label']] <=> [$b['depth'], $b['label']]
        );

        return [
            'grid'               => $grid,
            'worst'              => BusinessImpactRules::worstByHorizon($grid),
            'first_unacceptable' => BusinessImpactRules::firstUnacceptableHorizon($grid),
            'issues'             => BusinessImpactRules::objectiveIssues($mtpd, $rto, $rpo, $grid),
            'dependencies'       => array_values($dependencies),
            'hidden'             => $hidden,
        ];
    }

    /**
     * Message de chaque contrôle de cohérence (BusinessImpactRules).
     *
     * @return array<string, string>
     */
    public static function getIssueLabels(): array
    {
        return [
            'bia_missing'       => __('No impact assessed yet: fill in the business impact analysis.', 'grcmanager'),
            'mtpd_missing'      => __('Maximum tolerable period of disruption (MTPD) not set.', 'grcmanager'),
            'rto_missing'       => __('Recovery time objective (RTO) not set.', 'grcmanager'),
            'rto_above_mtpd'    => __(
                'The RTO is longer than the maximum tolerable period of disruption.',
                'grcmanager'
            ),
            'mtpd_above_impact' => __(
                'The maximum tolerable period of disruption is longer than the duration from which the impact '
                    . 'becomes serious.',
                'grcmanager'
            ),
            'rpo_above_mtpd'    => __(
                'The RPO is longer than the maximum tolerable period of disruption.',
                'grcmanager'
            ),
            'impact_decreasing' => __(
                'An impact decreases when the disruption lasts longer: check the analysis.',
                'grcmanager'
            ),
            'rto_slower'        => __('Recovers more slowly than the process requires (RTO)', 'grcmanager'),
            'rpo_larger'        => __('May lose more data than the process accepts (RPO)', 'grcmanager'),
            'asset_rto_missing' => __('No RTO set on this item (C/I/A classification tab)', 'grcmanager'),
            'process_rto_missing' => __('No RTO set on this process', 'grcmanager'),
        ];
    }

    /**
     * Issue #377 : plan de continuité (PCA / PRA) au format PDF, pour un processus ou pour tous ceux
     * que la personne peut lire. Ne contient que ce qu'elle peut lire : processus (droit et entité),
     * éléments et processus liés lisibles (les autres sont comptés), risques seulement avec le droit
     * du registre des risques. L'appelant trace l'export.
     */
    public static function getContinuityReportURL(int $id = 0): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/businessprocess.report.php'
            . ($id > 0 ? '?id=' . $id : '');
    }

    public static function renderContinuityReport(?self $only = null): string
    {
        $processes = [];
        if ($only !== null) {
            $processes[] = $only;
        } else {
            $criteria = getEntitiesRestrictCriteria(self::getTable(), '', '', true);
            foreach ((new self())->find($criteria, ['name ASC']) as $row) {
                $process = new self();
                if ($process->getFromDB((int) $row['id']) && $process->canViewItem()) {
                    $processes[] = $process;
                }
            }
        }

        $durations = self::getDurationOptions();
        $levels = BusinessImpact::getLevelLabels();
        $issueLabels = self::getIssueLabels();
        $showRisks = Risk::canView();
        $label = static fn (array $codes): array => array_map(
            static fn (string $code): string => $issueLabels[$code] ?? $code,
            $codes
        );
        $sheets = [];
        foreach ($processes as $process) {
            $fields = $process->fields;
            $analysis = self::analyze($process);
            $links = BusinessProcessLink::analyze($process);
            $grid = [];
            foreach (BusinessImpactRules::HORIZONS as $horizon) {
                $cells = [];
                foreach (BusinessImpactRules::CATEGORIES as $category) {
                    $value = (int) ($analysis['grid'][$horizon][$category] ?? 0);
                    $cells[] = ['level' => $value, 'label' => $value > 0 ? $levels[$value] : ''];
                }
                $worst = (int) ($analysis['worst'][$horizon] ?? 0);
                $grid[] = [
                    'horizon' => $durations[$horizon] ?? $horizon . ' h',
                    'cells'   => $cells,
                    'worst'   => ['level' => $worst, 'label' => $worst > 0 ? $levels[$worst] : ''],
                ];
            }
            $dependencies = [];
            $late = 0;
            foreach ($analysis['dependencies'] as $dependency) {
                $late += $dependency['issues'] !== [] ? 1 : 0;
                $dependencies[] = [
                    'label'     => $dependency['label'],
                    'depth'     => $dependency['depth'],
                    'comment'   => $dependency['comment'],
                    'criticity' => $dependency['criticity'],
                    'rto'       => $durations[$dependency['rto']] ?? '',
                    'rpo'       => $durations[$dependency['rpo']] ?? '',
                    'issues'    => $label($dependency['issues']),
                ];
            }
            $upstream = [];
            foreach ($links['upstream'] as $node) {
                $late += $node['issues'] !== [] ? 1 : 0;
                $upstream[] = [
                    'name'    => $node['name'],
                    'depth'   => $node['depth'],
                    'comment' => $node['comment'],
                    'rto'     => $durations[$node['rto']] ?? '',
                    'rpo'     => $durations[$node['rpo']] ?? '',
                    'issues'  => $label($node['issues']),
                ];
            }
            $sheets[] = [
                'name'         => (string) $fields['name'],
                'entity'       => (string) Dropdown::getDropdownName('glpi_entities', (int) $fields['entities_id']),
                'owner'        => (int) $fields['users_id'] > 0 ? getUserName((int) $fields['users_id']) : '',
                'group'        => (int) $fields['groups_id'] > 0
                    ? (string) Dropdown::getDropdownName('glpi_groups', (int) $fields['groups_id']) : '',
                'criticity'    => self::criticityName((int) $fields['businesscriticities_id']),
                'mtpd'         => $durations[(int) $fields['mtpd_hours']] ?? '',
                'rto'          => $durations[(int) $fields['rto_hours']] ?? '',
                'rpo'          => $durations[(int) $fields['rpo_hours']] ?? '',
                'bia_date'     => !empty($fields['bia_date']) ? \Html::convDate((string) $fields['bia_date']) : '',
                'description'  => (string) ($fields['description'] ?? ''),
                'grid'         => $grid,
                'issues'       => $label($analysis['issues']),
                'dependencies' => $dependencies,
                'hidden_items' => (int) $analysis['hidden'],
                'upstream'     => $upstream,
                'downstream'   => array_column($links['downstream'], 'name'),
                'required'     => [
                    'rto'      => $durations[$links['required']['rto']] ?? '',
                    'rto_from' => $links['required']['rto_from'],
                    'rpo'      => $durations[$links['required']['rpo']] ?? '',
                    'rpo_from' => $links['required']['rpo_from'],
                ],
                'hidden_processes' => (int) $links['hidden'],
                'risks'        => $showRisks ? BusinessProcessRisk::getRisksForProcess((int) $process->getID()) : [],
                'late'         => $late,
            ];
        }

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/business_continuity_report.html.twig', [
            'report_title'       => $only !== null
                ? sprintf(__('Business continuity plan: %s', 'grcmanager'), $only->fields['name'])
                : __('Business continuity plan', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %1$s by %2$s', 'grcmanager'),
                \Html::convDateTime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                getUserName((int) Session::getLoginUserID())
            ),
            'sheets'             => $sheets,
            'show_summary'       => $only === null,
            'show_risks'         => $showRisks,
            'categories'         => BusinessImpact::getCategoryLabels(),
        ]);
    }

    /**
     * Export pour le PCA / PRA (CSV) : processus lisibles par la personne, leurs objectifs, le pire
     * impact par durée d'interruption et leurs dépendances lisibles en écart.
     *
     * @return list<list<string>>
     */
    public static function continuityRows(): array
    {
        $durations = self::getDurationOptions();
        $header = [
            self::getTypeName(1),
            __('Entity'),
            __('Owner', 'grcmanager'),
            __('Business criticality', 'grcmanager'),
            __('MTPD', 'grcmanager'),
            'RTO',
            'RPO',
            __('Last business impact analysis', 'grcmanager'),
        ];
        foreach (BusinessImpactRules::HORIZONS as $horizon) {
            $header[] = sprintf(__('Worst impact after %s', 'grcmanager'), $durations[$horizon] ?? $horizon . ' h');
        }
        $header[] = __('Depends on', 'grcmanager');
        $header[] = __('Items not meeting the objectives', 'grcmanager');
        $header[] = __('Consistency checks', 'grcmanager');

        $levels = BusinessImpact::getLevelLabels();
        $issueLabels = self::getIssueLabels();
        $rows = [$header];
        $process = new self();
        $criteria = getEntitiesRestrictCriteria(self::getTable(), '', '', true);
        foreach ($process->find($criteria, ['name ASC']) as $row) {
            if (!$process->getFromDB((int) $row['id']) || !$process->canViewItem()) {
                continue;
            }
            $analysis = self::analyze($process);
            $line = [
                (string) $row['name'],
                (string) Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                self::criticityName((int) $row['businesscriticities_id']),
                $durations[(int) $row['mtpd_hours']] ?? '',
                $durations[(int) $row['rto_hours']] ?? '',
                $durations[(int) $row['rpo_hours']] ?? '',
                (string) ($row['bia_date'] ?? ''),
            ];
            foreach ($analysis['worst'] as $level) {
                $line[] = $level > 0 ? $levels[$level] : '';
            }
            $line[] = implode(', ', array_column(
                array_filter($analysis['dependencies'], static fn (array $d): bool => $d['depth'] === 0),
                'label'
            ));
            $late = [];
            foreach ($analysis['dependencies'] as $dependency) {
                if ($dependency['issues'] !== []) {
                    $late[] = $dependency['label'];
                }
            }
            $line[] = implode(', ', $late);
            $line[] = implode(' ', array_map(
                static fn (string $code): string => $issueLabels[$code] ?? $code,
                $analysis['issues']
            ));
            $rows[] = $line;
        }

        return $rows;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Owner', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 4, 'table' => 'glpi_groups', 'field' => 'completename', 'linkfield' => 'groups_id',
            'name' => __('Group'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 5, 'table' => 'glpi_businesscriticities', 'field' => 'completename',
            'linkfield' => 'businesscriticities_id', 'name' => __('Business criticality', 'grcmanager'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 6, 'table' => self::getTable(), 'field' => 'mtpd_hours', 'name' => __('MTPD', 'grcmanager'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 7, 'table' => self::getTable(), 'field' => 'rto_hours', 'name' => 'RTO',
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 8, 'table' => self::getTable(), 'field' => 'rpo_hours', 'name' => 'RPO',
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 9, 'table' => self::getTable(), 'field' => 'bia_date',
            'name' => __('Last business impact analysis', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 10, 'table' => self::getTable(), 'field' => 'description', 'name' => __('Description'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 11, 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (in_array($field, ['mtpd_hours', 'rto_hours', 'rpo_hours'], true)) {
            return htmlescape(self::getDurationOptions()[(int) ($values[$field] ?? 0)] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (in_array($field, ['mtpd_hours', 'rto_hours', 'rpo_hours'], true)) {
            return Dropdown::showFromArray($name, [0 => Dropdown::EMPTY_VALUE] + self::getDurationOptions(), [
                'value' => $values[$field] ?? 0, 'display' => false,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
