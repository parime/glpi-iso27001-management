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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ReportedRiskBase;
use GlpiPlugin\Grcmanager\Install\ReportedRiskTables;
use GlpiPlugin\Grcmanager\Services\Risk\ReportedRiskRules as Rules;
use Html;
use NotificationEvent;
use Preference;
use Session;

/**
 * Issue #872 : signalement d'un risque par n'importe quel collaborateur, puis tri par le
 * responsable des risques (ISO/IEC 27001:2022, 6.1.2 ; mesure A.6.8).
 *
 * Deux publics, deux contrôles d'accès distincts :
 * - la personne qui signale n'a besoin que d'une session (interface simplifiée comprise) : elle
 *   signale dans son entité active et ne voit que ses propres signalements, jamais le registre ni le
 *   risque retenu (front/reportedrisk.mine.php, onglet de ses préférences) ;
 * - la file de tri exige la lecture du registre des risques (droit sensible), limitée aux entités de
 *   la session ; trier exige en plus la création et la modification des risques
 *   (front/reportedrisk.php, sous-page de la rubrique des risques).
 * Une décision est prise par une personne, une seule fois, et prévient l'auteur (notification).
 */
class ReportedRisk extends ReportedRiskBase
{
    public const RIGHTNAME = Risk::RIGHTNAME;

    /** Événement de notification : décision du tri, adressée à l'auteur du signalement. */
    public const DECIDED_EVENT = 'reported_risk_decided';

    public static function getTable($classname = null)
    {
        return ReportedRiskTables::REPORTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Reported risk', 'Reported risks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-message-report';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/reportedrisk.php';
    }

    public static function getMineURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/reportedrisk.mine.php';
    }

    /** Adresse absolue de « Mes signalements », pour les notifications. */
    public static function getMineAbsoluteURL(): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . self::getMineURL(false);
    }

    /** File de tri : lecture du registre des risques (droit sensible). */
    public static function canViewQueue(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /** Trier : créer un risque ou en modifier un, en plus de lire le registre. */
    public static function canTriage(): bool
    {
        return self::canViewQueue()
            && (bool) Session::haveRight(self::RIGHTNAME, CREATE)
            && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canViewQueue()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Libellé, couleur et icône de chaque statut.
     *
     * @return array<string, array{label: string, class: string, icon: string, mine: string}>
     */
    public static function statusStyles(): array
    {
        return [
            Rules::STATUS_NEW      => [
                'label' => __('To triage', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-hourglass',
                'mine'  => __('Received, waiting to be examined', 'grcmanager'),
            ],
            Rules::STATUS_ACCEPTED => [
                'label' => __('Added to the risk register', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
                'mine'  => __('Taken into account: added to the risk register', 'grcmanager'),
            ],
            Rules::STATUS_MERGED   => [
                'label' => __('Linked to an existing risk', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-git-merge',
                'mine'  => __('Taken into account: this risk was already known and is being followed', 'grcmanager'),
            ],
            Rules::STATUS_REJECTED => [
                'label' => __('Rejected with a reason', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-circle-x',
                'mine'  => __('Not kept', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, class: string}>
     */
    public static function severityStyles(): array
    {
        return [
            Rules::SEVERITY_UNKNOWN => [
                'label' => __("I don't know", 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
            ],
            Rules::SEVERITY_LOW     => [
                'label' => __('Minor: an annoyance', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
            ],
            Rules::SEVERITY_MEDIUM  => [
                'label' => __('Significant: could disrupt work or expose information', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
            ],
            Rules::SEVERITY_HIGH    => [
                'label' => __('Serious: could cause major damage', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
            ],
        ];
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            Rules::ERROR_NAME_REQUIRED        => __('Give your report a short title.', 'grcmanager'),
            Rules::ERROR_NAME_TOO_LONG        => __('The title is too long (255 characters at most).', 'grcmanager'),
            Rules::ERROR_DESCRIPTION_REQUIRED => __('Describe what you noticed.', 'grcmanager'),
            Rules::ERROR_DESCRIPTION_TOO_LONG => __('The description is too long.', 'grcmanager'),
            Rules::ERROR_UNKNOWN_DECISION     => __('Unknown decision.', 'grcmanager'),
            Rules::ERROR_ALREADY_DECIDED      => __('This report has already been triaged.', 'grcmanager'),
            Rules::ERROR_REASON_REQUIRED      => __(
                'Give the reason for the rejection: the author will read it.',
                'grcmanager'
            ),
            Rules::ERROR_REASON_TOO_LONG      => __('The message is too long.', 'grcmanager'),
            Rules::ERROR_RISK_REQUIRED        => __('Choose the existing risk to link this report to.', 'grcmanager'),
            default                           => __('You cannot do this on this report.', 'grcmanager'),
        };
    }

    /**
     * Enregistre un signalement de $usersId dans son entité active. Aucun droit exigé : toute
     * personne connectée peut signaler un risque.
     *
     * @param array<string, mixed> $input
     * @return array{id: int, error: ?string}
     */
    public static function submit(int $usersId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $error = Rules::reportError($name, $description);
        if ($usersId <= 0) {
            $error = 'denied';
        }
        if ($error !== null) {
            return ['id' => 0, 'error' => $error];
        }
        $id = (int) (new self())->add([
            'entities_id' => (int) Session::getActiveEntity(),
            'name'        => $name,
            'description' => $description,
            'severity'    => Rules::normalizeSeverity((string) ($input['severity'] ?? '')),
            'users_id'    => $usersId,
            'status'      => Rules::STATUS_NEW,
        ]);

        return ['id' => $id, 'error' => $id > 0 ? null : 'denied'];
    }

    /**
     * Signalements de $usersId, du plus récent au plus ancien : rien d'autre que ses propres lignes.
     *
     * @return list<array<string, mixed>>
     */
    public static function mine(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['users_id' => $usersId],
                'ORDER' => ['date_creation DESC', 'id DESC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Contenu de « Mes signalements de risques » : formulaire puis historique de la personne. Ni le
     * titre ni le lien du risque retenu ne sont montrés (le registre reste réservé).
     */
    public static function showMine(int $usersId): void
    {
        $styles = self::statusStyles();
        $rows = [];
        foreach (self::mine($usersId) as $row) {
            $style = $styles[(string) $row['status']] ?? $styles[Rules::STATUS_NEW];
            $rows[] = [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'description'   => (string) $row['description'],
                'date_text'     => (string) Html::convDateTime($row['date_creation']),
                'decision_text' => self::decisionDate($row, false),
                'comment'       => (string) ($row['decision_comment'] ?? ''),
                'status'        => (string) $row['status'],
                'status_label'  => $style['mine'],
                'status_class'  => $style['class'],
                'status_icon'   => $style['icon'],
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/reported_risk_mine.html.twig', [
            'rows'       => $rows,
            'action'     => self::getMineURL(),
            'severities' => self::severityStyles(),
            'name_max'   => Rules::NAME_MAX,
            'evidence_url' => AuditEvidenceRequest::countWaiting($usersId) > 0
                ? AuditEvidenceRequest::getMineURL()
                : '',
        ]);
    }

    /**
     * File de tri : signalements des entités de la session ($status vide : tous).
     *
     * @return list<array<string, mixed>>
     */
    public static function queue(string $status = ''): array
    {
        global $DB;

        if (!self::canViewQueue()) {
            return [];
        }
        $table = self::getTable();
        $where = getEntitiesRestrictCriteria($table, '', '', false);
        if ($status !== '') {
            $where["$table.status"] = $status;
        }
        $rows = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 2000]) as $row) {
            $row['severity'] = (string) $row['severity'];
            $row['date_creation'] = (string) $row['date_creation'];
            $rows[] = $row;
        }

        return Rules::sortQueue($rows);
    }

    /**
     * Risques auxquels rattacher un signalement de l'entité $entityId : ceux de cette entité et ceux,
     * récursifs, de ses entités parentes, visibles dans la session et non clos.
     *
     * @return array<int, string>
     */
    public static function mergeTargets(int $entityId): array
    {
        global $DB;

        $risk = new Risk();
        $table = Risk::getTable();
        $where = [
            'AND' => [
                getEntitiesRestrictCriteria($table, '', $entityId, true),
                getEntitiesRestrictCriteria($table, '', '', true),
            ],
            'NOT' => ["$table.status" => 'closed'],
        ];
        if ($risk->isField('is_deleted')) {
            $where["$table.is_deleted"] = 0;
        }
        $targets = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => $table,
                'WHERE'  => $where,
                'ORDER'  => 'title',
                'LIMIT'  => 1000,
            ]) as $row
        ) {
            $targets[(int) $row['id']] = (string) $row['title'] !== '' ? (string) $row['title'] : '#' . $row['id'];
        }

        return $targets;
    }

    /**
     * Page de tri : synthèse, filtre par statut et un cadre par signalement.
     */
    public static function showQueue(string $filter): void
    {
        $all = self::queue();
        $summary = Rules::summary($all);
        if ($filter === '' && $summary[Rules::STATUS_NEW] > 0) {
            $filter = Rules::STATUS_NEW;
        }
        $styles = self::statusStyles();
        $severities = self::severityStyles();
        $canTriage = self::canTriage();
        $targetsByEntity = [];
        $rows = [];
        foreach ($all as $row) {
            if ($filter !== 'all' && $filter !== '' && $row['status'] !== $filter) {
                continue;
            }
            $entityId = (int) $row['entities_id'];
            $isNew = $row['status'] === Rules::STATUS_NEW;
            if ($canTriage && $isNew && !isset($targetsByEntity[$entityId])) {
                $targetsByEntity[$entityId] = self::mergeTargets($entityId);
            }
            $riskId = (int) $row['plugin_grcmanager_risks_id'];
            $risk = new Risk();
            $riskLink = $riskId > 0 && $risk->can($riskId, READ)
                ? ['url' => Risk::getFormURLWithID($riskId), 'name' => (string) $risk->fields['title']]
                : null;
            $style = $styles[(string) $row['status']] ?? $styles[Rules::STATUS_NEW];
            $severity = $severities[(string) $row['severity']] ?? $severities[Rules::SEVERITY_UNKNOWN];
            $rows[] = [
                'id'             => (int) $row['id'],
                'name'           => (string) $row['name'],
                'description'    => (string) $row['description'],
                'author'         => (string) getUserName((int) $row['users_id']),
                'entity'         => (string) \Dropdown::getDropdownName('glpi_entities', $entityId),
                'date_text'      => (string) Html::convDateTime($row['date_creation']),
                'is_new'         => $isNew,
                'status_label'   => $style['label'],
                'status_class'   => $style['class'],
                'status_icon'    => $style['icon'],
                'severity_label' => $severity['label'],
                'severity_class' => $severity['class'],
                'comment'        => (string) ($row['decision_comment'] ?? ''),
                'decider'        => self::deciderName($row),
                'decision_text'  => self::decisionDate($row, true),
                'risk'           => $riskLink,
                'targets'        => $targetsByEntity[$entityId] ?? [],
            ];
        }
        $filters = [];
        foreach ([Rules::STATUS_NEW, Rules::STATUS_ACCEPTED, Rules::STATUS_MERGED, Rules::STATUS_REJECTED] as $status) {
            $filters[] = [
                'key' => $status, 'label' => $styles[$status]['label'], 'icon' => $styles[$status]['icon'],
                'class' => $styles[$status]['class'], 'count' => $summary[$status],
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/reported_risk_queue.html.twig', [
            'rows'       => $rows,
            'filters'    => $filters,
            'filter'     => $filter === '' ? 'all' : $filter,
            'total'      => count($all),
            'action'     => self::getSearchURL(),
            'can_triage' => $canTriage,
            'categories' => Risk::getCategories(),
            'mine_url'   => self::getMineURL(),
        ]);
    }

    /**
     * Décision du tri sur le signalement $id. Accepter crée un risque « identifié » dans l'entité du
     * signalement (à compléter ensuite : propriétaire, probabilité, impact) ; rattacher relie le
     * signalement à un risque existant, sans le modifier ; rejeter exige un motif. L'auteur est
     * prévenu par notification.
     *
     * @return array{error: ?string, risks_id: int}
     */
    public static function decide(
        int $id,
        string $decision,
        string $reason,
        int $riskId = 0,
        string $category = ''
    ): array {
        $denied = ['error' => 'denied', 'risks_id' => 0];
        $report = new self();
        if (!self::canTriage() || !$report->getFromDB($id)) {
            return $denied;
        }
        $entityId = (int) $report->fields['entities_id'];
        if (!Session::haveAccessToEntity($entityId)) {
            return $denied;
        }
        $error = Rules::decisionError((string) $report->fields['status'], $decision, $reason, $riskId);
        if ($error !== null) {
            return ['error' => $error, 'risks_id' => 0];
        }

        $risk = new Risk();
        if ($decision === Rules::STATUS_MERGED) {
            if (!$risk->can($riskId, READ) || !array_key_exists($riskId, self::mergeTargets($entityId))) {
                return $denied;
            }
        } elseif ($decision === Rules::STATUS_ACCEPTED) {
            $input = [
                'title'        => (string) $report->fields['name'],
                'description'  => (string) $report->fields['description'] . "\n\n" . sprintf(
                    __('Reported by %1$s on %2$s (staff report #%3$d).', 'grcmanager'),
                    getUserName((int) $report->fields['users_id']),
                    Html::convDateTime($report->fields['date_creation']),
                    $id
                ),
                'category'     => array_key_exists($category, Risk::getCategories()) ? $category : 'process',
                'entities_id'  => $entityId,
                'is_recursive' => 0,
                'status'       => 'identified',
            ];
            if (!$risk->can(-1, CREATE, $input)) {
                return $denied;
            }
            $riskId = (int) $risk->add($input);
            if ($riskId <= 0) {
                return $denied;
            }
        } else {
            $riskId = 0;
        }

        $report->update([
            'id'                         => $id,
            'status'                     => $decision,
            'plugin_grcmanager_risks_id' => $riskId,
            'decision_comment'           => trim($reason),
            'users_id_decision'          => (int) Session::getLoginUserID(),
            'date_decision'              => date('Y-m-d H:i:s'),
        ]);
        // Fiche relue : la notification cite la décision enregistrée.
        $report->getFromDB($id);
        NotificationEvent::raiseEvent(self::DECIDED_EVENT, $report);

        return ['error' => null, 'risks_id' => $riskId];
    }

    /** @param array<string, mixed> $row */
    private static function deciderName(array $row): string
    {
        $usersId = (int) ($row['users_id_decision'] ?? 0);

        return $usersId > 0 ? (string) getUserName($usersId) : '';
    }

    /** @param array<string, mixed> $row */
    private static function decisionDate(array $row, bool $withTime): string
    {
        if (($row['date_decision'] ?? null) === null) {
            return '';
        }

        return (string) ($withTime ? Html::convDateTime($row['date_decision']) : Html::convDate($row['date_decision']));
    }

    /**
     * Signalements rattachés au risque $riskId (onglet du risque) : la trace de son origine.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRisk(int $riskId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
                'ORDER' => 'date_creation DESC',
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('My risk reports', 'grcmanager'), 0, null, self::getIcon());
        }
        if ($item instanceof Risk && !$item->isNewItem() && self::canViewQueue()) {
            $count = $_SESSION['glpishow_count_on_tabs'] ? count(self::forRisk($item->getID())) : 0;

            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, null, self::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            self::showMine((int) Session::getLoginUserID());

            return true;
        }
        if (
            !$item instanceof Risk || $item->isNewItem() || !self::canViewQueue()
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        $styles = self::statusStyles();
        $rows = [];
        foreach (self::forRisk($item->getID()) as $row) {
            $style = $styles[(string) $row['status']] ?? $styles[Rules::STATUS_NEW];
            $rows[] = [
                'name'         => (string) $row['name'],
                'description'  => (string) $row['description'],
                'author'       => (string) getUserName((int) $row['users_id']),
                'date_text'    => (string) Html::convDateTime($row['date_creation']),
                'status_label' => $style['label'],
                'status_class' => $style['class'],
                'status_icon'  => $style['icon'],
                'decider'       => self::deciderName($row),
                'decision_text' => self::decisionDate($row, false),
            ];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/_reported_risk_risk_tab.html.twig', [
            'rows'      => $rows,
            'queue_url' => self::getSearchURL(),
        ]);

        return true;
    }
}
