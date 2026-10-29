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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\BreachExposureBase;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitor;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitorConfig;
use GlpiPlugin\Grcmanager\Services\Breach\BreachRules;
use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;
use Log;
use NotificationEvent;
use Session;
use User;

/**
 * Issue #183 : exposition d'une adresse professionnelle dans une fuite de données connue (Have I Been
 * Pwned), détectée par la tâche planifiée « breachmonitor » (Services\Breach\BreachMonitor).
 *
 * Registre sensible (droit plugin_grcmanager_sensitive, comme les risques) : une ligne par
 * utilisateur et par fuite. Les lignes sont créées par la tâche seulement ; le RSSI en modifie le
 * statut (nouvelle, prise en compte, sans objet) et le commentaire, jamais les données de la fuite.
 *
 * Minimisation : nom, titre, date et catégories de données de la fuite ; l'adresse n'est gardée que
 * si aucun utilisateur GLPI ne la porte. Have I Been Pwned ne fournit jamais de mot de passe.
 */
class BreachExposure extends BreachExposureBase
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public const STATUS_NEW = 'new';
    public const STATUS_HANDLED = 'handled';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const NEW_EXPOSURES_EVENT = 'new_exposures';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_breachexposures';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Data breach exposure', 'Data breach exposures', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-spy';
    }

    /** Créées par la tâche planifiée uniquement. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW            => _x('breach', 'New', 'grcmanager'),
            self::STATUS_HANDLED        => _x('breach', 'Handled (password changed…)', 'grcmanager'),
            self::STATUS_NOT_APPLICABLE => _x('breach', 'Not applicable', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * Seuls le statut et le commentaire se modifient : les données de la fuite viennent de l'API.
     */
    public function prepareInputForUpdate($input)
    {
        $input = array_intersect_key((array) $input, array_flip(['id', 'status', 'comment']));
        if (isset($input['status']) && !isset(self::getStatuses()[(string) $input['status']])) {
            unset($input['status']);
        }
        if ($input !== []) {
            $input['date_mod'] = date('Y-m-d H:i:s');
        }

        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $table = $this->getTable();
        $tab[] = ['id' => 1, 'table' => $table, 'field' => 'breach_title', 'name' => __('Breach', 'grcmanager'),
            'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false];
        $tab[] = ['id' => 2, 'table' => 'glpi_users', 'field' => 'name', 'name' => User::getTypeName(1),
            'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false];
        $tab[] = ['id' => 3, 'table' => $table, 'field' => 'email', 'name' => __('Email (no GLPI user)', 'grcmanager'),
            'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => 4, 'table' => $table, 'field' => 'breach_date', 'name' => __('Breach date', 'grcmanager'),
            'datatype' => 'date', 'massiveaction' => false];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'added_date',
            'name' => __('Published by Have I Been Pwned', 'grcmanager'), 'datatype' => 'datetime',
            'massiveaction' => false];
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'data_classes', 'name' => __('Exposed data', 'grcmanager'),
            'datatype' => 'specific', 'nosearch' => true, 'massiveaction' => false];
        $tab[] = ['id' => 7, 'table' => $table, 'field' => 'status', 'name' => __('Status', 'grcmanager'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']];
        $tab[] = ['id' => 8, 'table' => $table, 'field' => 'date_creation', 'name' => __('Detected on', 'grcmanager'),
            'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => 9, 'table' => $table, 'field' => 'breach_domain', 'name' => __('Breached site', 'grcmanager'),
            'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => 10, 'table' => $table, 'field' => 'is_verified',
            'name' => __('Verified breach', 'grcmanager'),
            'datatype' => 'bool', 'massiveaction' => false];
        $tab[] = ['id' => 11, 'table' => $table, 'field' => 'is_spam_list', 'name' => __('Spam list', 'grcmanager'),
            'datatype' => 'bool', 'massiveaction' => false];
        $tab[] = ['id' => 12, 'table' => $table, 'field' => 'comment', 'name' => __('Comments', 'grcmanager'),
            'datatype' => 'text'];
        $tab[] = ['id' => 13, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink',
            'itemtype' => self::class, 'massiveaction' => false];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return match ($field) {
            'status'       => htmlescape(self::getStatuses()[(string) ($values[$field] ?? '')] ?? ''),
            'data_classes' => htmlescape(implode(', ', self::dataClasses((string) ($values[$field] ?? '')))),
            default        => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value'] = $values[$field] ?? '';

        return $field === 'status'
            ? Dropdown::showFromArray($name, self::getStatuses(), $options)
            : parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * @return list<string>
     */
    public static function dataClasses(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $classes = self::dataClasses((string) ($this->fields['data_classes'] ?? ''));

        TemplateRenderer::getInstance()->display('@grcmanager/breach_exposure_form.html.twig', [
            'item'              => $this,
            'params'            => $options + ['candel' => false],
            'statuses'          => self::getStatuses(),
            'data_classes'      => $classes,
            'exposes_passwords' => BreachRules::exposesPasswords($classes),
            'user_link'         => (int) $this->fields['users_id'] > 0
                ? getUserLink((int) $this->fields['users_id']) : '',
        ]);

        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof User || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = $_SESSION['glpishow_count_on_tabs']
            ? countElementsInTable(self::getTable(), ['users_id' => $item->getID()]) : 0;

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof User || $item->isNewItem() || !self::canView()) {
            return false;
        }
        $rows = [];
        foreach ((new self())->find(['users_id' => $item->getID()], ['added_date DESC']) as $row) {
            $row['data_classes'] = self::dataClasses((string) $row['data_classes']);
            $row['status_label'] = self::getStatuses()[(string) $row['status']] ?? '';
            $row['url'] = self::getFormURLWithID((int) $row['id']);
            $rows[] = $row;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/breach_exposures.html.twig', [
            'rows'      => $rows,
            'enabled'   => BreachMonitorConfig::isEnabled(),
        ]);

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'breachmonitor'
            ? ['description' => __(
                'Data breach monitoring: checks work email addresses with Have I Been Pwned',
                'grcmanager'
            )]
            : [];
    }

    public static function cronBreachmonitor(CronTask $task): int
    {
        if (!BreachMonitorConfig::isEnabled() || !BreachMonitorConfig::hasApiKey()) {
            return 0;
        }
        $monitor = new BreachMonitor(new HibpClient(BreachMonitorConfig::apiKey()));
        $result = $monitor->run(BreachMonitorConfig::domains(), BreachMonitorConfig::cursor());
        BreachMonitorConfig::setCursor($result->cursor);
        if ($result->error !== '') {
            $task->log(sprintf(__('Have I Been Pwned: error %s', 'grcmanager'), $result->error));
        }
        if ($result->rateLimited) {
            $task->log(__(
                'Have I Been Pwned: rate limit reached, the next run resumes where this one stopped',
                'grcmanager'
            ));
        }
        if ($result->created > 0) {
            NotificationEvent::raiseEvent(self::NEW_EXPOSURES_EVENT, new self(), ['new_count' => $result->created]);
        }
        $task->addVolume($result->checked);

        return $result->checked > 0 ? 1 : 0;
    }
}
