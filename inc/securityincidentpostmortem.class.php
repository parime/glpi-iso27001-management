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
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Incident\PostMortem;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;

/**
 * Issue #169 : rapport post-incident (enseignements tirés, mesure A.5.27). L'onglet de l'incident
 * en présente le contenu ; le bouton produit le PDF, archivé en Document sur l'incident et sur la
 * mesure A.5.27 de la SoA.
 */
class PluginGrcmanagerSecurityIncidentPostMortem extends CommonGLPI
{
    /** Mesure de l'Annexe A à laquelle le rapport sert de preuve. */
    private const CONTROL_CODE = 'A.5.27';

    public static function getTypeName($nb = 0)
    {
        return __('Rapport post-incident', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-report-analytics';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/securityincident.postmortem.php';
    }

    /**
     * Contenu du rapport.
     *
     * @return array<string, mixed>
     */
    public static function collect(PluginGrcmanagerSecurityIncident $incident): array
    {
        global $DB;

        $id = $incident->getID();
        $fields = $incident->fields;
        $own = ['itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $id];
        $byIncident = ['plugin_grcmanager_securityincidents_id' => $id];
        $events = [[
            'date'  => (string) $fields['date'],
            'kind'  => __('Déclaration', 'grcmanager'),
            'text'  => (string) $fields['name'],
            'icon'  => 'ti-flag',
        ]];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_logs',
                // Option de recherche 12 : le statut (journal natif de GLPI).
                'WHERE' => $own + ['id_search_option' => 12],
            ]) as $log
        ) {
            $events[] = [
                'date' => (string) $log['date_mod'],
                'kind' => __('Changement de statut', 'grcmanager'),
                'text' => PluginGrcmanagerSecurityIncident::getStatus((int) $log['old_value'])
                    . ' → ' . PluginGrcmanagerSecurityIncident::getStatus((int) $log['new_value']),
                'icon' => 'ti-arrows-exchange',
            ];
        }
        foreach ((new ITILFollowup())->find($own) as $row) {
            $events[] = [
                'date' => (string) $row['date'],
                'kind' => $row['is_private'] ? __('Suivi privé', 'grcmanager') : __('Suivi', 'grcmanager'),
                'text' => self::plain((string) $row['content']),
                'icon' => 'ti-message',
            ];
        }
        foreach ((new PluginGrcmanagerSecurityIncidentTask())->find($byIncident) as $row) {
            $events[] = [
                'date' => (string) ($row['begin'] ?: $row['date']),
                'kind' => __('Tâche', 'grcmanager'),
                'text' => self::plain((string) $row['content']),
                'icon' => 'ti-checkbox',
            ];
        }
        foreach ((new ITILSolution())->find($own) as $row) {
            $events[] = [
                'date' => (string) $row['date_creation'],
                'kind' => __('Solution', 'grcmanager'),
                'text' => self::plain((string) $row['content']),
                'icon' => 'ti-circle-check',
            ];
        }

        $assets = [];
        foreach ((new PluginGrcmanagerSecurityIncident_Item())->find($byIncident) as $row) {
            $itemtype = (string) $row['itemtype'];
            $item = is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null;
            if ($item !== null && $item->getFromDB((int) $row['items_id']) && $item->canViewItem()) {
                $assets[] = $itemtype::getTypeName(1) . ' ' . $item->getName();
            }
        }

        $deadlines = [];
        $labels = PluginGrcmanagerSecurityIncident::getRegulatoryLabels();
        foreach (RegulatoryDeadlines::compute($fields) as $row) {
            $deadlines[] = [
                'label'   => $labels[$row['key']] ?? $row['key'],
                'due'     => $row['due'],
                'done_at' => $row['done_at'],
                'outcome' => PostMortem::deadlineOutcome($row['done_at'], $row['due']),
            ];
        }

        $actions = [];
        foreach ((new PluginGrcmanagerNonconformity())->find($byIncident) as $row) {
            $actions[] = [
                'title'  => (string) $row['title'],
                'status' => PluginGrcmanagerNonconformity::getStatuses()[$row['status']] ?? $row['status'],
                'due'    => (string) ($row['due_date'] ?? ''),
                'url'    => PluginGrcmanagerNonconformity::getFormURLWithID((int) $row['id']),
            ];
        }

        return [
            'incident'   => [
                'id'          => $id,
                'name'        => (string) $fields['name'],
                'status'      => PluginGrcmanagerSecurityIncident::getStatus((int) $fields['status']),
                'closed'      => in_array(
                    (int) $fields['status'],
                    PluginGrcmanagerSecurityIncident::getClosedStatusArray(),
                    true
                ),
                'category'    => PluginGrcmanagerSecurityIncident::getCategories()[$fields['category']] ?? '',
                'severity'    => PluginGrcmanagerSecurityIncident::getSeverities()[$fields['severity']] ?? '',
                'description' => self::plain((string) $fields['content']),
                'root_cause'  => self::plain((string) ($fields['root_cause'] ?? '')),
                'lessons'     => self::plain((string) ($fields['lessons_learned'] ?? '')),
                'opened'      => (string) $fields['date'],
                'solved'      => (string) ($fields['solvedate'] ?? ''),
                'closed_at'   => (string) ($fields['closedate'] ?? ''),
                'hours'       => PostMortem::hoursBetween(
                    $fields['date'],
                    $fields['solvedate'] ?: $fields['closedate']
                ),
            ],
            'timeline'   => PostMortem::sortTimeline($events),
            'assets'     => $assets,
            'cves'       => array_column(
                (new PluginGrcmanagerSecurityIncidentCve())->find($byIncident),
                'cve_id'
            ),
            'cost'       => PostMortem::costTotal(
                (new PluginGrcmanagerSecurityIncidentCost())->find($byIncident)
            ),
            'deadlines'  => $deadlines,
            'actions'    => $actions,
            'tickets'    => PluginGrcmanagerSecurityIncidentTicket::getTicketIdsForIncident($id),
        ];
    }

    /**
     * Texte brut d'un contenu riche (suivis, tâches), pour le rapport.
     */
    private static function plain(string $html): string
    {
        // Conversion native de GLPI, qui garde les retours à la ligne.
        $text = RichText::getTextFromHtml($html, true, false, false, true, true);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public static function renderPdf(PluginGrcmanagerSecurityIncident $incident): string
    {
        return PdfRenderer::renderHtmlToPdf(TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/postmortem_report.html.twig',
            self::collect($incident) + [
                'report_title'       => sprintf(
                    __('Rapport post-incident : %s', 'grcmanager'),
                    $incident->fields['name']
                ),
                'generated_at_label' => sprintf(
                    __('Généré le %s', 'grcmanager'),
                    Html::convDateTime(date('Y-m-d H:i:s'))
                ),
            ]
        ));
    }

    /**
     * Produit le PDF et l'archive en Document sur l'incident et sur la mesure A.5.27.
     */
    public static function archive(PluginGrcmanagerSecurityIncident $incident): int
    {
        global $DB;

        $fileName = sprintf('rapport-post-incident-%d-%s.pdf', $incident->getID(), date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, self::renderPdf($incident)) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('Rapport post-incident - %s', 'grcmanager'), $incident->fields['name']),
            'entities_id'   => (int) $incident->fields['entities_id'],
            'is_recursive'  => 1,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        $targets = [[PluginGrcmanagerSecurityIncident::class, $incident->getID()]];
        $control = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => PluginGrcmanagerControl::getTable(),
            'WHERE'  => ['code' => self::CONTROL_CODE],
        ])->current();
        if ($control !== null) {
            $targets[] = [PluginGrcmanagerControl::class, (int) $control['id']];
        }
        foreach ($targets as [$itemtype, $itemsId]) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
            ]);
        }

        return $documentsId;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerSecurityIncident || $item->isNewItem() || !$item->canViewItem()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerSecurityIncident) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/postmortem_tab.html.twig', self::collect($item) + [
            'action'   => self::getFormURL(),
            'can_make' => $item->canViewItem() && Session::haveRight('document', CREATE),
        ]);

        return true;
    }
}
