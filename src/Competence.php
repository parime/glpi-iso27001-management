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

use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CompetenceBase;
use GlpiPlugin\Grcmanager\Install\CompetenceTables;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\Competence\DefaultCompetences;
use Log;
use Session;

/**
 * Issue #774 : compétence en sécurité de l'information (catalogue de l'organisation), et les
 * formations du plugin qui la donnent à un niveau une fois réussies.
 *
 * Droit général du plugin et entité de la compétence (check() du cœur). Une compétence déjà
 * déclarée pour une personne ne se supprime pas : la preuve resterait sans objet.
 */
class Competence extends CompetenceBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FK = 'plugin_grcmanager_competences_id';

    public static function getTable($classname = null)
    {
        return CompetenceTables::COMPETENCES;
    }

    public static function getTypeName($nb = 0)
    {
        // « Competence » existe déjà sans pluriel (titre de l'article 7.2) : deux chaînes simples.
        return $nb > 1 ? __('Competences', 'grcmanager') : __('Competence', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-certificate';
    }

    /**
     * @return array<string, string>
     */
    public static function defaultLabels(): array
    {
        return [
            'awareness'             => __('Information security awareness', 'grcmanager'),
            'isms_governance'       => __('ISMS governance and ISO 27001 requirements', 'grcmanager'),
            'risk_management'       => __('Information security risk management', 'grcmanager'),
            'incident_response'     => __('Security incident handling', 'grcmanager'),
            'access_management'     => __('Access rights management', 'grcmanager'),
            'secure_administration' => __('Secure administration of systems and networks', 'grcmanager'),
            'secure_development'    => __('Secure development', 'grcmanager'),
            'data_protection'       => __('Personal data protection (GDPR)', 'grcmanager'),
            'business_continuity'   => __('Business continuity and backups', 'grcmanager'),
            'internal_audit'        => __('ISMS internal audit', 'grcmanager'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function label(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return self::defaultLabels()[(string) ($row['default_key'] ?? '')] ?? '';
    }

    public function getName($options = [])
    {
        $label = self::label($this->fields);

        return $label !== '' ? $label : parent::getName($options);
    }

    /**
     * Noms des compétences citées (quelle que soit leur entité : elles le sont par une fonction ou
     * une déclaration déjà lisible).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function labelsFor(array $ids): array
    {
        global $DB;

        $labels = [];
        if ($ids === []) {
            return $labels;
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            $labels[(int) $row['id']] = self::label($row);
        }

        return $labels;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        $key = (string) ($input['default_key'] ?? '');
        $input['default_key'] = DefaultCompetences::competenceExists($key) ? $key : '';

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['default_key']);
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
        }
        if (self::label(array_merge($current, $input)) === '') {
            Session::addMessageAfterRedirect(__('Give the competence a name.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        if (countElementsInTable(CompetenceTables::DECLARATIONS, [self::FK => (int) $this->getID()]) > 0) {
            Session::addMessageAfterRedirect(
                __('This competence has been declared for people: it cannot be deleted.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        return true;
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(CompetenceTables::REQUIREMENTS, [self::FK => (int) $this->getID()]);
        $DB->delete(CompetenceTables::TRAININGS, [self::FK => (int) $this->getID()]);
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->applyTrainingInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->applyTrainingInput();
    }

    private function applyTrainingInput(): void
    {
        global $DB;

        $competenceId = (int) $this->getID();
        $removed = array_map('intval', (array) ($this->input['_remove_trainings'] ?? []));
        if ($removed !== []) {
            $DB->delete(CompetenceTables::TRAININGS, ['id' => $removed, self::FK => $competenceId]);
        }
        $trainingId = (int) ($this->input['_add_trainings_id'] ?? 0);
        if ($trainingId <= 0) {
            return;
        }
        // Une session donne la compétence par sa formation principale : c'est elle qui est liée.
        $training = new Training();
        // Issue #536, lot 3 : seulement une formation lisible (entités de la session).
        if (!$training->getFromDB($trainingId) || (EntityPerimeter::hasSession() && !$training->canViewItem())) {
            return;
        }
        $mainId = (int) ($training->fields['plugin_grcmanager_trainings_id'] ?? 0);
        $DB->updateOrInsert(CompetenceTables::TRAININGS, [
            'level'         => Rules::normalizeLevel($this->input['_add_training_level'] ?? 1),
            'date_creation' => date('Y-m-d H:i:s'),
        ], [self::FK => $competenceId, Training::getForeignKeyField() => $mainId > 0 ? $mainId : $trainingId]);
    }

    /**
     * Formations qui donnent les compétences : compétence => formation principale => niveau.
     *
     * @param list<int> $competenceIds
     * @return array<int, array<int, int>>
     */
    public static function trainingsFor(array $competenceIds): array
    {
        global $DB;

        $grants = [];
        if ($competenceIds === []) {
            return $grants;
        }
        $fk = Training::getForeignKeyField();
        $criteria = ['FROM' => CompetenceTables::TRAININGS, 'WHERE' => [self::FK => $competenceIds]];
        foreach ($DB->request($criteria) as $row) {
            $grants[(int) $row[self::FK]][(int) $row[$fk]] = Rules::normalizeLevel($row['level']);
        }

        return $grants;
    }

    /**
     * Intitulés des formations citées.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function trainingTitles(array $ids): array
    {
        global $DB;

        $titles = [];
        if ($ids === []) {
            return $titles;
        }
        $criteria = ['SELECT' => ['id', 'title'], 'FROM' => Training::getTable(), 'WHERE' => ['id' => $ids]];
        foreach ($DB->request($criteria) as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        return $titles;
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
            'id' => 3, 'table' => $table, 'field' => 'description', 'name' => __('Description'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $trainings = [];
        if (!$this->isNewItem()) {
            global $DB;

            $rows = iterator_to_array($DB->request([
                'FROM'  => CompetenceTables::TRAININGS,
                'WHERE' => [self::FK => (int) $ID],
                'ORDER' => 'id',
            ]), false);
            $fk = Training::getForeignKeyField();
            $titles = self::trainingTitles(array_map(static fn ($r): int => (int) $r[$fk], $rows));
            foreach ($rows as $row) {
                $trainingId = (int) $row[Training::getForeignKeyField()];
                $trainings[] = [
                    'id'    => (int) $row['id'],
                    'title' => $titles[$trainingId] ?? sprintf(__('Deleted item #%d', 'grcmanager'), $trainingId),
                    'url'   => Training::getFormURLWithID($trainingId),
                    'level' => Rules::normalizeLevel($row['level']),
                ];
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/competence_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'default_label'  => self::defaultLabels()[(string) ($this->fields['default_key'] ?? '')] ?? '',
            'trainings'      => $trainings,
            'main_trainings' => [0 => '-----'] + array_diff_key(Training::getMainTrainings(), [0 => true]),
            'levels'         => IsmsFunction::levelLabels(),
            'training_label' => Training::getTypeName(1),
        ]);

        return true;
    }
}
