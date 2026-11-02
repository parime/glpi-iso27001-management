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
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentPlaybookTaskBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Incident\PlaybookRules;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #370 : tâche de remédiation d'un incident, copiée d'une étape de procédure ou propre à
 * l'incident. Renseignée par les intervenants avec le droit de modifier l'incident ; la réalisation
 * est horodatée et signée (utilisateur), et inscrite dans l'historique de l'incident.
 */
class SecurityIncidentPlaybookTask extends SecurityIncidentPlaybookTaskBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_securityincidentplaybooktasks';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Remediation task', 'Remediation tasks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getStatuses(): array
    {
        return [
            'todo'           => [
                'label' => __('To do', 'grcmanager'), 'class' => 'bg-secondary-lt', 'icon' => 'ti-circle',
            ],
            'in_progress'    => [
                'label' => __('In progress', 'grcmanager'), 'class' => 'bg-blue-lt', 'icon' => 'ti-progress',
            ],
            'done'           => [
                'label' => __('Done', 'grcmanager'), 'class' => 'bg-green-lt', 'icon' => 'ti-circle-check',
            ],
            'not_applicable' => [
                'label' => __('Not applicable', 'grcmanager'), 'class' => 'bg-muted-lt', 'icon' => 'ti-circle-off',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForIncident(int $incidentsId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_securityincidents_id' => $incidentsId],
            ['rank ASC', 'id ASC']
        ));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident) || !PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        if ($item->isNewID($item->getID())) {
            return self::createTabEntry(__('Remediation', 'grcmanager'), 0, $item::class, self::getIcon());
        }
        $progress = PlaybookRules::progress(self::getForIncident((int) $item->getID()));
        $label = __('Remediation', 'grcmanager');
        if ($progress['total'] > 0) {
            $label .= sprintf(' %d/%d', $progress['done'], $progress['total']);
        }

        return self::createTabEntry($label, 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }
        $tasks = self::getForIncident((int) $item->getID());
        $users = [];
        foreach ($tasks as $task) {
            foreach (['users_id', 'users_id_done'] as $field) {
                $id = (int) $task[$field];
                if ($id > 0 && !isset($users[$id])) {
                    $users[$id] = getUserName($id);
                }
            }
        }
        $applied = array_values(array_unique(array_filter(array_map(
            static fn (array $task): int => (int) $task['plugin_grcmanager_playbooks_id'],
            $tasks
        ))));

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/incident_playbook.html.twig', [
            'item'      => $item,
            'groups'    => PlaybookRules::byPhase($tasks),
            'progress'  => PlaybookRules::progress($tasks),
            'phases'    => Playbook::getPhaseLabels(),
            'statuses'  => self::getStatuses(),
            'users'     => $users,
            'playbooks' => Playbook::suggestFor($item),
            'applied'   => $applied,
            'can_edit'  => $item->canUpdateItem(),
            'can_library' => Playbook::canView(),
            'library_url' => Playbook::getSearchURL(),
            'form_url'  => self::getFormURL(),
            'pdf_url'   => self::getFormURL() . '?pdf=' . (int) $item->getID(),
        ]);

        return true;
    }

    /**
     * Copie les étapes d'une procédure en tâches de l'incident, à la suite des tâches existantes.
     *
     * @return int Tâches créées.
     */
    public static function applyPlaybook(int $incidentsId, int $playbooksId): int
    {
        $playbook = new Playbook();
        if (!self::canEditIncident($incidentsId) || !$playbook->getFromDB($playbooksId)) {
            return 0;
        }
        $existing = self::getForIncident($incidentsId);
        // Une procédure déjà appliquée ne l'est pas une seconde fois (tâches en double).
        $applied = array_map('intval', array_column($existing, 'plugin_grcmanager_playbooks_id'));
        if (in_array($playbooksId, $applied, true)) {
            Session::addMessageAfterRedirect(
                __('This playbook is already applied to the incident.', 'grcmanager'),
                false,
                WARNING
            );

            return 0;
        }
        $offset = $existing === [] ? 0 : (int) max(array_column($existing, 'rank'));
        $created = 0;
        foreach (PlaybookStep::getForPlaybook($playbooksId) as $step) {
            $task = new self();
            $created += $task->add([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'plugin_grcmanager_playbooks_id'         => $playbooksId,
                'plugin_grcmanager_playbooksteps_id'     => (int) $step['id'],
                'rank'         => $offset + (int) $step['rank'],
                'phase'        => (string) $step['phase'],
                'name'         => (string) $step['name'],
                'description'  => (string) $step['description'],
                'is_mandatory' => (int) $step['is_mandatory'],
            ]) ? 1 : 0;
        }
        if ($created > 0) {
            Log::history(
                $incidentsId,
                PluginGrcmanagerSecurityIncident::class,
                [0, '', sprintf(__('Playbook applied: %s', 'grcmanager'), $playbook->fields['name'])],
                '',
                Log::HISTORY_LOG_SIMPLE_MESSAGE
            );
        }

        return $created;
    }

    /**
     * PDF du plan de remédiation : tâches par phase avec cases à cocher, comptes rendus,
     * réalisations signées, zones de signature et empreinte SHA-256 du contenu, consignée dans
     * l'historique de l'incident à chaque impression.
     */
    public static function renderPdf(PluginGrcmanagerSecurityIncident $incident): string
    {
        $tasks = self::getForIncident((int) $incident->getID());
        $users = [];
        $hashed = [];
        foreach ($tasks as $task) {
            $doneBy = (int) $task['users_id_done'];
            if ($doneBy > 0 && !isset($users[$doneBy])) {
                $users[$doneBy] = getUserName($doneBy);
            }
            $hashed[] = array_intersect_key($task, array_flip([
                'id', 'name', 'phase', 'is_mandatory', 'status', 'report', 'users_id_done', 'date_done',
            ]));
        }
        $hash = hash('sha256', (string) json_encode($hashed, JSON_UNESCAPED_UNICODE));
        $playbooks = [];
        foreach (array_unique(array_filter(array_column($tasks, 'plugin_grcmanager_playbooks_id'))) as $playbookId) {
            $playbook = new Playbook();
            if ($playbook->getFromDB((int) $playbookId)) {
                $playbooks[] = (string) $playbook->fields['name'];
            }
        }

        $pdf = PdfRenderer::renderHtmlToPdf(TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/remediation_plan.html.twig',
            [
                'item'         => $incident,
                'groups'       => PlaybookRules::byPhase($tasks),
                'progress'     => PlaybookRules::progress($tasks),
                'phases'       => Playbook::getPhaseLabels(),
                'statuses'     => self::getStatuses(),
                'users'        => $users,
                'playbooks'    => $playbooks,
                'hash'         => $hash,
                'report_title' => sprintf(__('Remediation plan: %s', 'grcmanager'), $incident->fields['name']),
                'generated_at' => Html::convDateTime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'generated_by' => getUserName(Session::getLoginUserID() ?: 0),
            ]
        ));
        Log::history(
            (int) $incident->getID(),
            PluginGrcmanagerSecurityIncident::class,
            [0, '', sprintf(__('Remediation plan printed (SHA-256 %s)', 'grcmanager'), $hash)],
            '',
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );

        return $pdf;
    }

    /**
     * La clôture d'un incident est refusée tant qu'une tâche obligatoire n'est pas terminée.
     */
    public static function blocksClosure(int $incidentsId): bool
    {
        return PlaybookRules::blocksClosure(self::getForIncident($incidentsId));
    }

    /**
     * Tâches non terminées assignées à un utilisateur, sur les incidents qu'il peut voir.
     *
     * @return list<array<string, mixed>>
     */
    public static function getOpenTasksFor(int $usersId): array
    {
        $tasks = [];
        $rows = (new self())->find([
            'users_id' => $usersId,
            'NOT'      => ['status' => PlaybookRules::FINISHED],
        ], ['plugin_grcmanager_securityincidents_id ASC', 'rank ASC']);
        foreach ($rows as $row) {
            $incident = new PluginGrcmanagerSecurityIncident();
            $incidentId = (int) $row['plugin_grcmanager_securityincidents_id'];
            if ($incident->getFromDB($incidentId) && $incident->canViewItem()) {
                $tasks[] = $row + ['incident_name' => (string) $incident->fields['name']];
            }
        }

        return $tasks;
    }

    public function prepareInputForAdd($input)
    {
        if (!self::canEditIncident((int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0))) {
            return false;
        }
        $input['status'] = 'todo';

        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['plugin_grcmanager_securityincidents_id'], $input['plugin_grcmanager_playbooks_id']);
        // Réalisation horodatée et signée : par qui, quand ; effacée si la tâche est rouverte.
        if (isset($input['status']) && $input['status'] !== ($this->fields['status'] ?? '')) {
            if (in_array($input['status'], PlaybookRules::FINISHED, true)) {
                $input['users_id_done'] = (int) Session::getLoginUserID();
                $input['date_done'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
            } else {
                $input['users_id_done'] = 0;
                $input['date_done'] = 'NULL';
            }
        }

        return $this->checkInput($input);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if (in_array('status', $this->updates, true)) {
            Log::history(
                (int) $this->fields['plugin_grcmanager_securityincidents_id'],
                PluginGrcmanagerSecurityIncident::class,
                [0, '', sprintf(
                    __('Remediation task "%1$s": %2$s', 'grcmanager'),
                    $this->fields['name'],
                    self::getStatuses()[$this->fields['status']]['label'] ?? $this->fields['status']
                )],
                '',
                Log::HISTORY_LOG_SIMPLE_MESSAGE
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('The task name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (isset($input['status']) && !in_array($input['status'], PlaybookRules::STATUSES, true)) {
            unset($input['status']);
        }
        if (isset($input['phase']) && !in_array($input['phase'], PlaybookRules::PHASES, true)) {
            $input['phase'] = 'post_incident';
        }
        foreach (['is_mandatory'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = empty($input[$flag]) ? 0 : 1;
            }
        }
        if (isset($input['users_id'])) {
            $input['users_id'] = (int) $input['users_id'];
        }

        return $input;
    }

    public static function canEditIncident(int $incidentsId): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incidentsId > 0 && $incident->can($incidentsId, UPDATE);
    }

    public function canViewItem(): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0))
            && $incident->canViewItem();
    }

    public function canCreateItem(): bool
    {
        return self::canEditIncident((int) ($this->input['plugin_grcmanager_securityincidents_id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return self::canEditIncident((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }
}
