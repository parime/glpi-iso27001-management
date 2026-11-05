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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\BusinessProcessRiskBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use Log;
use Session;

/**
 * Issue #377 : risque du registre lié à un processus métier (ISO 22301 8.2.3, appréciation des
 * risques de continuité).
 *
 * Deux droits distincts sont en jeu : celui de la continuité (processus, avec son entité) et celui
 * des registres sensibles (registre des risques). Le lien n'est visible, ajouté ou retiré qu'avec les
 * deux : lire les risques, et lire (ou modifier) le processus. Onglet « Risques » sur le processus,
 * onglet « Processus métier » sur le risque.
 *
 * Depuis l'onglet « Dépendances », un actif qui ne tient pas les objectifs du processus peut donner
 * lieu à un risque proposé, lié à l'actif et au processus (createRecoveryRisk()).
 */
class BusinessProcessRisk extends BusinessProcessRiskBase
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_businessprocesses_risks';
    }

    public static function getTypeName($nb = 0)
    {
        return Risk::getTypeName($nb);
    }

    public static function getIcon()
    {
        return Risk::getIcon();
    }

    /**
     * Les deux droits de lecture : continuité et registre des risques.
     */
    public static function canSeeLinks(): bool
    {
        return BusinessProcess::canView() && Risk::canView();
    }

    /**
     * Risques liés au processus (l'appelant a vérifié canSeeLinks()).
     *
     * @return list<array<string, mixed>>
     */
    public static function getRisksForProcess(int $processId): array
    {
        $levels = Risk::getImpacts();
        $statuses = Risk::getStatuses();
        $treatments = Risk::getTreatments();
        $risk = new Risk();
        $risks = [];
        foreach ((new self())->find(['plugin_grcmanager_businessprocesses_id' => $processId]) as $link) {
            if (!$risk->getFromDB((int) $link['plugin_grcmanager_risks_id']) || !$risk->canViewItem()) {
                continue;
            }
            $risks[] = [
                'link_id'   => (int) $link['id'],
                'id'        => (int) $risk->getID(),
                'title'     => (string) $risk->fields['title'],
                'url'       => $risk->getLinkURL(),
                'level'     => $levels[$risk->fields['risk_level'] ?? ''] ?? '',
                'status'    => $statuses[$risk->fields['status'] ?? ''] ?? '',
                'treatment' => $treatments[$risk->fields['treatment'] ?? ''] ?? '',
                'comment'   => (string) $link['comment'],
            ];
        }
        usort($risks, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $risks;
    }

    /**
     * Processus liés au risque, limités à ceux que la personne peut lire.
     *
     * @return list<array<string, mixed>>
     */
    public static function getProcessesForRisk(int $riskId): array
    {
        $durations = BusinessProcess::getDurationOptions();
        $process = new BusinessProcess();
        $processes = [];
        foreach ((new self())->find(['plugin_grcmanager_risks_id' => $riskId]) as $link) {
            if (
                !$process->getFromDB((int) $link['plugin_grcmanager_businessprocesses_id'])
                || !$process->canViewItem()
            ) {
                continue;
            }
            $processes[] = [
                'name'    => (string) $process->fields['name'],
                'url'     => $process->getLinkURL(),
                'mtpd'    => $durations[(int) $process->fields['mtpd_hours']] ?? '',
                'rto'     => $durations[(int) $process->fields['rto_hours']] ?? '',
                'comment' => (string) $link['comment'],
            ];
        }
        usort($processes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $processes;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !self::canSeeLinks()) {
            return '';
        }
        if ($item instanceof BusinessProcess) {
            return self::createTabEntry(
                Risk::getTypeName(Session::getPluralNumber()),
                count(self::getRisksForProcess((int) $item->getID())),
                $item::class,
                Risk::getIcon()
            );
        }
        if ($item instanceof Risk) {
            return self::createTabEntry(
                BusinessProcess::getTypeName(Session::getPluralNumber()),
                count(self::getProcessesForRisk((int) $item->getID())),
                $item::class,
                BusinessProcess::getIcon()
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !self::canSeeLinks() || !$item->canViewItem()) {
            return false;
        }
        if ($item instanceof BusinessProcess) {
            $linked = self::getRisksForProcess((int) $item->getID());
            $canEdit = $item->canUpdateItem();
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/business_process_risks.html.twig', [
                'item'     => $item,
                'risks'    => $linked,
                'choices'  => $canEdit ? self::riskChoices(array_column($linked, 'id')) : [],
                'can_edit' => $canEdit,
                'form_url' => self::getFormURL(),
            ]);

            return true;
        }
        if ($item instanceof Risk) {
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/risk_business_processes.html.twig', [
                'processes' => self::getProcessesForRisk((int) $item->getID()),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Risques du registre proposés dans la liste d'ajout (registre global, lu avec le droit dédié).
     *
     * @param list<int> $used
     * @return array<int, string>
     */
    private static function riskChoices(array $used): array
    {
        global $DB;

        $choices = [0 => \Dropdown::EMPTY_VALUE];
        foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => Risk::getTable(), 'ORDER' => 'title']) as $row) {
            if (!in_array((int) $row['id'], $used, true)) {
                $choices[(int) $row['id']] = (string) $row['title'];
            }
        }

        return $choices;
    }

    public function prepareInputForAdd($input)
    {
        $processId = (int) ($input['plugin_grcmanager_businessprocesses_id'] ?? 0);
        $riskId = (int) ($input['plugin_grcmanager_risks_id'] ?? 0);
        $process = new BusinessProcess();
        if (!$process->getFromDB($processId) || !$process->canUpdateItem() || !Risk::canView()) {
            return false;
        }
        $risk = new Risk();
        if ($riskId <= 0 || !$risk->getFromDB($riskId) || !$risk->canViewItem()) {
            Session::addMessageAfterRedirect(htmlescape(__('Choose a risk to link.', 'grcmanager')), false, ERROR);

            return false;
        }
        $existing = ['plugin_grcmanager_businessprocesses_id' => $processId, 'plugin_grcmanager_risks_id' => $riskId];
        if (countElementsInTable(self::getTable(), $existing) > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('This risk is already linked to the process.', 'grcmanager')),
                false,
                WARNING
            );

            return false;
        }

        return $existing + ['comment' => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255)];
    }

    public function prepareInputForUpdate($input)
    {
        return [
            'id'      => (int) ($input['id'] ?? 0),
            'comment' => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255),
        ];
    }

    /**
     * Risque proposé pour un élément qui ne tient pas les objectifs du processus (RTO, RPO, ou RTO
     * non défini), lié à l'élément et au processus, à apprécier dans le registre. L'appelant a
     * vérifié le droit de modifier le processus, celui de créer un risque et la lecture de l'élément.
     *
     * @param list<string> $issues codes de BusinessProcess::analyze() pour cet élément
     * @return int identifiant du risque (existant ou créé), 0 en cas d'échec
     */
    public static function createRecoveryRisk(BusinessProcess $process, CommonDBTM $item, array $issues): int
    {
        $title = sprintf(
            __('Insufficient recovery of %1$s for the process %2$s', 'grcmanager'),
            $item->getName(),
            $process->fields['name']
        );
        $riskId = 0;
        foreach (Risk::getRisksLinkedToItem($item::class, (int) $item->getID()) as $existing) {
            if ($existing['title'] === $title) {
                $riskId = (int) $existing['id'];
                break;
            }
        }
        if ($riskId === 0) {
            $labels = BusinessProcess::getIssueLabels();
            $durations = BusinessProcess::getDurationOptions();
            $description = sprintf(
                __('The process %1$s requires a recovery within %2$s (RTO) with a data loss of at most %3$s (RPO).', 'grcmanager'),
                $process->fields['name'],
                $durations[(int) $process->fields['rto_hours']] ?? '-',
                $durations[(int) $process->fields['rpo_hours']] ?? '-'
            ) . "\n- " . implode("\n- ", array_map(
                static fn (string $code): string => $labels[$code] ?? $code,
                $issues
            )) . "\n\n" . __(
                'Improve the recovery of this item (backup, redundancy, contract) or accept the risk.',
                'grcmanager'
            );
            $riskId = (int) (new Risk())->add([
                'title'       => $title,
                'description' => $description,
                'category'    => 'technical',
                'probability' => 'possible',
                'impact'      => 'high',
                'status'      => 'identified',
                'users_id'    => (int) Session::getLoginUserID(),
                'linked_items_' . str_replace('\\', '_', $item::class) => [(int) $item->getID()],
            ]);
        }
        if ($riskId > 0) {
            $link = ['plugin_grcmanager_businessprocesses_id' => (int) $process->getID(), 'plugin_grcmanager_risks_id' => $riskId];
            if (countElementsInTable(self::getTable(), $link) === 0) {
                (new self())->add($link + ['comment' => (string) $item->getName()]);
            }
        }

        return $riskId;
    }

    public function post_addItem()
    {
        $this->history(Log::HISTORY_ADD_SUBITEM);
    }

    public function post_purgeItem()
    {
        $this->history(Log::HISTORY_DELETE_SUBITEM);
    }

    private function history(int $action): void
    {
        $label = sprintf('%s #%d', Risk::getTypeName(1), (int) $this->fields['plugin_grcmanager_risks_id']);
        Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            $action === Log::HISTORY_ADD_SUBITEM ? [0, '', $label] : [0, $label, ''],
            self::class,
            $action
        );
    }
}
