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
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Glpi\RichText\RichText;
use GlpiPlugin\Grcmanager\Compatibility\Base\CrisisExerciseBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Exercise\CrisisExercise as ExerciseRules;
use GlpiPlugin\Grcmanager\Services\Exercise\RecoveryTracker;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use Html;
use ITILFollowup;
use Planning;
use PluginGrcmanagerControl;
use PluginGrcmanagerRestrictedDocument;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncidentTask;
use Session;

/**
 * Issue #168 : exercice de crise (test des plans de continuité, A.5.29 / A.5.30). Le lancement
 * crée un incident de sécurité fictif marqué « EXERCICE » et une tâche par étape du scénario ;
 * la salle de crise chronomètre les étapes, et la fin de l'exercice produit le rapport PDF,
 * archivé sur l'incident et sur les mesures A.5.29 et A.5.30. Les incidents d'exercice sont
 * exclus des indicateurs (tableau de bord, score de maturité).
 *
 * Issue #185 : la même salle de crise sert de cahier de cellule de crise sur un incident réel
 * (onglet « Cellule de crise » de l'incident) : étapes du plan, reprise des éléments touchés et de
 * ce qui en dépend comparée à leur RTO, journal chronologique. Son rapport sert de preuve sur les
 * mesures A.5.26 et A.5.29.
 */
class CrisisExercise extends CrisisExerciseBase
{
    public const RIGHTNAME = PluginGrcmanagerSecurityIncident::RIGHTNAME;

    public const STEPS_TABLE = 'glpi_plugin_grcmanager_crisisexercisesteps';
    public const RECOVERIES_TABLE = 'glpi_plugin_grcmanager_crisisrecoveries';

    /**
     * Mesures de l'Annexe A auxquelles le rapport d'un exercice (incident fictif) sert de preuve.
     * Le rapport d'une crise réelle n'y est jamais lié : les mesures sont globales, et le
     * document serait téléchargeable par tout lecteur de la SoA, sans droit sur l'incident.
     */
    private const DRILL_CONTROL_CODES = ['A.5.29', 'A.5.30'];

    public static function getTypeName($nb = 0)
    {
        // « Exercice de crise » existe déjà au singulier seul (type d'événement du calendrier).
        return $nb > 1 ? __('Crisis exercises', 'grcmanager') : __('Crisis exercise', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-alarm';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/crisisexercise.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/crisisexercise.form.php';
    }

    public static function canView(): bool
    {
        return PluginGrcmanagerSecurityIncident::canView();
    }

    public static function canCreate(): bool
    {
        return PluginGrcmanagerSecurityIncident::canCreate();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function getScenarios(): array
    {
        return [
            'ransomware'  => [
                'label'       => __('Ransomware attack', 'grcmanager'),
                'description' => __(
                    'Workstations and a file server are encrypted and a ransom note is displayed. The team must '
                        . 'isolate, assess, restore from backups and communicate.',
                    'grcmanager'
                ),
            ],
            'data_breach' => [
                'label'       => __('Personal data leak', 'grcmanager'),
                'description' => __(
                    'A customer file is published on a forum. The team must contain the leak, identify the affected '
                        . 'data and decide whether to notify the authority.',
                    'grcmanager'
                ),
            ],
            'outage'      => [
                'label'       => __('Major information system outage', 'grcmanager'),
                'description' => __(
                    'The server room (or the main hosting provider) is unavailable. The team must activate the '
                        . 'continuity plan and switch to the backup site.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStepLabels(): array
    {
        return [
            'confirm_alert'            => __('Confirm the alert and qualify the incident', 'grcmanager'),
            'isolate_network'          => __('Isolate the affected machines from the network', 'grcmanager'),
            'crisis_cell'              => __('Convene the crisis team', 'grcmanager'),
            'inform_management'        => __('Inform management', 'grcmanager'),
            'assess_scope'             => __('Assess the scope: affected systems and data', 'grcmanager'),
            'restore_test_backup'      => __('Restore a test backup', 'grcmanager'),
            'communicate'              => __('Prepare internal and external communication', 'grcmanager'),
            'back_to_normal'           => __('Confirm the return to normal', 'grcmanager'),
            'contain_leak'             => __('Contain the leak (takedown, accounts, access)', 'grcmanager'),
            'identify_data'            => __('Identify the data and the people concerned', 'grcmanager'),
            'inform_dpo'               => __('Inform the data protection officer', 'grcmanager'),
            'regulator_decision'       => __('Decide on notifying the authority (GDPR, NIS2)', 'grcmanager'),
            'activate_continuity_plan' => __('Activate the continuity plan', 'grcmanager'),
            'inform_users'             => __('Warn users', 'grcmanager'),
            'switch_to_backup'         => __('Switch to the backup site or service', 'grcmanager'),
        ];
    }

    /**
     * Lance l'exercice : incident fictif, une tâche par étape, chronomètre démarré.
     *
     * @return int identifiant de l'exercice, 0 en cas d'échec
     */
    public static function launch(string $scenario): int
    {
        $scenarios = self::getScenarios();
        if (!isset($scenarios[$scenario], ExerciseRules::SCENARIOS[$scenario])) {
            return 0;
        }
        $now = Session::getCurrentTime();
        $definition = ExerciseRules::SCENARIOS[$scenario];
        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name'        => sprintf(__('[EXERCISE] %s', 'grcmanager'), $scenarios[$scenario]['label']),
            'content'     => self::richText([
                __('This is a crisis exercise: no real incident is in progress.', 'grcmanager'),
                $scenarios[$scenario]['description'],
            ]),
            'entities_id' => Session::getActiveEntity(),
            'date'        => $now,
            'category'    => $definition['category'],
            'severity'    => $definition['severity'],
            'is_exercise' => 1,
        ]);
        $incident = new PluginGrcmanagerSecurityIncident();
        if ($incidentId <= 0 || !$incident->getFromDB($incidentId)) {
            return 0;
        }

        return self::open($incident, $scenario, $now);
    }

    /**
     * Issue #185 : active la cellule de crise sur un incident réel, avec les étapes du plan choisi.
     *
     * @return int identifiant de la cellule, 0 si refusé (scénario inconnu, incident d'exercice,
     *     cellule déjà active)
     */
    public static function activate(PluginGrcmanagerSecurityIncident $incident, string $scenario): int
    {
        if (
            !isset(ExerciseRules::SCENARIOS[$scenario])
            || !empty($incident->fields['is_exercise'])
            || self::forIncident($incident->getID()) !== null
        ) {
            return 0;
        }

        return self::open($incident, $scenario, Session::getCurrentTime());
    }

    /**
     * Cellule de crise (exercice ou crise réelle) déjà ouverte sur un incident.
     */
    public static function forIncident(int $incidentId): ?self
    {
        $cell = new self();

        return $cell->getFromDBByCrit(['plugin_grcmanager_securityincidents_id' => $incidentId]) ? $cell : null;
    }

    /**
     * Ouvre la salle de crise : chronomètre démarré et une tâche de l'incident par étape.
     */
    private static function open(PluginGrcmanagerSecurityIncident $incident, string $scenario, string $now): int
    {
        global $DB;

        $drill = !empty($incident->fields['is_exercise']);
        $exerciseId = (int) (new self())->add([
            'entities_id'                            => (int) $incident->fields['entities_id'],
            'plugin_grcmanager_securityincidents_id' => $incident->getID(),
            'scenario'                               => $scenario,
            'users_id'                               => Session::getLoginUserID(),
            'started_at'                             => $now,
        ]);
        if ($exerciseId <= 0) {
            return 0;
        }
        $labels = self::getStepLabels();
        $objective = $drill
            ? __('Target: %d min after the start of the exercise.', 'grcmanager')
            : __('Target: %d min after the crisis team was activated.', 'grcmanager');
        foreach (ExerciseRules::orderedSteps($scenario) as $rank => [$step, $target]) {
            $taskId = (int) (new PluginGrcmanagerSecurityIncidentTask())->add([
                'plugin_grcmanager_securityincidents_id' => $incident->getID(),
                'content'                                => self::richText([
                    $labels[$step] ?? $step,
                    sprintf($objective, $target),
                ]),
                'state'                                  => Planning::TODO,
                '_disablenotif'                          => true,
            ]);
            $DB->insert(self::STEPS_TABLE, [
                'plugin_grcmanager_crisisexercises_id'       => $exerciseId,
                'step'                                       => $step,
                'rank'                                       => $rank,
                'target_minutes'                             => $target,
                'plugin_grcmanager_securityincidenttasks_id' => $taskId,
            ]);
        }

        return $exerciseId;
    }

    /**
     * Contenu riche (un paragraphe par texte, échappé par Twig) de l'incident et des tâches.
     *
     * @param list<string> $paragraphs
     */
    private static function richText(array $paragraphs): string
    {
        return TemplateRenderer::getInstance()->render(
            '@grcmanager/exercise_content.html.twig',
            ['paragraphs' => $paragraphs]
        );
    }

    public function getIncident(): ?PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) $this->fields['plugin_grcmanager_securityincidents_id']) ? $incident : null;
    }

    /**
     * Exercice (incident fictif) ou crise réelle.
     */
    public function isDrill(): bool
    {
        return !empty($this->getIncident()?->fields['is_exercise']);
    }

    /**
     * Issue #185 : éléments à rétablir, les éléments touchés par l'incident puis ce qui en dépend
     * (analyse d'impact de GLPI), clé => profondeur (0 : touché directement).
     *
     * @return array<string, int>
     */
    private function recoveryScope(): array
    {
        $scope = [];
        $incidentId = (int) $this->fields['plugin_grcmanager_securityincidents_id'];
        foreach (
            (new PluginGrcmanagerSecurityIncident_Item())->find([
                'plugin_grcmanager_securityincidents_id' => $incidentId,
            ]) as $row
        ) {
            $scope[ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id'])] = 0;
        }
        if ($scope === []) {
            return [];
        }
        $edges = ImpactAnalysis::loadEdges();
        foreach (array_keys($scope) as $start) {
            foreach (ImpactGraph::downstream($edges, $start) as $key => $depth) {
                $scope[$key] = min($scope[$key] ?? PHP_INT_MAX, $depth);
            }
        }

        return $scope;
    }

    /**
     * Issue #185 : un élément déclaré rétabli ; l'heure sert à mesurer le temps de reprise réel.
     */
    public function markRestored(string $key): bool
    {
        global $DB;

        if (!empty($this->fields['ended_at']) || !isset($this->recoveryScope()[$key])) {
            return false;
        }
        [$itemtype, $itemsId] = ImpactGraph::split($key);
        $criteria = [
            'plugin_grcmanager_crisisexercises_id' => $this->getID(),
            'itemtype'                             => $itemtype,
            'items_id'                             => $itemsId,
        ];
        if (countElementsInTable(self::RECOVERIES_TABLE, $criteria) > 0) {
            return false;
        }
        $DB->insert(self::RECOVERIES_TABLE, $criteria + [
            'restored_at' => Session::getCurrentTime(),
            'users_id'    => Session::getLoginUserID(),
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recovery(): array
    {
        global $DB;

        $restored = [];
        foreach (
            $DB->request([
                'FROM'  => self::RECOVERIES_TABLE,
                'WHERE' => ['plugin_grcmanager_crisisexercises_id' => $this->getID()],
            ]) as $row
        ) {
            $restored[ImpactGraph::key((string) $row['itemtype'], (int) $row['items_id'])] = $row;
        }
        $now = empty($this->fields['ended_at']) ? Session::getCurrentTime() : (string) $this->fields['ended_at'];
        $rows = [];
        foreach ($this->recoveryScope() as $key => $depth) {
            $node = ImpactAnalysis::describe($key);
            if (!$node['visible']) {
                continue;
            }
            $done = $restored[$key] ?? null;
            $rows[] = $node + [
                'key'         => $key,
                'depth'       => $depth,
                'restored_at' => (string) ($done['restored_at'] ?? ''),
                'by'          => $done === null ? '' : getUserName((int) $done['users_id']),
            ] + RecoveryTracker::status(
                (string) $this->fields['started_at'],
                $node['rto'],
                $done['restored_at'] ?? null,
                $now
            );
        }
        usort($rows, static fn (array $a, array $b): int => [$a['depth'], $a['label']] <=> [$b['depth'], $b['label']]);

        return $rows;
    }

    /**
     * Issue #185 : cahier de crise, les suivis de l'incident depuis l'ouverture de la salle (les
     * suivis privés seulement pour qui peut les voir, jamais dans le rapport archivé).
     *
     * @return list<array{date: string, author: string, text: string, private: bool}>
     */
    private function journal(bool $withPrivate): array
    {
        $criteria = [
            'itemtype' => PluginGrcmanagerSecurityIncident::class,
            'items_id' => (int) $this->fields['plugin_grcmanager_securityincidents_id'],
            'date'     => ['>=', $this->fields['started_at']],
        ];
        if (!$withPrivate) {
            $criteria['is_private'] = 0;
        }
        $entries = [];
        foreach (
            (new ITILFollowup())->find($criteria, ['date ASC', 'id ASC']) as $row
        ) {
            $entries[] = [
                'date'    => (string) $row['date'],
                'author'  => getUserName((int) $row['users_id']),
                'text'    => self::plain((string) $row['content']),
                'private' => (bool) $row['is_private'],
            ];
        }

        return $entries;
    }

    /**
     * Issue #185 : une ligne du cahier de crise, enregistrée comme suivi de l'incident.
     */
    public function addJournalEntry(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || !empty($this->fields['ended_at'])) {
            return false;
        }

        return (bool) (new ITILFollowup())->add([
            'itemtype' => PluginGrcmanagerSecurityIncident::class,
            'items_id' => (int) $this->fields['plugin_grcmanager_securityincidents_id'],
            'content'  => self::richText([$text]),
        ]);
    }

    private static function plain(string $html): string
    {
        return trim(RichText::getTextFromHtml($html, true, false, false, true, true));
    }

    /**
     * Une étape cochée dans la salle de crise : l'heure est retenue et la tâche passe à « Fait ».
     */
    public function markStepDone(int $stepId): bool
    {
        global $DB;

        if (!empty($this->fields['ended_at'])) {
            return false;
        }
        $step = $DB->request([
            'FROM'  => self::STEPS_TABLE,
            'WHERE' => ['id' => $stepId, 'plugin_grcmanager_crisisexercises_id' => $this->getID(), 'done_at' => null],
        ])->current();
        if ($step === null) {
            return false;
        }
        $DB->update(
            self::STEPS_TABLE,
            ['done_at' => Session::getCurrentTime(), 'users_id_done' => Session::getLoginUserID()],
            ['id' => $stepId]
        );
        $task = new PluginGrcmanagerSecurityIncidentTask();
        if ($task->getFromDB((int) $step['plugin_grcmanager_securityincidenttasks_id'])) {
            $task->update(['id' => $task->getID(), 'state' => Planning::DONE, '_disablenotif' => true]);
        }

        return true;
    }

    /**
     * Une tâche passée à « Fait » depuis l'incident compte aussi : l'heure de sa modification
     * devient celle de l'étape.
     */
    private function syncStepsFromTasks(): void
    {
        global $DB;

        if (!empty($this->fields['ended_at'])) {
            return;
        }
        $tasks = PluginGrcmanagerSecurityIncidentTask::getTable();
        $rows = $DB->request([
            'SELECT'     => [self::STEPS_TABLE . '.id', "$tasks.date_mod", "$tasks.users_id_editor"],
            'FROM'       => self::STEPS_TABLE,
            'INNER JOIN' => [
                $tasks => [
                    'FKEY' => [self::STEPS_TABLE => 'plugin_grcmanager_securityincidenttasks_id', $tasks => 'id'],
                ],
            ],
            'WHERE'      => [
                self::STEPS_TABLE . '.plugin_grcmanager_crisisexercises_id' => $this->getID(),
                self::STEPS_TABLE . '.done_at'                              => null,
                "$tasks.state"                                              => Planning::DONE,
            ],
        ]);
        foreach ($rows as $row) {
            $DB->update(
                self::STEPS_TABLE,
                ['done_at' => $row['date_mod'], 'users_id_done' => (int) $row['users_id_editor']],
                ['id' => (int) $row['id']]
            );
        }
    }

    /**
     * Étapes, résultats et synthèse de l'exercice.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        global $DB;

        $this->syncStepsFromTasks();
        $ended = !empty($this->fields['ended_at']);
        $started = (string) $this->fields['started_at'];
        $labels = self::getStepLabels();
        $steps = [];
        $participants = [];
        foreach (
            $DB->request([
                'FROM'  => self::STEPS_TABLE,
                'WHERE' => ['plugin_grcmanager_crisisexercises_id' => $this->getID()],
                'ORDER' => 'rank',
            ]) as $row
        ) {
            $minutes = ExerciseRules::minutesSince($started, $row['done_at']);
            $doneBy = (int) $row['users_id_done'];
            if ($doneBy > 0) {
                $participants[$doneBy] = getUserName($doneBy);
            }
            $steps[] = [
                'id'      => (int) $row['id'],
                'label'   => $labels[$row['step']] ?? $row['step'],
                'target'  => (int) $row['target_minutes'],
                'done_at' => (string) ($row['done_at'] ?? ''),
                'by'      => $doneBy > 0 ? $participants[$doneBy] : '',
                'minutes' => $minutes,
                'status'  => ExerciseRules::stepStatus($minutes, (int) $row['target_minutes'], $ended),
            ];
        }
        $incident = $this->getIncident();
        $endTime = $ended ? (string) $this->fields['ended_at'] : Session::getCurrentTime();

        return [
            'exercise'     => [
                'id'           => $this->getID(),
                'scenario'     => self::getScenarios()[$this->fields['scenario']]['label'] ?? $this->fields['scenario'],
                'started_at'   => $started,
                'started_ts'   => (int) strtotime($started),
                'ended_at'     => (string) ($this->fields['ended_at'] ?? ''),
                'ended'        => $ended,
                'duration'     => ExerciseRules::minutesSince($started, $endTime),
                'organizer'    => getUserName((int) $this->fields['users_id']),
                'documents_id' => (int) $this->fields['documents_id'],
                'document_url' => (int) $this->fields['documents_id'] > 0
                    ? Document::getFormURLWithID((int) $this->fields['documents_id'])
                    : '',
            ],
            'drill'        => $incident === null || !empty($incident->fields['is_exercise']),
            'recovery'     => $this->recovery(),
            'journal'      => $this->journal(Session::haveRight('followup', ITILFollowup::SEEPRIVATE)),
            'incident'     => [
                'id'      => (int) $this->fields['plugin_grcmanager_securityincidents_id'],
                'name'    => $incident?->fields['name'] ?? '',
                'url'     => $incident?->getLinkURL() ?? '',
                'lessons' => self::plain((string) ($incident?->fields['lessons_learned'] ?? '')),
            ],
            'steps'        => $steps,
            'summary'      => ExerciseRules::summary($steps),
            'participants' => array_values($participants),
        ];
    }

    /**
     * Fin de l'exercice : chronomètre arrêté, rapport PDF archivé sur l'incident et sur les
     * mesures A.5.29 / A.5.30.
     */
    public function finish(): int
    {
        global $DB;

        if (empty($this->fields['ended_at'])) {
            $this->syncStepsFromTasks();
            $this->update(['id' => $this->getID(), 'ended_at' => Session::getCurrentTime()]);
            $this->getFromDB($this->getID());
        }
        // Le rapport archivé ne reprend jamais les suivis privés.
        $data = ['journal' => $this->journal(false)] + $this->collect();
        $drill = $data['drill'];
        $fileName = sprintf(
            $drill ? 'rapport-exercice-crise-%d-%s.pdf' : 'rapport-cellule-crise-%d-%s.pdf',
            $this->getID(),
            date('Ymd-His')
        );
        $scenario = $drill ? $data['exercise']['scenario'] : $data['incident']['name'];
        $title = $drill
            ? __('Crisis exercise report: %s', 'grcmanager')
            : __('Crisis team report: %s', 'grcmanager');
        $html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/crisis_exercise_report.html.twig', $data + [
            'report_title'       => sprintf($title, $scenario),
            'generated_at_label' => sprintf(__(
                'Generated on %s',
                'grcmanager'
            ), Html::convDateTime(date('Y-m-d H:i:s'))),
        ]);
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, PdfRenderer::renderHtmlToPdf($html)) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                $drill
                    ? __('Crisis exercise report - %s', 'grcmanager')
                    : __('Crisis team report - %s', 'grcmanager'),
                $scenario
            ),
            'entities_id'   => (int) $this->fields['entities_id'],
            // Lisible dans l'entité de l'incident seulement, comme l'incident.
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        $targets = [[
            PluginGrcmanagerSecurityIncident::class,
            (int) $this->fields['plugin_grcmanager_securityincidents_id'],
        ]];
        if ($drill) {
            foreach (
                $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => PluginGrcmanagerControl::getTable(),
                    'WHERE'  => ['code' => self::DRILL_CONTROL_CODES],
                ]) as $control
            ) {
                $targets[] = [PluginGrcmanagerControl::class, (int) $control['id']];
            }
        }
        foreach ($targets as [$itemtype, $itemsId]) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
            ]);
        }
        $incident = new PluginGrcmanagerSecurityIncident();
        if ($incident->getFromDB((int) $this->fields['plugin_grcmanager_securityincidents_id'])) {
            PluginGrcmanagerRestrictedDocument::register($documentsId, $incident);
        }
        $this->update(['id' => $this->getID(), 'documents_id' => $documentsId]);

        return $documentsId;
    }

    /**
     * Exercices passés et en cours que l'utilisateur peut voir, les plus récents d'abord (les
     * cellules de crise réelles s'ouvrent depuis leur incident).
     *
     * @return list<array<string, mixed>>
     */
    public static function history(): array
    {
        global $DB;

        $list = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => getEntitiesRestrictCriteria(self::getTable(), '', '', false),
                'ORDER' => 'started_at DESC',
                'LIMIT' => 50,
            ]) as $row
        ) {
            $exercise = new self();
            $exercise->getFromResultSet($row);
            $incident = $exercise->getIncident();
            if ($incident === null || empty($incident->fields['is_exercise']) || !$incident->canViewItem()) {
                continue;
            }
            $data = $exercise->collect();
            $list[] = $data['exercise'] + [
                'summary' => $data['summary'],
                'url'     => self::getFormURLWithID($exercise->getID()),
            ];
        }

        return $list;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerSecurityIncident || $item->isNewItem() || !$item->canViewItem()) {
            return '';
        }

        return self::createTabEntry(__('Crisis team', 'grcmanager'), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerSecurityIncident) {
            return false;
        }
        $cell = self::forIncident($item->getID());
        $data = $cell?->collect();
        $scenarios = [];
        foreach (self::getScenarios() as $key => $scenario) {
            $scenarios[$key] = $scenario['label'];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/crisis_tab.html.twig', [
            'incident_id'  => $item->getID(),
            'drill'        => !empty($item->fields['is_exercise']),
            'cell'         => $data,
            'room_url'     => $cell === null ? '' : self::getFormURLWithID($cell->getID()),
            'scenarios'    => $scenarios,
            'can_activate' => $item->canUpdateItem(),
            'action'       => self::getFormURL(),
        ]);

        return true;
    }
}
