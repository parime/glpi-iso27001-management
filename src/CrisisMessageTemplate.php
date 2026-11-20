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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CrisisMessageTemplateBase;
use GlpiPlugin\Grcmanager\Install\CrisisCommunicationTables;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as Rules;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisMessageDefaults;
use Log;
use Session;

/**
 * Issue #616 : modèle de message de crise (incident majeur, violation de données, indisponibilité,
 * rançongiciel...), avec ses variables remplies depuis l'incident et sa liste de diffusion
 * (CrisisMessageRecipient). Bibliothèque globale gérée avec le droit général du plugin, comme les
 * procédures de remédiation. Le plugin n'envoie jamais le message : la personne le copie ou
 * l'exporte, l'envoie elle-même, puis trace la communication (CrisisCommunication).
 */
class CrisisMessageTemplate extends CrisisMessageTemplateBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return CrisisCommunicationTables::TEMPLATES;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Crisis message template', 'Crisis message templates', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-speakerphone';
    }

    /**
     * @return array<string, string>
     */
    public static function getSituations(): array
    {
        return [
            Rules::SITUATION_MAJOR_INCIDENT => __('Major incident', 'grcmanager'),
            Rules::SITUATION_DATA_BREACH    => __('Personal data breach', 'grcmanager'),
            Rules::SITUATION_UNAVAILABILITY => __('Service unavailability', 'grcmanager'),
            Rules::SITUATION_RANSOMWARE     => __('Ransomware', 'grcmanager'),
            Rules::SITUATION_OTHER          => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStages(): array
    {
        return [
            'first'  => __('First message', 'grcmanager'),
            'update' => __('Situation update', 'grcmanager'),
            'end'    => __('End of crisis', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAudiences(): array
    {
        return [
            'internal' => __('Internal', 'grcmanager'),
            'external' => __('External', 'grcmanager'),
        ];
    }

    /**
     * Variables proposées dans l'aide du formulaire, avec ce qu'elles deviennent.
     *
     * @return array<string, string>
     */
    public static function getVariableLabels(): array
    {
        return [
            'reference'        => __('Reference of the incident (SEC-YYYY-NNNN)', 'grcmanager'),
            'title'            => __('Title of the incident or of the breach', 'grcmanager'),
            'incident_type'    => __('Type of incident', 'grcmanager'),
            'severity'         => __('Severity', 'grcmanager'),
            'detection_date'   => __('Date and time of detection', 'grcmanager'),
            'affected_systems' => __('Affected systems (items linked to the incident)', 'grcmanager'),
            'entity'           => __('Entity'),
            'author'           => __('Person preparing the message', 'grcmanager'),
            'today'            => __('Current date and time', 'grcmanager'),
            'next_update'      => __('Time of the next communication (to be completed)', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(CrisisMessageRecipient::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $used = Rules::variablesIn((string) ($this->fields['subject'] ?? '') . ' ' . ($this->fields['body'] ?? ''));
        TemplateRenderer::getInstance()->display('@grcmanager/crisis_message_template_form.html.twig', [
            'item'       => $this,
            'params'     => $options,
            'situations' => self::getSituations(),
            'stages'     => self::getStages(),
            'audiences'  => self::getAudiences(),
            'variables'  => self::getVariableLabels(),
            'unknown'    => $used['unknown'],
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
            Session::addMessageAfterRedirect(__('The template name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        $lists = ['situation' => Rules::SITUATIONS, 'stage' => Rules::STAGES, 'audience' => Rules::AUDIENCES];
        foreach ($lists as $field => $allowed) {
            if (isset($input[$field]) && !in_array($input[$field], $allowed, true)) {
                $input[$field] = $allowed[0];
            }
        }
        if (array_key_exists('subject', $input)) {
            $input['subject'] = Rules::subject((string) $input['subject']);
        }
        if (array_key_exists('body', $input)) {
            $input['body'] = Rules::body((string) $input['body']);
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        (new CrisisMessageRecipient())->deleteByCriteria([CrisisMessageRecipient::TEMPLATE_FK => $this->getID()]);
    }

    /**
     * Modèles actifs, pour l'onglet d'un incident ou d'une violation et pour la preuve.
     *
     * @return list<array<string, mixed>>
     */
    public static function activeTemplates(): array
    {
        return array_values((new self())->find(['is_active' => 1], ['name ASC']));
    }

    /**
     * Installe les modèles fournis, dans la langue de l'installation. Sans effet si la bibliothèque
     * contient déjà un modèle (mise à jour, ou modèles supprimés exprès).
     */
    public static function seedDefaults(): int
    {
        global $DB;

        if (!$DB->tableExists(self::getTable()) || countElementsInTable(self::getTable()) > 0) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $created = 0;
        foreach (CrisisMessageDefaults::definitions() as $definition) {
            $DB->insert(self::getTable(), $definition + [
                'is_active'     => 1,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
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
        $table = self::getTable();
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $lists = [
            3 => ['situation', __('Situation', 'grcmanager')],
            4 => ['stage', __('Stage', 'grcmanager')],
            5 => ['audience', __('Audience', 'grcmanager')],
        ];
        foreach ($lists as $id => [$field, $label]) {
            $tab[] = [
                'id' => $id, 'table' => $table, 'field' => $field, 'name' => $label,
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
            ];
        }
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'subject', 'name' => __('Subject', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id' => 8, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * @return array<string, string>
     */
    private static function labelsFor(string $field): array
    {
        return match ($field) {
            'situation' => self::getSituations(),
            'stage'     => self::getStages(),
            'audience'  => self::getAudiences(),
            default     => [],
        };
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $labels = self::labelsFor((string) $field);
        if ($labels !== []) {
            return htmlescape($labels[$values[$field] ?? ''] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $labels = self::labelsFor((string) $field);
        if ($labels !== []) {
            return \Dropdown::showFromArray($name, $labels, [
                'value' => $values[$field] ?? '', 'display' => false,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
