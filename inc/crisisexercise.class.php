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
use Glpi\RichText\RichText;
use GlpiPlugin\Grcmanager\Compatibility\Base\CrisisExerciseBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Exercise\CrisisExercise;

/**
 * Issue #168 : exercice de crise (test des plans de continuité, A.5.29 / A.5.30). Le lancement
 * crée un incident de sécurité fictif marqué « EXERCICE » et une tâche par étape du scénario ;
 * la salle de crise chronomètre les étapes, et la fin de l'exercice produit le rapport PDF,
 * archivé sur l'incident et sur les mesures A.5.29 et A.5.30. Les incidents d'exercice sont
 * exclus des indicateurs (tableau de bord, score de maturité).
 */
class PluginGrcmanagerCrisisExercise extends CrisisExerciseBase
{
    public const STEPS_TABLE = 'glpi_plugin_grcmanager_crisisexercisesteps';

    /** Mesures de l'Annexe A auxquelles le rapport sert de preuve. */
    private const CONTROL_CODES = ['A.5.29', 'A.5.30'];

    public static function getTypeName($nb = 0)
    {
        // « Exercice de crise » existe déjà au singulier seul (type d'événement du calendrier).
        return $nb > 1 ? __('Exercices de crise', 'grcmanager') : __('Exercice de crise', 'grcmanager');
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
                'label'       => __('Attaque par rançongiciel', 'grcmanager'),
                'description' => __(
                    'Des postes et un serveur de fichiers sont chiffrés, une demande de rançon s\'affiche. '
                        . 'L\'équipe doit isoler, évaluer, restaurer depuis les sauvegardes et communiquer.',
                    'grcmanager'
                ),
            ],
            'data_breach' => [
                'label'       => __('Fuite de données personnelles', 'grcmanager'),
                'description' => __(
                    'Un fichier de clients est publié sur un forum. L\'équipe doit contenir la fuite, '
                        . 'identifier les données touchées et décider de la notification à l\'autorité.',
                    'grcmanager'
                ),
            ],
            'outage'      => [
                'label'       => __('Panne majeure du système d\'information', 'grcmanager'),
                'description' => __(
                    'La salle serveur (ou l\'hébergeur principal) est indisponible. L\'équipe doit '
                        . 'activer le plan de continuité et basculer sur le site de secours.',
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
            'confirm_alert'            => __('Confirmer l\'alerte et qualifier l\'incident', 'grcmanager'),
            'isolate_network'          => __('Isoler du réseau les machines touchées', 'grcmanager'),
            'crisis_cell'              => __('Réunir la cellule de crise', 'grcmanager'),
            'inform_management'        => __('Informer la direction', 'grcmanager'),
            'assess_scope'             => __('Évaluer l\'étendue : systèmes et données touchés', 'grcmanager'),
            'restore_test_backup'      => __('Restaurer une sauvegarde de test', 'grcmanager'),
            'communicate'              => __('Préparer la communication interne et externe', 'grcmanager'),
            'back_to_normal'           => __('Valider le retour à la normale', 'grcmanager'),
            'contain_leak'             => __('Contenir la fuite (retrait, comptes, accès)', 'grcmanager'),
            'identify_data'            => __('Identifier les données et les personnes concernées', 'grcmanager'),
            'inform_dpo'               => __('Informer le délégué à la protection des données', 'grcmanager'),
            'regulator_decision'       => __('Décider de la notification à l\'autorité (RGPD, NIS2)', 'grcmanager'),
            'activate_continuity_plan' => __('Activer le plan de continuité', 'grcmanager'),
            'inform_users'             => __('Prévenir les utilisateurs', 'grcmanager'),
            'switch_to_backup'         => __('Basculer sur le site ou le service de secours', 'grcmanager'),
        ];
    }

    /**
     * Lance l'exercice : incident fictif, une tâche par étape, chronomètre démarré.
     *
     * @return int identifiant de l'exercice, 0 en cas d'échec
     */
    public static function launch(string $scenario): int
    {
        global $DB;

        $scenarios = self::getScenarios();
        if (!isset($scenarios[$scenario], CrisisExercise::SCENARIOS[$scenario])) {
            return 0;
        }
        $now = Session::getCurrentTime();
        $definition = CrisisExercise::SCENARIOS[$scenario];
        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name'        => sprintf(__('[EXERCICE] %s', 'grcmanager'), $scenarios[$scenario]['label']),
            'content'     => self::richText([
                __('Ceci est un exercice de crise : aucun incident réel n\'est en cours.', 'grcmanager'),
                $scenarios[$scenario]['description'],
            ]),
            'entities_id' => Session::getActiveEntity(),
            'date'        => $now,
            'category'    => $definition['category'],
            'severity'    => $definition['severity'],
            'is_exercise' => 1,
        ]);
        if ($incidentId <= 0) {
            return 0;
        }
        $exercise = new self();
        $exerciseId = (int) $exercise->add([
            'entities_id'                            => Session::getActiveEntity(),
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'scenario'                               => $scenario,
            'users_id'                               => Session::getLoginUserID(),
            'started_at'                             => $now,
        ]);
        if ($exerciseId <= 0) {
            return 0;
        }
        $labels = self::getStepLabels();
        foreach (CrisisExercise::orderedSteps($scenario) as $rank => [$step, $target]) {
            $taskId = (int) (new PluginGrcmanagerSecurityIncidentTask())->add([
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'content'                                => self::richText([
                    $labels[$step] ?? $step,
                    sprintf(__('Objectif : %d min après le début de l\'exercice.', 'grcmanager'), $target),
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
            $minutes = CrisisExercise::minutesSince($started, $row['done_at']);
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
                'status'  => CrisisExercise::stepStatus($minutes, (int) $row['target_minutes'], $ended),
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
                'duration'     => CrisisExercise::minutesSince($started, $endTime),
                'organizer'    => getUserName((int) $this->fields['users_id']),
                'documents_id' => (int) $this->fields['documents_id'],
                'document_url' => (int) $this->fields['documents_id'] > 0
                    ? Document::getFormURLWithID((int) $this->fields['documents_id'])
                    : '',
            ],
            'incident'     => [
                'id'      => (int) $this->fields['plugin_grcmanager_securityincidents_id'],
                'name'    => $incident?->fields['name'] ?? '',
                'url'     => $incident?->getLinkURL() ?? '',
                'lessons' => $incident === null ? '' : trim(RichText::getTextFromHtml(
                    (string) ($incident->fields['lessons_learned'] ?? ''),
                    true,
                    false,
                    false,
                    true,
                    true
                )),
            ],
            'steps'        => $steps,
            'summary'      => CrisisExercise::summary($steps),
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
        $data = $this->collect();
        $fileName = sprintf('rapport-exercice-crise-%d-%s.pdf', $this->getID(), date('Ymd-His'));
        $scenario = $data['exercise']['scenario'];
        $html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/crisis_exercise_report.html.twig', $data + [
            'report_title'       => sprintf(__('Rapport d\'exercice de crise : %s', 'grcmanager'), $scenario),
            'generated_at_label' => sprintf(__('Généré le %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
        ]);
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, PdfRenderer::renderHtmlToPdf($html)) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('Rapport d\'exercice de crise - %s', 'grcmanager'), $scenario),
            'entities_id'   => (int) $this->fields['entities_id'],
            'is_recursive'  => 1,
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
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => PluginGrcmanagerControl::getTable(),
                'WHERE'  => ['code' => self::CONTROL_CODES],
            ]) as $control
        ) {
            $targets[] = [PluginGrcmanagerControl::class, (int) $control['id']];
        }
        foreach ($targets as [$itemtype, $itemsId]) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
            ]);
        }
        $this->update(['id' => $this->getID(), 'documents_id' => $documentsId]);

        return $documentsId;
    }

    /**
     * Exercices passés et en cours que l'utilisateur peut voir, les plus récents d'abord.
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
            if ($incident === null || !$incident->canViewItem()) {
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
}
