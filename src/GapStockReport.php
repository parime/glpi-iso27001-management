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
use Document;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Capa\GapStockRules as Rules;
use Html;
use Session;

/**
 * Issue #870 : rapport du stock d'écarts pour démontrer la clause 10 (amélioration, non-conformités
 * et actions correctives) : non-conformités, autres constats et actions du registre des CAPA, et
 * actions décidées en revue de direction.
 *
 * Stock ouvert par type, gravité, origine, entité et responsable ; âge et retard sur l'échéance ;
 * délai moyen de clôture et efficacité vérifiée sur une période ; tendance mensuelle (ouverts,
 * clôturés, stock). Lecture seule : aucun statut, aucune échéance ni aucun responsable n'est modifié.
 * Export CSV et PDF ; archivage du PDF comme preuve des exigences 10.1 et 10.2, repris par le
 * dossier de preuves de l'auditeur (#868). Droit du plugin, entités de la session.
 */
class GapStockReport extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Exigences du SMSI dont le rapport est la preuve. */
    public const CLAUSES = ['10.1', '10.2'];

    /** Écarts ouverts listés au plus sur la page et dans le PDF (les plus en retard d'abord). */
    public const MAX_ROWS = 300;

    public static function getTypeName($nb = 0)
    {
        return __('Gap backlog', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-arrows-vertical';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/gapstock.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canArchive(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Audits » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            Rules::KIND_NONCONFORMITY => Nonconformity::getTypeName(1),
            Rules::KIND_FINDING       => __('Other finding or improvement action', 'grcmanager'),
            Rules::KIND_REVIEW_ACTION => ManagementReviewAction::getTypeName(1),
        ];
    }

    /** @return array<string, string> */
    public static function originLabels(): array
    {
        return [
            Rules::ORIGIN_INTERNAL_AUDIT      => __('Internal audit', 'grcmanager'),
            Rules::ORIGIN_CERTIFICATION_AUDIT => __('Certification audit', 'grcmanager'),
            Rules::ORIGIN_INCIDENT            => __('Security incident', 'grcmanager'),
            Rules::ORIGIN_MANAGEMENT_REVIEW   => __('Management review', 'grcmanager'),
            Rules::ORIGIN_OTHER               => __('Other source', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function ageLabels(): array
    {
        return [
            '0-30'   => __('30 days or less', 'grcmanager'),
            '31-90'  => __('31 to 90 days', 'grcmanager'),
            '91-180' => __('91 to 180 days', 'grcmanager'),
            '180+'   => __('More than 180 days', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------ Filtres.

    /**
     * Filtres reçus (GET), normalisés : valeur inconnue ignorée, entité hors session ignorée.
     *
     * @param array<string, mixed> $input
     * @return array{from: string, to: string, kind: string, severity: string, origin: string,
     *     users_id: int, entities_id: int}
     */
    public static function filters(array $input, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $period = Rules::period($input['from'] ?? null, $input['to'] ?? null, $today);
        $pick = static fn (string $key, array $allowed): string => in_array($input[$key] ?? '', $allowed, true)
            ? (string) $input[$key] : '';
        $entityId = max(0, (int) ($input['entities_id'] ?? 0));
        if ($entityId > 0 && !Session::haveAccessToEntity($entityId)) {
            $entityId = 0;
        }

        return [
            'from'        => $period['from'],
            'to'          => $period['to'],
            'kind'        => $pick('kind', Rules::KINDS),
            'severity'    => $pick('severity', array_keys(Nonconformity::getSeverities())),
            'origin'      => $pick('origin', Rules::ORIGINS),
            'users_id'    => max(0, (int) ($input['users_id'] ?? 0)),
            'entities_id' => $entityId,
        ];
    }

    // ------------------------------------------------------------------ Données.

    /**
     * Écarts des entités de la session, normalisés pour GapStockRules, filtres appliqués.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function rows(array $filters, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $rows = array_merge(self::nonconformityRows($filters, $today), self::reviewActionRows($filters, $today));

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            return ($filters['kind'] === '' || $row['kind'] === $filters['kind'])
                && ($filters['severity'] === '' || $row['severity'] === $filters['severity'])
                && ($filters['origin'] === '' || $row['origin'] === $filters['origin'])
                && ($filters['users_id'] === 0 || $row['users_id'] === $filters['users_id']);
        }));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function nonconformityRows(array $filters, string $today): array
    {
        global $DB;

        $table = Nonconformity::getTable();
        $audits = Audit::getTable();
        if (!$DB->tableExists($table)) {
            return [];
        }
        $hasClosed = $DB->fieldExists($table, 'date_closed');
        $where = EntityPerimeter::criteria($table);
        if ($filters['entities_id'] > 0) {
            $where[] = ["$table.entities_id" => $filters['entities_id']];
        }
        $statuses = Nonconformity::getStatuses();
        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => array_merge([
                    "$table.id", "$table.title", "$table.finding_type", "$table.severity", "$table.status",
                    "$table.users_id", "$table.due_date", "$table.entities_id", "$table.date_creation",
                    "$table.date_mod", "$table.closure_verification_date", "$table.plugin_grcmanager_audits_id",
                    "$table.plugin_grcmanager_securityincidents_id", "$audits.audit_type",
                ], $hasClosed ? ["$table.date_closed"] : []),
                'FROM'      => $table,
                'LEFT JOIN' => [$audits => ['FKEY' => [$table => 'plugin_grcmanager_audits_id', $audits => 'id']]],
                'WHERE'     => $where,
                'ORDER'     => "$table.id ASC",
            ]) as $row
        ) {
            $status = (string) $row['status'];
            $closed = null;
            if (in_array($status, Nonconformity::CLOSED_STATUSES, true)) {
                $closed = substr((string) (($row['date_closed'] ?? null) ?: ($row['closure_verification_date']
                    ?: ($row['date_mod'] ?: $today))), 0, 10);
            }
            $rows[] = [
                'kind'        => Rules::kindOf((string) $row['finding_type']),
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'severity'    => (string) $row['severity'],
                'origin'      => Rules::origin(
                    (int) $row['plugin_grcmanager_audits_id'],
                    (string) ($row['audit_type'] ?? ''),
                    (int) $row['plugin_grcmanager_securityincidents_id']
                ),
                'entities_id' => (int) $row['entities_id'],
                'users_id'    => (int) $row['users_id'],
                'status'      => (string) ($statuses[$status] ?? $status),
                'created'     => substr((string) ($row['date_creation'] ?: ($row['date_mod'] ?: $today)), 0, 10),
                'due'         => $row['due_date'] ?: null,
                'closed'      => $closed,
                'verified'    => $status === 'verified' || !empty($row['closure_verification_date']),
                'url'         => Nonconformity::getFormURLWithID((int) $row['id']),
            ];
        }

        return $rows;
    }

    /**
     * Actions décidées en revue de direction (entité de leur revue).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function reviewActionRows(array $filters, string $today): array
    {
        global $DB;

        $table = ManagementReviewAction::getTable();
        $reviews = ManagementReview::getTable();
        if (!$DB->tableExists($table)) {
            return [];
        }
        $where = EntityPerimeter::criteria($reviews);
        if ($filters['entities_id'] > 0) {
            $where[] = ["$reviews.entities_id" => $filters['entities_id']];
        }
        $statuses = ManagementReviewAction::getStatuses();
        $rows = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    "$table.id", "$table.description", "$table.users_id", "$table.due_date", "$table.status",
                    "$table.completion_date", "$table.date_creation", "$table.date_mod", "$reviews.entities_id",
                    "$reviews.id AS review_id",
                ],
                'FROM'       => $table,
                'INNER JOIN' => [
                    $reviews => ['FKEY' => [$table => 'plugin_grcmanager_managementreviews_id', $reviews => 'id']],
                ],
                'WHERE'      => $where,
                'ORDER'      => "$table.id ASC",
            ]) as $row
        ) {
            $status = (string) $row['status'];
            $closed = $status === 'done'
                ? substr((string) ($row['completion_date'] ?: ($row['date_mod'] ?: $today)), 0, 10) : null;
            $rows[] = [
                'kind'        => Rules::KIND_REVIEW_ACTION,
                'id'          => (int) $row['id'],
                'title'       => mb_substr(trim((string) $row['description']), 0, 160),
                'severity'    => '',
                'origin'      => Rules::ORIGIN_MANAGEMENT_REVIEW,
                'entities_id' => (int) $row['entities_id'],
                'users_id'    => (int) $row['users_id'],
                'status'      => (string) ($statuses[$status] ?? $status),
                'created'     => substr((string) ($row['date_creation'] ?: ($row['date_mod'] ?: $today)), 0, 10),
                'due'         => $row['due_date'] ?: null,
                'closed'      => $closed,
                'verified'    => false,
                'url'         => ManagementReview::getFormURLWithID((int) $row['review_id']),
            ];
        }

        return $rows;
    }

    /**
     * Rapport complet : synthèse, libellés et écarts ouverts détaillés (les plus en retard d'abord).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function report(array $filters, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $rows = self::rows($filters, $today);
        $summary = Rules::summarize($rows, $filters['from'], $filters['to'], $today);
        $open = [];
        foreach ($rows as $row) {
            if ($row['closed'] !== null) {
                continue;
            }
            $open[] = self::decorate($row, $today);
        }
        usort($open, static fn (array $a, array $b): int => [$b['overdue_days'], $b['age']]
            <=> [$a['overdue_days'], $a['age']]);
        $severities = ['' => '-'] + Nonconformity::getSeverities();
        $entities = [];
        foreach (array_keys($summary['open']['by_entity']) as $id) {
            $entities[$id] = Dropdown::getDropdownName('glpi_entities', (int) $id);
        }
        $owners = [];
        foreach (array_keys($summary['open']['by_owner']) as $id) {
            $owners[$id] = (int) $id > 0 ? (string) getUserName((int) $id) : __('No owner', 'grcmanager');
        }
        $maxStock = 1;
        foreach ($summary['trend'] as $point) {
            $maxStock = max($maxStock, $point['stock'], $point['opened'], $point['closed']);
        }

        return [
            'filters'        => $filters,
            'summary'        => $summary,
            'open_rows'      => array_slice($open, 0, self::MAX_ROWS),
            'open_truncated' => count($open) > self::MAX_ROWS,
            'labels'         => [
                'kinds'      => self::kindLabels(),
                'origins'    => self::originLabels(),
                'severities' => $severities,
                'ages'       => self::ageLabels(),
                'entities'   => $entities,
                'owners'     => $owners,
            ],
            'max_stock'      => $maxStock,
            'generated'      => Html::convDateTime(date('Y-m-d H:i:s')),
            'period_label'   => sprintf(
                __('From %1$s to %2$s', 'grcmanager'),
                $filters['from'],
                $filters['to']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row, string $today): array
    {
        $severities = Nonconformity::getSeverities();
        $kinds = self::kindLabels();
        $origins = self::originLabels();

        return $row + [
            'kind_label'     => $kinds[$row['kind']] ?? $row['kind'],
            'origin_label'   => $origins[$row['origin']] ?? $row['origin'],
            'severity_label' => $severities[$row['severity']] ?? '-',
            'entity'         => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            'owner'          => $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
            'age'            => Rules::days((string) $row['created'], $today),
            'overdue_days'   => Rules::overdueDays($row, $today),
            'created_label'  => Html::convDate((string) $row['created']),
            'due_label'      => $row['due'] !== null ? Html::convDate((string) $row['due']) : '',
            'closed_label'   => $row['closed'] !== null ? Html::convDate((string) $row['closed']) : '',
        ];
    }

    // ------------------------------------------------------------------ Affichage et exports.

    /**
     * @param array<string, mixed> $filters
     */
    public static function showPage(array $filters): void
    {
        $data = self::report($filters);
        $query = http_build_query(array_filter([
            'from' => $filters['from'], 'to' => $filters['to'], 'kind' => $filters['kind'],
            'severity' => $filters['severity'], 'origin' => $filters['origin'],
            'users_id' => $filters['users_id'] ?: '', 'entities_id' => $filters['entities_id'] ?: '',
        ], static fn ($v): bool => $v !== ''));
        TemplateRenderer::getInstance()->display('@grcmanager/gap_stock.html.twig', $data + [
            'url'          => self::getSearchURL(),
            'export_query' => $query,
            'can_archive'  => self::canArchive(),
            'entity_options' => self::entityOptions(),
            'clauses'      => implode(', ', self::CLAUSES),
        ]);
    }

    /**
     * Entités actives de la session, pour le filtre (vide avec une seule entité).
     *
     * @return array<int, string>
     */
    private static function entityOptions(): array
    {
        $ids = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        if (count($ids) < 2) {
            return [];
        }
        $options = [];
        foreach ($ids as $id) {
            $options[$id] = Dropdown::getDropdownName('glpi_entities', $id);
        }
        asort($options);

        return $options;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function renderPdf(array $filters): string
    {
        return TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/gap_stock_report.html.twig',
            self::report($filters) + [
                'clauses'            => implode(', ', self::CLAUSES),
                'report_title'       => self::getTypeName(),
                'generated_at_label' => sprintf(
                    __('Generated on %s', 'grcmanager'),
                    Html::convDateTime(date('Y-m-d H:i:s'))
                ),
                'perimeter'          => Dropdown::getDropdownName(
                    'glpi_entities',
                    $filters['entities_id'] > 0 ? $filters['entities_id'] : (int) Session::getActiveEntity()
                ),
            ]
        );
    }

    /**
     * Lignes CSV : écarts ouverts et écarts clôturés dans la période, avec leurs dates.
     *
     * @param array<string, mixed> $filters
     * @return list<list<string>>
     */
    public static function csvRows(array $filters, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $periodStart = $filters['from'] . '-01';
        $lines = [[
            __('Type', 'grcmanager'), 'ID', __('Title', 'grcmanager'), __('Origin', 'grcmanager'),
            __('Severity', 'grcmanager'), __('Entity'), __('Owner', 'grcmanager'), __('Status'),
            __('Opened on', 'grcmanager'), __('Due date', 'grcmanager'), __('Closed on', 'grcmanager'),
            __('Age (days)', 'grcmanager'), __('Days overdue', 'grcmanager'),
            __('Effectiveness verified', 'grcmanager'),
        ]];
        foreach (self::rows($filters, $today) as $row) {
            if ($row['closed'] !== null && $row['closed'] < $periodStart) {
                continue;
            }
            $row = self::decorate($row, $today);
            $end = $row['closed'] ?? $today;
            $lines[] = array_map([Rules::class, 'csvCell'], [
                (string) $row['kind_label'], (string) $row['id'], (string) $row['title'],
                (string) $row['origin_label'], (string) $row['severity_label'], (string) $row['entity'],
                (string) $row['owner'], (string) $row['status'], (string) $row['created'],
                (string) ($row['due'] ?? ''), (string) ($row['closed'] ?? ''),
                (string) Rules::days((string) $row['created'], $end), (string) $row['overdue_days'],
                $row['kind'] === Rules::KIND_REVIEW_ACTION ? '' : ($row['verified'] ? __('Yes') : __('No')),
            ]);
        }

        return $lines;
    }

    /**
     * Archive le PDF dans l'entité active, comme preuve des exigences 10.1 et 10.2 (onglet
     * Documents) : le dossier de preuves de l'auditeur le reprend. Document lisible seulement avec
     * la lecture de ces exigences (RestrictedDocument). Renvoie l'identifiant du document, 0 sinon.
     *
     * @param array<string, mixed> $filters
     */
    public static function archive(array $filters): int
    {
        if (!self::canArchive()) {
            return 0;
        }
        try {
            $pdf = PdfRenderer::renderHtmlToPdf(self::renderPdf($filters));
        } catch (\Throwable $e) {
            return 0;
        }
        $fileName = sprintf('stock-ecarts-%s.pdf', date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }
        $entityId = (int) Session::getActiveEntity();
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('Gap backlog report - %1$s (%2$s to %3$s)', 'grcmanager'),
                Html::convDate(date('Y-m-d')),
                $filters['from'],
                $filters['to']
            ),
            // Noms des responsables d'une seule entité : pas de récursivité.
            'entities_id'   => $entityId,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        if ($documentsId > 0) {
            $source = 0;
            foreach (self::CLAUSES as $code) {
                $requirement = new IsmsRequirement();
                if (!$requirement->getFromDBByCrit(['code' => $code])) {
                    continue;
                }
                $source = $source ?: (int) $requirement->getID();
                (new Document_Item())->add([
                    'documents_id' => $documentsId,
                    'itemtype'     => IsmsRequirement::class,
                    'items_id'     => (int) $requirement->getID(),
                ]);
            }
            if ($source > 0) {
                RestrictedDocument::registerFor($documentsId, IsmsRequirement::class, $source);
            }
            AuditLog::record('export', self::class, $documentsId, 0, 'success', [
                'entities_id' => $entityId,
                'from'        => $filters['from'],
                'to'          => $filters['to'],
            ]);
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

        return $documentsId;
    }

    // ------------------------------------------------------------------ Preuve et tableau de bord.

    /**
     * Cadre de preuve des exigences 10.1 et 10.2 (et de leur fiche) : null pour un autre code ou
     * sans droit.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CLAUSES, true) || !self::canView()) {
            return null;
        }
        $summary = Rules::summarize(self::rows(self::filters([])), ...self::defaultPeriod());

        return [
            'open'          => $summary['open']['total'],
            'overdue'       => $summary['open']['overdue'],
            'mean_closure'  => $summary['mean_closure'],
            'verified_rate' => $summary['verified_rate'],
            'closed'        => $summary['closed_in_period'],
            'date'          => Html::convDate(date('Y-m-d')),
            'url'           => self::getSearchURL(),
        ];
    }

    /** @return array{0: string, 1: string, 2: string} début, fin, aujourd'hui */
    private static function defaultPeriod(): array
    {
        $today = date('Y-m-d');
        $period = Rules::period(null, null, $today);

        return [$period['from'], $period['to'], $today];
    }

    /**
     * Carte « Délai moyen de clôture des écarts (jours, 12 mois) » ; vide sans droit.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $mean = null;
        if (self::canView()) {
            $mean = Rules::summarize(self::rows(self::filters([])), ...self::defaultPeriod())['mean_closure'];
        }

        return [
            'number' => $mean ?? 0,
            'url'    => self::canView() ? self::getSearchURL() : '',
            'label'  => $params['label'] ?? __('Mean time to close gaps (days, 12 months)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }
}
