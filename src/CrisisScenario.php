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

use CommonDBTM;
use CommonGLPI;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CrisisScenarioBase;
use GlpiPlugin\Grcmanager\Install\CrisisScenarioTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioDefaults as Defaults;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules as Rules;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #776 : scénario de crise sécurité de l'information du plan de continuité (ISO 27001,
 * A.5.29). Constat d'audit : le PCA et le PRA décrivaient la restauration technique, sans scénario de
 * crise intégrant la sécurité. Chaque scénario dit :
 * - quels processus métier il touche (BusinessProcess) ;
 * - le mode dégradé sécurisé (accès limités, validation des actions, communication contrôlée) ;
 * - les rôles de la direction, du RSSI et de la DSI ;
 * - le guide de réponse (procédure de remédiation, Playbook), le scénario d'exercice de crise qui
 *   l'exerce (CrisisExercise) et la situation des modèles de messages de crise (#616).
 *
 * Rien n'est dupliqué : les objets cités restent ceux du plugin, avec leurs propres droits. Un
 * catalogue de cinq scénarios est ajouté à la demande dans l'entité active (CrisisScenarioDefaults),
 * puis modifiable.
 *
 * Droits : ceux de la continuité (Installer::CONTINUITY_RIGHT) et l'entité du scénario, comme les
 * processus métier. Un processus, une procédure, un exercice ou un modèle que la personne ne peut pas
 * lire n'est jamais nommé ; les exercices ne comptent que pour qui lit les incidents.
 */
class CrisisScenario extends CrisisScenarioBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const FK = 'plugin_grcmanager_crisisscenarios_id';

    public const CONTROL_CODE = 'A.5.29';

    public static function getTable($classname = null)
    {
        return CrisisScenarioTables::SCENARIOS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Security crisis scenario', 'Security crisis scenarios', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-urgent';
    }

    public function maybeRecursive()
    {
        return true;
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/crisisscenario.php';
    }

    public static function getExportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/crisisscenario.export.php';
    }

    /** Sous-page de la rubrique « Revue des accès », à côté des processus métier. */
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

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    // ------------------------------------------------------------------
    // Libellés
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public static function gapLabels(): array
    {
        return [
            Rules::GAP_NO_PROCESS       => __('No impacted process', 'grcmanager'),
            Rules::GAP_NO_DEGRADED_MODE => __('No secure degraded mode', 'grcmanager'),
            Rules::GAP_NO_ROLES         => __('Roles to complete', 'grcmanager'),
            Rules::GAP_NO_PLAYBOOK      => __('No response playbook', 'grcmanager'),
            Rules::GAP_NEVER_EXERCISED  => __('Never exercised', 'grcmanager'),
            Rules::GAP_EXERCISE_OVERDUE => __('Not exercised for more than a year', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            'role_management' => __('Management', 'grcmanager'),
            'role_ciso'       => __('CISO', 'grcmanager'),
            'role_it'         => __('IT department', 'grcmanager'),
        ];
    }

    /**
     * Scénarios des exercices de crise, pour la liste du formulaire.
     *
     * @return array<string, string>
     */
    public static function exerciseChoices(): array
    {
        $choices = ['' => Dropdown::EMPTY_VALUE];
        foreach (CrisisExercise::getScenarios() as $key => $scenario) {
            $choices[$key] = $scenario['label'];
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public static function situationChoices(): array
    {
        return ['' => Dropdown::EMPTY_VALUE] + CrisisMessageTemplate::getSituations();
    }

    // ------------------------------------------------------------------
    // Enregistrement
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        $key = (string) ($input['default_key'] ?? '');
        $input['default_key'] = Defaults::exists($key) ? $key : '';

        return $this->normalizeInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        // Le droit est contrôlé sur l'entité actuelle du scénario : un formulaire forgé ne doit pas
        // pouvoir l'envoyer dans une autre entité. La clé par défaut ne change jamais.
        unset($input['entities_id'], $input['default_key']);

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input)
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(
                    htmlescape(__('The scenario name is required.', 'grcmanager')),
                    false,
                    ERROR
                );

                return false;
            }
        }
        if (array_key_exists('plugin_grcmanager_playbooks_id', $input)) {
            // Une procédure ne se choisit qu'avec la lecture de la bibliothèque des procédures.
            if (!Playbook::canView()) {
                unset($input['plugin_grcmanager_playbooks_id']);
            } else {
                $playbookId = (int) $input['plugin_grcmanager_playbooks_id'];
                $input['plugin_grcmanager_playbooks_id'] = $playbookId > 0 && (new Playbook())->getFromDB($playbookId)
                    ? $playbookId
                    : 0;
            }
        }
        if (array_key_exists('exercise_scenario', $input)) {
            $exercise = (string) $input['exercise_scenario'];
            $input['exercise_scenario'] = isset(CrisisExercise::getScenarios()[$exercise]) ? $exercise : '';
        }
        if (array_key_exists('communication_situation', $input)) {
            $situation = (string) $input['communication_situation'];
            $input['communication_situation'] = isset(CrisisMessageTemplate::getSituations()[$situation])
                ? $situation
                : '';
        }
        if (array_key_exists('last_exercise_date', $input)) {
            $input['last_exercise_date'] = Rules::latestDate((string) $input['last_exercise_date']);
        }
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = empty($input['is_active']) ? 0 : 1;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->applyProcessInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->applyProcessInput();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(CrisisScenarioTables::PROCESSES, [self::FK => (int) $this->getID()]);
    }

    /**
     * Processus impactés choisis dans le formulaire (`_processes`, avec `_processes_sent`). Seuls les
     * liens vers des processus que la personne peut lire sont retirés ou ajoutés ; un processus hors
     * de l'entité du scénario est refusé et signalé.
     */
    private function applyProcessInput(): void
    {
        global $DB;

        if (empty($this->input['_processes_sent'])) {
            return;
        }
        $scenarioId = (int) $this->getID();
        $wanted = array_values(array_unique(array_filter(
            array_map('intval', (array) ($this->input['_processes'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $current = [];
        $links = $DB->request(['FROM' => CrisisScenarioTables::PROCESSES, 'WHERE' => [self::FK => $scenarioId]]);
        foreach ($links as $row) {
            $current[(int) $row['plugin_grcmanager_businessprocesses_id']] = (int) $row['id'];
        }
        $process = new BusinessProcess();
        foreach ($current as $processId => $linkId) {
            if (!in_array($processId, $wanted, true) && $process->getFromDB($processId) && $process->canViewItem()) {
                $DB->delete(CrisisScenarioTables::PROCESSES, ['id' => $linkId]);
            }
        }
        $refused = false;
        foreach ($wanted as $processId) {
            if (isset($current[$processId])) {
                continue;
            }
            if (!$this->processAllowed($processId)) {
                $refused = true;
                continue;
            }
            $DB->insert(CrisisScenarioTables::PROCESSES, [
                self::FK                                 => $scenarioId,
                'plugin_grcmanager_businessprocesses_id' => $processId,
                'date_creation'                          => date('Y-m-d H:i:s'),
            ]);
        }
        if ($refused) {
            Session::addMessageAfterRedirect(
                htmlescape(__('A process outside the entity of the scenario was not linked.', 'grcmanager')),
                false,
                ERROR
            );
        }
    }

    /** Processus lisible par la personne et visible depuis l'entité du scénario. */
    public function processAllowed(int $processId): bool
    {
        $process = new BusinessProcess();
        if ($processId <= 0 || !$process->getFromDB($processId) || !$process->canViewItem()) {
            return false;
        }
        $scenarioEntity = (int) ($this->fields['entities_id'] ?? 0);
        $processEntity = (int) $process->fields['entities_id'];

        return Rules::processInScope(
            $processEntity,
            !empty($process->fields['is_recursive']),
            $scenarioEntity,
            !empty($this->fields['is_recursive']),
            array_map('intval', array_values(getAncestorsOf('glpi_entities', $scenarioEntity))),
            array_map('intval', array_values(getAncestorsOf('glpi_entities', $processEntity)))
        );
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Processus liés aux scénarios donnés, limités à ceux que la personne peut lire.
     *
     * @param list<int> $scenarioIds
     * @return array<int, list<array{id: int, name: string, url: string, rto: string, critical: bool}>>
     */
    public static function processesFor(array $scenarioIds): array
    {
        global $DB;

        $result = [];
        if ($scenarioIds === [] || !BusinessProcess::canView()) {
            return $result;
        }
        $durations = BusinessProcess::getDurationOptions();
        $process = new BusinessProcess();
        foreach (
            $DB->request([
                'FROM'  => CrisisScenarioTables::PROCESSES,
                'WHERE' => [self::FK => $scenarioIds],
            ]) as $link
        ) {
            $processId = (int) $link['plugin_grcmanager_businessprocesses_id'];
            if (!$process->getFromDB($processId) || !$process->canViewItem()) {
                continue;
            }
            $result[(int) $link[self::FK]][] = [
                'id'       => (int) $process->getID(),
                'name'     => (string) $process->fields['name'],
                'url'      => BusinessProcess::getFormURLWithID((int) $process->getID()),
                'rto'      => $durations[(int) $process->fields['rto_hours']] ?? '',
                'critical' => Rules::isCritical($process->fields),
            ];
        }
        foreach ($result as &$processes) {
            usort($processes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }
        unset($processes);

        return $result;
    }

    /**
     * Processus proposés dans le formulaire : lisibles et visibles depuis l'entité du scénario.
     *
     * @return array<int, string>
     */
    public function processChoices(): array
    {
        global $DB;

        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => BusinessProcess::getTable(),
                'WHERE'  => EntityPerimeter::criteria(BusinessProcess::getTable()),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            if ($this->processAllowed((int) $row['id'])) {
                $choices[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $choices;
    }

    /**
     * Date du dernier exercice terminé (incident fictif) par scénario d'exercice, parmi les exercices
     * des entités de la session. Vide sans le droit de lire les incidents : les exercices ne sont
     * alors ni nommés ni datés.
     *
     * @return array<string, string>
     */
    public static function drillDates(): array
    {
        global $DB;

        $dates = [];
        if (!PluginGrcmanagerSecurityIncident::canView()) {
            return $dates;
        }
        $exercises = CrisisExercise::getTable();
        $incidents = PluginGrcmanagerSecurityIncident::getTable();
        foreach (
            $DB->request([
                'SELECT'     => ["$exercises.scenario", "$exercises.ended_at"],
                'FROM'       => $exercises,
                'INNER JOIN' => [
                    $incidents => ['ON' => [
                        $exercises => 'plugin_grcmanager_securityincidents_id',
                        $incidents => 'id',
                    ]],
                ],
                'WHERE'      => array_merge([
                    "$incidents.is_exercise" => 1,
                    ['NOT' => ["$exercises.ended_at" => null]],
                // Les exercices ne sont pas récursifs (pas de colonne is_recursive).
                ], EntityPerimeter::criteria($exercises, false)),
                'ORDER'      => "$exercises.ended_at DESC",
            ]) as $row
        ) {
            $dates[(string) $row['scenario']] ??= substr((string) $row['ended_at'], 0, 10);
        }

        return $dates;
    }

    /**
     * Procédures de remédiation proposées comme guide de réponse (bibliothèque globale).
     *
     * @return array<int, string>
     */
    public static function playbookChoices(): array
    {
        $choices = [0 => Dropdown::EMPTY_VALUE];
        if (!Playbook::canView()) {
            return $choices;
        }
        foreach ((new Playbook())->find([], ['name ASC']) as $row) {
            $choices[(int) $row['id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * Scénarios des entités de la session, présentés (manques, exercice, guide, processus).
     *
     * @return list<array<string, mixed>>
     */
    public static function register(): array
    {
        global $DB;

        $rows = [];
        if (!self::canView() || !$DB->tableExists(self::getTable())) {
            return $rows;
        }
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => EntityPerimeter::criteria(self::getTable()),
                'ORDER' => 'name',
            ]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }
        $processes = self::processesFor(array_keys($rows));
        $drills = self::drillDates();
        $canPlaybooks = Playbook::canView();
        $canTemplates = CrisisMessageTemplate::canView();
        $templates = $canTemplates ? self::templatesBySituation() : [];
        $exerciseLabels = CrisisExercise::getScenarios();
        $situations = CrisisMessageTemplate::getSituations();
        $gapLabels = self::gapLabels();
        $today = date('Y-m-d');
        $playbook = new Playbook();

        $presented = [];
        foreach ($rows as $id => $row) {
            $exerciseKey = (string) $row['exercise_scenario'];
            $drillDate = $drills[$exerciseKey] ?? null;
            $last = Rules::latestDate($row['last_exercise_date'] ?? null, $drillDate);
            $status = Rules::exerciseStatus($last, $today);
            $playbookId = (int) $row['plugin_grcmanager_playbooks_id'];
            $hasPlaybook = $playbookId > 0 && $playbook->getFromDB($playbookId);
            $own = $processes[$id] ?? [];
            $gaps = !empty($row['is_active']) ? Rules::gaps($row, count($own), $hasPlaybook, $status) : [];
            $presented[] = [
                'id'              => $id,
                'url'             => self::getFormURLWithID($id),
                'name'            => (string) $row['name'],
                'default_key'     => (string) $row['default_key'],
                'is_active'       => !empty($row['is_active']),
                'entity'          => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'processes'       => $own,
                'playbook'        => $hasPlaybook && $canPlaybooks ? [
                    'name' => (string) $playbook->fields['name'],
                    'url'  => Playbook::getFormURLWithID($playbookId),
                ] : null,
                'has_playbook'    => $hasPlaybook,
                'exercise'        => $exerciseLabels[$exerciseKey]['label'] ?? '',
                'exercise_key'    => $exerciseKey,
                'last_exercise'   => $last ?? '',
                'drill_date'      => $drillDate ?? '',
                'exercise_status' => $status,
                'situation'       => $situations[(string) $row['communication_situation']] ?? '',
                'templates'       => $templates[(string) $row['communication_situation']] ?? [],
                'gaps'            => $gaps,
                'gap_labels'      => array_map(static fn (string $gap): string => $gapLabels[$gap] ?? $gap, $gaps),
                // Registre : l'exercice a sa propre colonne, seuls les autres manques sont listés.
                'todo_labels'     => array_values(array_map(
                    static fn (string $gap): string => $gapLabels[$gap] ?? $gap,
                    array_diff($gaps, [Rules::GAP_NEVER_EXERCISED, Rules::GAP_EXERCISE_OVERDUE])
                )),
            ];
        }

        return $presented;
    }

    /**
     * Modèles de messages de crise actifs par situation (l'appelant a vérifié le droit de les lire).
     *
     * @return array<string, list<array{name: string, url: string}>>
     */
    private static function templatesBySituation(): array
    {
        $bySituation = [];
        foreach ((new CrisisMessageTemplate())->find(['is_active' => 1], ['name ASC']) as $row) {
            $bySituation[(string) $row['situation']][] = [
                'name' => (string) $row['name'],
                'url'  => CrisisMessageTemplate::getFormURLWithID((int) $row['id']),
            ];
        }

        return $bySituation;
    }

    /**
     * Processus lisibles et nombre de scénarios actifs liés à chacun.
     *
     * @return array{processes: list<array<string, mixed>>, counts: array<int, int>}
     */
    private static function processCoverage(): array
    {
        global $DB;

        $processes = [];
        $counts = [];
        if (!BusinessProcess::canView()) {
            return ['processes' => $processes, 'counts' => $counts];
        }
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'rto_hours', 'entities_id'],
                'FROM'   => BusinessProcess::getTable(),
                'WHERE'  => EntityPerimeter::criteria(BusinessProcess::getTable()),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $processes[] = $row;
        }
        $links = CrisisScenarioTables::PROCESSES;
        $scenarios = self::getTable();
        foreach (
            $DB->request([
                'SELECT'     => ["$links.plugin_grcmanager_businessprocesses_id"],
                'FROM'       => $links,
                'INNER JOIN' => [$scenarios => ['ON' => [$links => self::FK, $scenarios => 'id']]],
                'WHERE'      => array_merge(["$scenarios.is_active" => 1], EntityPerimeter::criteria($scenarios)),
            ]) as $row
        ) {
            $processId = (int) $row['plugin_grcmanager_businessprocesses_id'];
            $counts[$processId] = ($counts[$processId] ?? 0) + 1;
        }

        return ['processes' => $processes, 'counts' => $counts];
    }

    /**
     * Couverture : synthèse, processus critiques sans scénario actif.
     *
     * @param list<array<string, mixed>> $scenarios scénarios présentés (register())
     * @return array{summary: array<string, mixed>, uncovered: list<array{name: string, url: string, rto: string}>}
     */
    public static function coverage(array $scenarios): array
    {
        $coverage = self::processCoverage();
        $summary = Rules::summary($coverage['processes'], $coverage['counts'], $scenarios);
        $durations = BusinessProcess::getDurationOptions();
        $uncovered = [];
        foreach (Rules::uncoveredCriticalProcesses($coverage['processes'], $coverage['counts']) as $row) {
            $uncovered[] = [
                'name' => (string) $row['name'],
                'url'  => BusinessProcess::getFormURLWithID((int) $row['id']),
                'rto'  => $durations[(int) $row['rto_hours']] ?? '',
            ];
        }

        return ['summary' => $summary, 'uncovered' => $uncovered];
    }

    // ------------------------------------------------------------------
    // Catalogue par défaut
    // ------------------------------------------------------------------

    /**
     * Ajoute dans l'entité les scénarios par défaut qui n'y sont pas encore visibles, reliés à la
     * procédure fournie de même catégorie et de même nom si elle existe. Ne modifie rien d'existant.
     *
     * @return int nombre de scénarios ajoutés
     */
    public static function addDefaults(int $entityId): int
    {
        global $DB;

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
        $added = 0;
        foreach (Defaults::texts() as $key => $text) {
            if (isset($existing[$key])) {
                continue;
            }
            $id = (new self())->add([
                'entities_id'             => $entityId,
                'is_recursive'            => 1,
                'default_key'             => $key,
                'plugin_grcmanager_playbooks_id' => self::defaultPlaybook($key),
                'exercise_scenario'       => Defaults::EXERCISES[$key] ?? '',
                'communication_situation' => Defaults::SITUATIONS[$key] ?? '',
                'is_active'               => 1,
            ] + $text);
            if ($id) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Procédure fournie servant de guide de réponse : même catégorie, nom anglais ou traduit dans la
     * langue de la personne (les procédures sont enregistrées dans la langue de l'installation).
     */
    private static function defaultPlaybook(string $key): int
    {
        if (!isset(Defaults::PLAYBOOKS[$key]) || !Playbook::canView()) {
            return 0;
        }
        [$category, $name] = Defaults::PLAYBOOKS[$key];
        $rows = (new Playbook())->find([
            'category' => $category,
            'name'     => array_values(array_unique([$name, __($name, 'grcmanager')])),
        ], ['is_active DESC', 'id ASC'], 1);
        $row = reset($rows);

        return $row === false ? 0 : (int) $row['id'];
    }

    // ------------------------------------------------------------------
    // Écrans
    // ------------------------------------------------------------------

    /** Page du registre : couverture, scénarios et leurs manques. */
    public static function showRegister(): void
    {
        $scenarios = self::register();
        TemplateRenderer::getInstance()->display('@grcmanager/crisis_scenario_register.html.twig', [
            'scenarios'    => $scenarios,
            'coverage'     => self::coverage($scenarios),
            'can_create'   => self::canCreate(),
            'add_url'      => self::getFormURL(),
            'register_url' => self::getSearchURL(),
            'export_url'   => self::getExportURL(),
            'exercise_url' => PluginGrcmanagerSecurityIncident::canView() ? CrisisExercise::getSearchURL() : '',
            'control'      => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
            'critical_rto' => Rules::CRITICAL_RTO_HOURS,
            'defaults'     => Defaults::texts(),
        ]);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            $this->fields['is_active'] = 1;
        }
        $linked = $this->isNewItem() ? [] : (self::processesFor([(int) $ID])[(int) $ID] ?? []);
        $presented = null;
        if (!$this->isNewItem()) {
            foreach (self::register() as $row) {
                if ($row['id'] === (int) $ID) {
                    $presented = $row;
                }
            }
        }
        TemplateRenderer::getInstance()->display('@grcmanager/crisis_scenario_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'roles'         => self::roleLabels(),
            'processes'     => $this->processChoices(),
            'linked'        => array_column($linked, 'id'),
            'can_processes' => BusinessProcess::canView(),
            'playbooks'     => self::playbookChoices(),
            'can_playbooks' => Playbook::canView(),
            'exercises'     => self::exerciseChoices(),
            'situations'    => self::situationChoices(),
            'scenario'      => $presented,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Onglet « Scénarios de crise » d'un processus métier
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            count(self::scenariosForProcess($item)),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (!$item instanceof BusinessProcess || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_crisis_scenarios.html.twig', [
            'scenarios'    => self::scenariosForProcess($item),
            'register_url' => self::getSearchURL(),
            'critical'     => Rules::isCritical($item->fields),
        ]);

        return true;
    }

    /**
     * Scénarios lisibles qui touchent le processus.
     *
     * @return list<array{name: string, url: string, is_active: bool, degraded_mode: string}>
     */
    public static function scenariosForProcess(CommonDBTM $process): array
    {
        global $DB;

        $scenarios = [];
        $scenario = new self();
        foreach (
            $DB->request([
                'FROM'  => CrisisScenarioTables::PROCESSES,
                'WHERE' => ['plugin_grcmanager_businessprocesses_id' => (int) $process->getID()],
            ]) as $link
        ) {
            if (!$scenario->getFromDB((int) $link[self::FK]) || !$scenario->canViewItem()) {
                continue;
            }
            $scenarios[] = [
                'name'          => (string) $scenario->fields['name'],
                'url'           => self::getFormURLWithID((int) $scenario->getID()),
                'is_active'     => !empty($scenario->fields['is_active']),
                'degraded_mode' => (string) $scenario->fields['degraded_mode'],
            ];
        }
        usort($scenarios, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $scenarios;
    }

    /** Un processus purgé quitte les scénarios. */
    public static function forgetProcess(int $processId): void
    {
        global $DB;

        if ($DB->tableExists(CrisisScenarioTables::PROCESSES)) {
            $DB->delete(CrisisScenarioTables::PROCESSES, ['plugin_grcmanager_businessprocesses_id' => $processId]);
        }
    }

    /** Une procédure purgée n'est plus le guide de réponse d'aucun scénario. */
    public static function forgetPlaybook(int $playbookId): void
    {
        global $DB;

        if ($DB->tableExists(self::getTable())) {
            $DB->update(self::getTable(), ['plugin_grcmanager_playbooks_id' => 0], [
                'plugin_grcmanager_playbooks_id' => $playbookId,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Preuve de la mesure A.5.29
    // ------------------------------------------------------------------

    /**
     * Preuve affichée sur la fiche de la mesure A.5.29 : couverture des scénarios. Null pour les
     * autres mesures ou sans le droit de la continuité.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if ($code !== self::CONTROL_CODE || !self::canView()) {
            return null;
        }
        $coverage = self::coverage(self::register());

        return $coverage['summary'] + [
            'url'        => self::getSearchURL(),
            'export_url' => self::getExportURL(),
        ];
    }

    /** Registre en PDF (HTML pour PdfRenderer), avec les droits de la personne. */
    public static function reportHtml(): string
    {
        $scenarios = self::register();

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/crisis_scenarios_report.html.twig', [
            'report_title'       => __('Information security crisis scenarios', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'control'            => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
            'coverage'           => self::coverage($scenarios),
            'scenarios'          => self::withDetails($scenarios),
            'roles'              => self::roleLabels(),
        ]);
    }

    /**
     * Ajoute à chaque scénario présenté sa description, son mode dégradé et ses rôles (PDF).
     *
     * @param list<array<string, mixed>> $scenarios
     * @return list<array<string, mixed>>
     */
    private static function withDetails(array $scenarios): array
    {
        $scenario = new self();
        foreach ($scenarios as &$row) {
            if ($scenario->getFromDB((int) $row['id'])) {
                foreach (['description', 'degraded_mode', 'role_management', 'role_ciso', 'role_it'] as $field) {
                    $row[$field] = (string) ($scenario->fields[$field] ?? '');
                }
            }
        }
        unset($row);

        return $scenarios;
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
            'id' => 4, 'table' => $table, 'field' => 'degraded_mode',
            'name' => __('Secure degraded mode', 'grcmanager'), 'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'last_exercise_date',
            'name' => __('Last exercise outside the plugin', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }
}
