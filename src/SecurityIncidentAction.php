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
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentActionBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Incident\ActionLog;
use GlpiPlugin\Grcmanager\Services\Incident\IncidentPhases;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncidentTask;
use Session;

/**
 * Issue #370 : action d'investigation ou de remédiation menée pendant un incident (cible, outil,
 * commande exacte, constat, résultat). Mêmes droits que l'incident.
 */
class SecurityIncidentAction extends SecurityIncidentActionBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTypeName($nb = 0)
    {
        return _n('Investigation action', 'Investigation actions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-terminal-2';
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function getTools(): array
    {
        return [
            'edr'              => ['label' => __('EDR / XDR console', 'grcmanager'), 'icon' => 'ti ti-radar'],
            'powershell'       => ['label' => 'PowerShell', 'icon' => 'ti ti-terminal-2'],
            'active_directory' => ['label' => 'Active Directory', 'icon' => 'ti ti-sitemap'],
            'm365'             => ['label' => 'Microsoft 365', 'icon' => 'ti ti-brand-office'],
            'firewall'         => ['label' => __('Firewall', 'grcmanager'), 'icon' => 'ti ti-wall'],
            'backup'           => ['label' => __('Backup', 'grcmanager'), 'icon' => 'ti ti-database-export'],
            'forensic'         => ['label' => __('Forensic analysis', 'grcmanager'), 'icon' => 'ti ti-microscope'],
            'onsite'           => ['label' => __('On-site intervention', 'grcmanager'), 'icon' => 'ti ti-walk'],
            'other'            => ['label' => __('Other', 'grcmanager'), 'icon' => 'ti ti-tool'],
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getResults(): array
    {
        return [
            'success'  => [
                'label' => __('Successful', 'grcmanager'), 'class' => 'bg-green-lt', 'icon' => 'ti-circle-check',
            ],
            'partial'  => [
                'label' => __('Partial', 'grcmanager'), 'class' => 'bg-yellow-lt', 'icon' => 'ti-circle-half-2',
            ],
            'failure'  => ['label' => __('Failed', 'grcmanager'), 'class' => 'bg-red-lt', 'icon' => 'ti-circle-x'],
            'to_check' => [
                'label' => __('To be checked', 'grcmanager'), 'class' => 'bg-blue-lt', 'icon' => 'ti-eye-check',
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident) || !PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        $count = $item->isNewID($item->getID()) ? 0 : countElementsInTable(
            self::getTable(),
            ['plugin_grcmanager_securityincidents_id' => $item->getID()]
        );

        return self::createTabEntry(__('Action log', 'grcmanager'), $count, $item::class, self::getIcon());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForIncident(int $incidentsId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_securityincidents_id' => $incidentsId],
            ['date ASC', 'id ASC']
        ));
    }

    /**
     * Données communes à l'onglet et au PDF.
     *
     * @return array<string, mixed>
     */
    private static function viewData(PluginGrcmanagerSecurityIncident $incident): array
    {
        $actions = self::getForIncident((int) $incident->getID());
        $users = [];
        foreach (array_unique(array_map(static fn (array $a): int => (int) $a['users_id'], $actions)) as $usersId) {
            $users[$usersId] = $usersId > 0 ? getUserName($usersId) : '';
        }
        $byIncident = ['plugin_grcmanager_securityincidents_id' => $incident->getID()];
        $iocs = [];
        foreach ((new SecurityIncidentIoc())->find($byIncident) as $row) {
            $iocs[(int) $row['id']] = (string) $row['value'];
        }
        $tasks = [];
        foreach ((new PluginGrcmanagerSecurityIncidentTask())->find($byIncident) as $row) {
            $text = trim(html_entity_decode(strip_tags((string) $row['content'])));
            $tasks[(int) $row['id']] = sprintf('#%d %s', $row['id'], mb_strimwidth($text, 0, 60, '…'));
        }

        return [
            'item'    => $incident,
            'actions' => $actions,
            'summary' => ActionLog::summary($actions),
            'hash'    => ActionLog::contentHash($actions),
            'users'   => $users,
            'tools'   => self::getTools(),
            'results' => self::getResults(),
            'iocs'    => $iocs,
            'tasks'   => $tasks,
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }

        $template = '@grcmanager/tabs/action_log.html.twig';
        TemplateRenderer::getInstance()->display($template, self::viewData($item) + [
            'can_edit' => $item->canUpdateItem(),
            'form_url' => self::getFormURL(),
            'pdf_url'  => self::getFormURL() . '?pdf=' . (int) $item->getID(),
            'now'      => date('Y-m-d\TH:i', strtotime($_SESSION['glpi_currenttime'] ?? 'now') ?: time()),
        ]);

        return true;
    }

    /**
     * PDF « Journal des actions et remédiation ». L'impression est consignée dans l'historique de
     * l'incident avec l'empreinte du contenu.
     */
    public static function renderPdf(PluginGrcmanagerSecurityIncident $incident): string
    {
        $data = self::viewData($incident);
        $phases = [];
        foreach (IncidentPhases::compute($incident->fields) as $phase) {
            if ($phase['done']) {
                $phases[PluginGrcmanagerSecurityIncident::getPhaseLabels()[$phase['key']]] = (string) $phase['at'];
            }
        }
        $pdf = PdfRenderer::renderHtmlToPdf(TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/action_log.html.twig',
            $data + [
                'phases'       => $phases,
                'report_title' => sprintf(
                    __('Action and remediation log: %s', 'grcmanager'),
                    $incident->fields['name']
                ),
                'generated_at' => Html::convDateTime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'generated_by' => getUserName(Session::getLoginUserID() ?: 0),
            ]
        ));
        Log::history(
            (int) $incident->getID(),
            PluginGrcmanagerSecurityIncident::class,
            [0, '', sprintf(__('Action log printed (SHA-256 %s)', 'grcmanager'), $data['hash'])],
            '',
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );

        return $pdf;
    }

    public function prepareInputForAdd($input)
    {
        if (!self::canEditIncident((int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0))) {
            return false;
        }
        $input['users_id'] = (int) ($input['users_id'] ?? 0) ?: (int) Session::getLoginUserID();
        if (trim((string) ($input['date'] ?? '')) === '') {
            $input['date'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }

        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['plugin_grcmanager_securityincidents_id']);

        return $this->prepareInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareInput(array $input)
    {
        if (array_key_exists('date', $input)) {
            $date = IncidentPhases::normalizeInput($input['date']);
            if ($date === null || $date === 'NULL') {
                Session::addMessageAfterRedirect(__('The action date is invalid.', 'grcmanager'), false, ERROR);

                return false;
            }
            $input['date'] = $date;
        }
        if (isset($input['tool']) && !in_array($input['tool'], ActionLog::TOOLS, true)) {
            $input['tool'] = 'other';
        }
        if (isset($input['result']) && !in_array($input['result'], ActionLog::RESULTS, true)) {
            $input['result'] = 'to_check';
        }
        foreach (['plugin_grcmanager_securityincidentiocs_id', 'tasks_id'] as $link) {
            if (isset($input[$link])) {
                $input[$link] = (int) $input[$link];
            }
        }
        if (isset($input['target']) && trim((string) $input['target']) === '') {
            Session::addMessageAfterRedirect(__('The target of the action is required.', 'grcmanager'), false, ERROR);

            return false;
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
