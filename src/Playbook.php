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
use GlpiPlugin\Grcmanager\Compatibility\Base\PlaybookBase;
use GlpiPlugin\Grcmanager\Services\Incident\PlaybookDefaults;
use GlpiPlugin\Grcmanager\Services\Incident\PlaybookRules;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #370 : procédure de remédiation type (playbook), bibliothèque globale gérée avec le droit
 * du plugin (RSSI). Ses étapes ordonnées par phase sont copiées en tâches sur un incident ; les
 * incidents gardent leur copie si la procédure change ensuite.
 */
class Playbook extends PlaybookBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_playbooks';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Remediation playbook', 'Remediation playbooks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /**
     * @return array<string, string>
     */
    public static function getPhaseLabels(): array
    {
        return [
            'containment'   => __('Containment', 'grcmanager'),
            'analysis'      => __('Analysis', 'grcmanager'),
            'notification'  => __('Notification', 'grcmanager'),
            'eradication'   => __('Eradication', 'grcmanager'),
            'recovery'      => __('Recovery', 'grcmanager'),
            'post_incident' => __('After the incident', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(PlaybookStep::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/playbook_form.html.twig', [
            'item'       => $this,
            'params'     => $options,
            'categories' => ['' => \Dropdown::EMPTY_VALUE] + PluginGrcmanagerSecurityIncident::getCategories(),
        ]);

        return true;
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
            Session::addMessageAfterRedirect(__('The playbook name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        $categories = PluginGrcmanagerSecurityIncident::getCategories();
        if (isset($input['category']) && !array_key_exists($input['category'], $categories)) {
            $input['category'] = '';
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        (new PlaybookStep())->deleteByCriteria(['plugin_grcmanager_playbooks_id' => $this->getID()]);
    }

    /**
     * Procédures actives proposées pour un incident, celles de sa catégorie en premier.
     *
     * @return list<array{id: int, name: string, category: string, recommended: bool}>
     */
    public static function suggestFor(PluginGrcmanagerSecurityIncident $incident): array
    {
        $playbooks = [];
        foreach ((new self())->find(['is_active' => 1], ['name ASC']) as $row) {
            $playbooks[] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'category' => (string) $row['category'],
            ];
        }

        return PlaybookRules::suggest($playbooks, (string) ($incident->fields['category'] ?? ''));
    }

    /**
     * Installe les cinq procédures fournies, dans la langue de l'installation. Sans effet si la
     * bibliothèque contient déjà une procédure (mise à jour, ou procédures supprimées exprès).
     */
    public static function seedDefaults(): int
    {
        global $DB;

        if (countElementsInTable(self::getTable()) > 0) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $created = 0;
        foreach (PlaybookDefaults::definitions() as $definition) {
            // Textes de PlaybookDefaults, présents dans les catalogues de traduction.
            $DB->insert(self::getTable(), [
                'name'          => __($definition['name'], 'grcmanager'),
                'category'      => $definition['category'],
                'description'   => __($definition['description'], 'grcmanager'),
                'is_active'     => 1,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
            $playbookId = (int) $DB->insertId();
            foreach ($definition['steps'] as $rank => [$phase, $name, $description, $mandatory]) {
                $DB->insert(PlaybookStep::getTable(), [
                    'plugin_grcmanager_playbooks_id' => $playbookId,
                    'rank'          => ($rank + 1) * 10,
                    'phase'         => $phase,
                    'name'          => __($name, 'grcmanager'),
                    'description'   => $description === '' ? '' : __($description, 'grcmanager'),
                    'is_mandatory'  => $mandatory ? 1 : 0,
                    'date_creation' => $now,
                    'date_mod'      => $now,
                ]);
            }
            $created++;
        }

        return $created;
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
            'id' => 3, 'table' => self::getTable(), 'field' => 'category', 'name' => __('Category', 'grcmanager'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 5, 'table' => self::getTable(), 'field' => 'description', 'name' => __('Description'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 6, 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'category') {
            return htmlescape(PluginGrcmanagerSecurityIncident::getCategories()[$values[$field]] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'category') {
            return \Dropdown::showFromArray($name, PluginGrcmanagerSecurityIncident::getCategories(), [
                'value' => $values[$field] ?? '', 'display' => false,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
