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

use Document_Item;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\IsmsFunctionBase;
use GlpiPlugin\Grcmanager\Install\CompetenceTables;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\Competence\DefaultCompetences;
use Group;
use Log;
use Session;
use User;
use UserTitle;

/**
 * Issue #774 : fonction du SMSI (RSSI, DPO, administrateur, développeur...) et les compétences
 * qu'elle exige (ISO 27001, article 7.2), avec le niveau attendu et la façon de l'acquérir.
 *
 * Les titulaires sont des utilisateurs, des groupes ou des titres (intitulés de poste) de GLPI :
 * rien n'est recréé, et les titres posés par le plugin Configuration GLPI Auto servent tels quels.
 * Droit général du plugin et entité de la fonction (check() du cœur) ; les utilisateurs, groupes et
 * compétences acceptés sont ceux de l'entité de la fonction.
 */
class IsmsFunction extends IsmsFunctionBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FK = 'plugin_grcmanager_ismsfunctions_id';

    public static function getTable($classname = null)
    {
        return CompetenceTables::FUNCTIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ISMS function', 'ISMS functions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-id-badge-2';
    }

    /** Sous-page de la rubrique « Revue des accès » : la page des compétences. */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => __('Competences', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /**
     * Libellés traduits des fonctions proposées par défaut.
     *
     * @return array<string, string>
     */
    public static function defaultLabels(): array
    {
        return [
            'ciso'           => __('Chief information security officer (CISO)', 'grcmanager'),
            'dpo'            => __('Data protection officer (DPO)', 'grcmanager'),
            'top_management' => __('Top management', 'grcmanager'),
            'sysadmin'       => __('System and network administrator', 'grcmanager'),
            'developer'      => __('Developer', 'grcmanager'),
            'technician'     => __('Support technician', 'grcmanager'),
            'all_staff'      => __('All staff', 'grcmanager'),
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

    /** @return array<int, string> */
    public static function levelLabels(): array
    {
        return [
            Rules::LEVEL_BASIC      => _x('competence level', 'Basic', 'grcmanager'),
            Rules::LEVEL_PROFICIENT => _x('competence level', 'Proficient', 'grcmanager'),
            Rules::LEVEL_EXPERT     => _x('competence level', 'Expert', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function modeLabels(): array
    {
        return [
            'training'      => __('Training', 'grcmanager'),
            'diploma'       => __('Diploma', 'grcmanager'),
            'certification' => __('Professional certification', 'grcmanager'),
            'experience'    => __('Experience', 'grcmanager'),
            'other'         => __('Other', 'grcmanager'),
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        $key = (string) ($input['default_key'] ?? '');
        $input['default_key'] = DefaultCompetences::functionExists($key) ? $key : '';

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
            Session::addMessageAfterRedirect(__('Give the function a name.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->applyLinkInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->applyLinkInput();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(CompetenceTables::HOLDERS, [self::FK => (int) $this->getID()]);
        $DB->delete(CompetenceTables::REQUIREMENTS, [self::FK => (int) $this->getID()]);
    }

    /**
     * Titulaires des fonctions.
     *
     * @param list<int> $functionIds
     * @return array<int, list<array{id: int, itemtype: string, items_id: int}>>
     */
    public static function holdersFor(array $functionIds): array
    {
        global $DB;

        $byFunction = [];
        if ($functionIds === []) {
            return $byFunction;
        }
        foreach (
            $DB->request([
                'FROM'  => CompetenceTables::HOLDERS,
                'WHERE' => [self::FK => $functionIds],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $byFunction[(int) $row[self::FK]][] = [
                'id'       => (int) $row['id'],
                'itemtype' => (string) $row['itemtype'],
                'items_id' => (int) $row['items_id'],
            ];
        }

        return $byFunction;
    }

    /**
     * Compétences exigées par les fonctions.
     *
     * @param list<int> $functionIds
     * @return array<int, array<int, array{id: int, level: int, mode: string}>>
     */
    public static function requirementsFor(array $functionIds): array
    {
        global $DB;

        $byFunction = [];
        if ($functionIds === []) {
            return $byFunction;
        }
        foreach (
            $DB->request([
                'FROM'  => CompetenceTables::REQUIREMENTS,
                'WHERE' => [self::FK => $functionIds],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $byFunction[(int) $row[self::FK]][(int) $row[Competence::FK]] = [
                'id'    => (int) $row['id'],
                'level' => Rules::normalizeLevel($row['required_level']),
                'mode'  => Rules::normalizeMode($row['acquisition_mode']),
            ];
        }

        return $byFunction;
    }

    /**
     * Le titulaire appartient-il à l'entité de la fonction ? Les titres de GLPI n'ont pas d'entité.
     */
    public static function holderAllowed(string $itemtype, int $id, int $entityId, bool $recursive): bool
    {
        if ($itemtype === 'UserTitle') {
            return $id > 0 && (new UserTitle())->getFromDB($id);
        }
        if ($itemtype !== 'User' && $itemtype !== 'Group') {
            return false;
        }

        return ResponsibilityActivity::principalAllowed($itemtype, $id, $entityId, $recursive);
    }

    /**
     * La compétence est-elle visible depuis l'entité de la fonction (même entité, ou entité parente
     * avec une compétence récursive) ?
     */
    public static function competenceAllowed(int $competenceId, int $entityId): bool
    {
        $competence = new Competence();
        if ($competenceId <= 0 || !$competence->getFromDB($competenceId)) {
            return false;
        }
        $own = (int) $competence->fields['entities_id'];
        if ($own === $entityId) {
            return true;
        }

        return !empty($competence->fields['is_recursive'])
            && in_array($own, array_map('intval', getAncestorsOf('glpi_entities', $entityId)), true);
    }

    /**
     * Ajouts et retraits saisis dans le formulaire (titulaires, compétences exigées). Un ajout refusé
     * est signalé, le reste de la fiche est enregistré.
     */
    private function applyLinkInput(): void
    {
        global $DB;

        $functionId = (int) $this->getID();
        $entityId = (int) ($this->fields['entities_id'] ?? 0);
        $recursive = !empty($this->fields['is_recursive']);
        $now = date('Y-m-d H:i:s');

        $removed = array_map('intval', (array) ($this->input['_remove_holders'] ?? []));
        if ($removed !== []) {
            $DB->delete(CompetenceTables::HOLDERS, ['id' => $removed, self::FK => $functionId]);
        }
        $fields = ['User' => '_add_users_id', 'Group' => '_add_groups_id', 'UserTitle' => '_add_usertitles_id'];
        foreach ($fields as $type => $field) {
            $id = (int) ($this->input[$field] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!self::holderAllowed($type, $id, $entityId, $recursive)) {
                Session::addMessageAfterRedirect(
                    __('This user or group does not belong to the entity of the function.', 'grcmanager'),
                    false,
                    ERROR
                );
                continue;
            }
            $exists = countElementsInTable(CompetenceTables::HOLDERS, [
                self::FK => $functionId, 'itemtype' => $type, 'items_id' => $id,
            ]) > 0;
            if (!$exists) {
                $DB->insert(CompetenceTables::HOLDERS, [
                    self::FK => $functionId, 'itemtype' => $type, 'items_id' => $id, 'date_creation' => $now,
                ]);
            }
        }

        $removed = array_map('intval', (array) ($this->input['_remove_requirements'] ?? []));
        if ($removed !== []) {
            $DB->delete(CompetenceTables::REQUIREMENTS, ['id' => $removed, self::FK => $functionId]);
        }
        $competenceId = (int) ($this->input['_add_competences_id'] ?? 0);
        if ($competenceId > 0) {
            if (!self::competenceAllowed($competenceId, $entityId)) {
                Session::addMessageAfterRedirect(
                    __('This competence is not available in the entity of the function.', 'grcmanager'),
                    false,
                    ERROR
                );

                return;
            }
            $DB->updateOrInsert(CompetenceTables::REQUIREMENTS, [
                'required_level'   => Rules::normalizeLevel($this->input['_add_level'] ?? 1),
                'acquisition_mode' => Rules::normalizeMode($this->input['_add_mode'] ?? 'training'),
                'date_creation'    => $now,
            ], [self::FK => $functionId, Competence::FK => $competenceId]);
        }
    }

    /**
     * Noms des titulaires, clé « User:12 », « Group:3 » ou « UserTitle:5 ».
     *
     * @param list<array{itemtype: string, items_id: int}> $rows
     * @return array<string, string>
     */
    public static function holderLabels(array $rows): array
    {
        $labels = ResponsibilityActivity::principalLabels(array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['itemtype'] !== 'UserTitle'
        )));
        foreach ($rows as $row) {
            if ($row['itemtype'] === 'UserTitle') {
                $title = new UserTitle();
                $labels['UserTitle:' . $row['items_id']] = $title->getFromDB($row['items_id'])
                    ? sprintf(__('Job title: %s', 'grcmanager'), (string) $title->fields['name'])
                    : sprintf(__('Deleted item #%d', 'grcmanager'), $row['items_id']);
            }
        }

        return $labels;
    }

    /**
     * Ajoute dans l'entité les compétences et les fonctions par défaut qui n'y sont pas encore
     * visibles, avec leurs exigences. Ne modifie rien d'existant. Renvoie le nombre de fonctions et
     * de compétences ajoutées.
     *
     * @return array{functions: int, competences: int}
     */
    public static function addDefaults(int $entityId): array
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $competenceIds = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'default_key'],
                'FROM'   => Competence::getTable(),
                'WHERE'  => array_merge(
                    [['NOT' => ['default_key' => '']]],
                    EntityPerimeter::criteria(Competence::getTable())
                ),
            ]) as $row
        ) {
            $competenceIds[(string) $row['default_key']] ??= (int) $row['id'];
        }
        $added = ['functions' => 0, 'competences' => 0];
        foreach (DefaultCompetences::COMPETENCES as $key) {
            if (isset($competenceIds[$key])) {
                continue;
            }
            // Nom enregistré dans la langue de la personne qui ajoute : lisible dans les listes de GLPI.
            $DB->insert(Competence::getTable(), [
                'entities_id' => $entityId, 'is_recursive' => 1, 'default_key' => $key,
                'name' => Competence::defaultLabels()[$key],
                'date_creation' => $now, 'date_mod' => $now,
            ]);
            $competenceIds[$key] = (int) $DB->insertId();
            $added['competences']++;
        }

        $existing = [];
        foreach (
            $DB->request([
                'SELECT' => ['default_key'],
                'FROM'   => self::getTable(),
                'WHERE'  => array_merge(
                    [['NOT' => ['default_key' => '']]],
                    EntityPerimeter::criteria(self::getTable())
                ),
            ]) as $row
        ) {
            $existing[(string) $row['default_key']] = true;
        }
        foreach (DefaultCompetences::FUNCTIONS as $key => $requirements) {
            if (isset($existing[$key])) {
                continue;
            }
            $DB->insert(self::getTable(), [
                'entities_id' => $entityId, 'is_recursive' => 1, 'default_key' => $key,
                'name' => self::defaultLabels()[$key],
                'date_creation' => $now, 'date_mod' => $now,
            ]);
            $functionId = (int) $DB->insertId();
            foreach ($requirements as $competenceKey => [$level, $mode]) {
                $DB->insert(CompetenceTables::REQUIREMENTS, [
                    self::FK           => $functionId,
                    Competence::FK     => $competenceIds[$competenceKey],
                    'required_level'   => $level,
                    'acquisition_mode' => $mode,
                    'date_creation'    => $now,
                ]);
            }
            $added['functions']++;
        }

        return $added;
    }

    /**
     * Fonctions des entités de la session (ou toutes, hors restriction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visibleFunctions(bool $restrict = true): array
    {
        global $DB;

        $rows = [];
        if (!$DB->tableExists(self::getTable())) {
            return $rows;
        }
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $restrict ? EntityPerimeter::criteria(self::getTable()) : [],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }
        uasort($rows, static fn (array $a, array $b): int => strcasecmp(self::label($a), self::label($b)));

        return $rows;
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
            'id' => 3, 'table' => $table, 'field' => 'description', 'name' => __('Mission', 'grcmanager'),
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
        $entityId = $this->isNewItem() ? Session::getActiveEntity() : (int) $this->fields['entities_id'];
        $holders = $this->isNewItem() ? [] : (self::holdersFor([(int) $ID])[(int) $ID] ?? []);
        $labels = self::holderLabels($holders);
        foreach ($holders as &$row) {
            $row['label'] = $labels[$row['itemtype'] . ':' . $row['items_id']] ?? '';
        }
        unset($row);
        $requirements = [];
        if (!$this->isNewItem()) {
            $own = self::requirementsFor([(int) $ID])[(int) $ID] ?? [];
            $names = Competence::labelsFor(array_keys($own));
            foreach ($own as $competenceId => $requirement) {
                $requirements[] = $requirement + [
                    'competence' => $names[$competenceId] ?? '',
                    'url'        => Competence::getFormURLWithID($competenceId),
                ];
            }
            usort($requirements, static fn (array $a, array $b): int => strcasecmp($a['competence'], $b['competence']));
        }

        TemplateRenderer::getInstance()->display('@grcmanager/isms_function_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'default_label' => self::defaultLabels()[(string) ($this->fields['default_key'] ?? '')] ?? '',
            'holders'       => $holders,
            'requirements'  => $requirements,
            'levels'        => self::levelLabels(),
            'modes'         => self::modeLabels(),
            'entity_id'     => $entityId,
            'recursive'     => !empty($this->fields['is_recursive']),
            'user_label'    => User::getTypeName(1),
            'group_label'   => Group::getTypeName(1),
            'title_label'   => UserTitle::getTypeName(1),
            'competence_label' => Competence::getTypeName(1),
            'sheet'         => $this->isNewItem() ? null : CompetenceMatrix::functionSheet($this),
            'pdf_url'       => $this->isNewItem() ? '' : CompetenceMatrix::pdfUrl((int) $ID),
        ]);

        return true;
    }
}
