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
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards as Cards;
use GlpiPlugin\Grcmanager\Services\Review\ManagementReviewDossierRules as Rules;
use Html;
use Session;
use User;

/**
 * Issue #542 : dossier préparatoire de la revue de direction (ISO 27001, 9.3.2), généré depuis la
 * fiche de la revue. Chaque entrée que la direction doit examiner devient une section du PDF, sur
 * une période choisie (par défaut depuis la revue précédente), à partir des données que le plugin
 * connaît déjà : actions des revues précédentes, contexte, non-conformités, mesures et incidents,
 * audits, objectifs, risques et SoA, maturité et écarts de cohérence.
 *
 * Les chiffres viennent des fournisseurs du tableau de bord (SecuredDashboardCards : mêmes
 * définitions et mêmes droits que les cartes) ; seuls les décomptes « sur la période » sont propres
 * au dossier. Une section dont la personne qui génère n'a pas le droit de lire le registre est
 * signalée comme non incluse, jamais remplie.
 *
 * Le PDF est archivé comme Document joint à la revue (registre des documents restreints : lisible
 * par qui peut lire la revue), avec sa période et son empreinte SHA-256, pour servir de preuve.
 * Rien n'est modifié dans les registres : le dossier ne fait que lire.
 */
class ManagementReviewDossier extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_managementreviewdossiers';

    public static function getTypeName($nb = 0)
    {
        return _n('Preparatory file', 'Preparatory files', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-analytics';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/managementreview.dossier.php';
    }

    public static function canView(): bool
    {
        return ManagementReview::canView();
    }

    /**
     * Titre et résumé de chaque section, avec nos mots (pas le texte de la norme).
     *
     * @return array<string, array{title: string, summary: string}>
     */
    public static function getSectionLabels(): array
    {
        return [
            'previous_actions' => [
                'title'   => __('Actions from previous reviews', 'grcmanager'),
                'summary' => __('Where the actions decided at earlier management reviews stand today.', 'grcmanager'),
            ],
            'context'          => [
                'title'   => __('Context and interested parties', 'grcmanager'),
                'summary' => __('Internal and external issues and interested parties: what changed during the period and what is overdue for review.', 'grcmanager'),
            ],
            'nonconformities'  => [
                'title'   => __('Nonconformities and corrective actions', 'grcmanager'),
                'summary' => __('Findings raised and closed during the period, and corrective actions still open or late.', 'grcmanager'),
            ],
            'measurement'      => [
                'title'   => __('Monitoring, measurement and incidents', 'grcmanager'),
                'summary' => __('Key risk indicators and information security incidents over the period.', 'grcmanager'),
            ],
            'audits'           => [
                'title'   => __('Audit results', 'grcmanager'),
                'summary' => __('Internal audits completed during the period and what they found.', 'grcmanager'),
            ],
            'objectives'       => [
                'title'   => __('Information security objectives', 'grcmanager'),
                'summary' => __('Status of each objective and its latest measurement in the period.', 'grcmanager'),
            ],
            'risks'            => [
                'title'   => __('Risk assessment and treatment', 'grcmanager'),
                'summary' => __('Open risks by level, risks beyond appetite, and progress of the treatment plan.', 'grcmanager'),
            ],
            'soa'              => [
                'title'   => __('Statement of Applicability', 'grcmanager'),
                'summary' => __('Implementation of the applicable controls and the latest approved version of the SoA.', 'grcmanager'),
            ],
            'improvement'      => [
                'title'   => __('Opportunities for improvement', 'grcmanager'),
                'summary' => __('Maturity score over the period and consistency gaps still to fix.', 'grcmanager'),
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof ManagementReview || $item->isNewItem() || !ManagementReview::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(1),
            countElementsInTable(self::TABLE, ['plugin_grcmanager_managementreviews_id' => $item->getID()]),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof ManagementReview || $item->isNewItem()) {
            return false;
        }
        $reviewId = (int) $item->getID();
        $canEdit = $item->can($reviewId, UPDATE);
        $actions = ManagementReviewAction::getForReview($reviewId);
        $period = Rules::defaultPeriod($item->previousReviewDate(), $item->fields['review_date'] ?? null, date('Y-m-d'));
        $labels = self::getSectionLabels();
        $sections = [];
        foreach (Rules::SECTIONS as $key => [$clause, $icon]) {
            $sections[] = ['clause' => $clause, 'icon' => $icon] + $labels[$key];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/management_review_dossier_tab.html.twig', [
            'review_id'       => $reviewId,
            'can_edit'        => $canEdit,
            'dossier_action'  => self::getFormURL(),
            'action_url'      => ManagementReviewAction::getFormURL(),
            'period'          => $period,
            'sections'        => $sections,
            'dossiers'        => self::history($reviewId),
            'actions'         => $actions,
            'summary'         => Rules::actionSummary($actions, date('Y-m-d')),
            'statuses'        => ManagementReviewAction::getStatuses(),
            'new_decisions'   => count(Rules::newDecisionLines(
                Rules::decisionLines((string) ($item->fields['decisions'] ?? '')),
                array_column($actions, 'description')
            )),
            'user_dropdown'   => $canEdit ? User::dropdown([
                'name'    => 'users_id',
                'right'   => 'all',
                'display' => false,
                'width'   => '100%',
            ]) : '',
        ]);

        return true;
    }

    /**
     * Dossiers déjà générés pour la revue, le plus récent d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(int $reviewId): array
    {
        global $DB, $CFG_GLPI;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_managementreviews_id' => $reviewId],
                'ORDER' => 'id DESC',
            ]) as $row
        ) {
            $documentsId = (int) $row['documents_id'];
            $document = new Document();
            $exists = $documentsId > 0 && $document->getFromDB($documentsId);
            $rows[] = [
                'id'           => (int) $row['id'],
                'date'         => (string) $row['date_creation'],
                'period_start' => (string) $row['period_start'],
                'period_end'   => (string) $row['period_end'],
                'author'       => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'sha256'       => (string) $row['sha256'],
                'filename'     => $exists ? (string) $document->fields['filename'] : '',
                'url'          => $exists
                    ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documentsId
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * Données du dossier, section par section, avec les droits et les entités de la session.
     *
     * @return array<string, mixed>
     */
    public static function collect(ManagementReview $review, string $start, string $end): array
    {
        $labels = self::getSectionLabels();
        $builders = [
            'previous_actions' => static fn (): ?array => self::previousActions($review, $start, $end),
            'context'          => static fn (): ?array => self::context($start),
            'nonconformities'  => static fn (): ?array => self::nonconformities($start, $end),
            'measurement'      => static fn (): ?array => self::measurement($start, $end),
            'audits'           => static fn (): ?array => self::audits($start, $end),
            'objectives'       => static fn (): ?array => self::objectives($review, $start, $end),
            'risks'            => static fn (): ?array => self::risks($start, $end),
            'soa'              => static fn (): ?array => self::soa(),
            'improvement'      => static fn (): ?array => self::improvement($start, $end),
        ];
        $sections = [];
        foreach (Rules::SECTIONS as $key => [$clause, $icon]) {
            $data = $builders[$key]();
            $sections[] = [
                'key'      => $key,
                'clause'   => $clause,
                'icon'     => $icon,
                'title'    => $labels[$key]['title'],
                'summary'  => $labels[$key]['summary'],
                'included' => $data !== null,
                'data'     => $data ?? [],
            ];
        }
        $entityId = EntityPerimeter::activeEntity();

        return [
            'review'    => [
                'title'     => (string) $review->fields['title'],
                'date'      => Html::convDate($review->fields['review_date'] ?? null),
                'status'    => ManagementReview::getStatuses()[$review->fields['status'] ?? ''] ?? '',
                'attendees' => array_values(array_map(
                    static fn (int $id): string => getUserName($id),
                    array_keys(ManagementReview::getAttendees((int) $review->getID()))
                )),
                'agenda'    => (string) ($review->fields['agenda'] ?? ''),
            ],
            'period'    => ['start' => Html::convDate($start), 'end' => Html::convDate($end)],
            'perimeter' => Dropdown::getDropdownName(Entity::getTable(), $entityId)
                . (($_SESSION['glpiactive_entity_recursive'] ?? false) ? ' ' . __('(and sub-entities)', 'grcmanager') : ''),
            'author'    => getUserName((int) Session::getLoginUserID()),
            'sections'  => $sections,
        ];
    }

    /**
     * 9.3.2 a : actions décidées lors des revues précédentes.
     *
     * @return array<string, mixed>|null
     */
    private static function previousActions(ManagementReview $review, string $start, string $end): ?array
    {
        if (!ManagementReview::canView()) {
            return null;
        }
        $actions = ManagementReviewAction::fromPreviousReviews($review);
        // Les actions encore ouvertes, et celles terminées pendant la période.
        $listed = array_values(array_filter(
            $actions,
            static fn (array $a): bool => $a['status'] !== 'done' || Rules::inPeriod($a['completion_date'], $start, $end)
        ));

        return [
            'summary' => Rules::actionSummary($actions, date('Y-m-d')),
            'actions' => array_map(static fn (array $a): array => $a + [
                'due_label'        => $a['due_date'] ? Html::convDate($a['due_date']) : '',
                'completion_label' => $a['completion_date'] ? Html::convDate($a['completion_date']) : '',
            ], $listed),
        ];
    }

    /**
     * 9.3.2 b, c et e : enjeux et parties intéressées (ContextItem::summary()).
     *
     * @return array<string, mixed>|null
     */
    private static function context(string $start): ?array
    {
        return ContextItem::canView() ? ContextItem::summary($start) : null;
    }

    /**
     * 9.3.2 d 1 : non-conformités et actions correctives.
     *
     * @return array<string, mixed>|null
     */
    private static function nonconformities(string $start, string $end): ?array
    {
        if (!Nonconformity::canView()) {
            return null;
        }
        $table = Nonconformity::getTable();
        $scope = EntityPerimeter::criteria($table);
        $raised = [];
        foreach (Nonconformity::getFindingTypes() as $type => $label) {
            $raised[] = ['label' => $label, 'number' => countElementsInTable($table, array_merge([
                'finding_type'  => $type,
                'date_creation' => ['>=', $start . ' 00:00:00'],
            ], [['date_creation' => ['<=', $end . ' 23:59:59']]], $scope))];
        }

        return [
            'raised'   => $raised,
            'verified' => countElementsInTable($table, array_merge([
                'status'                    => 'verified',
                'closure_verification_date' => ['>=', $start],
            ], [['closure_verification_date' => ['<=', $end]]], $scope)),
            'open'     => (int) Cards::openNonconformitiesCount()['number'],
            'overdue'  => (int) Cards::overdueCapaCount()['number'],
            'trend'    => self::trendFromSnapshots(1, $start, $end),
        ];
    }

    /**
     * 9.3.2 d 2 : indicateurs de risque (KRI) et incidents de sécurité de la période.
     *
     * @return array<string, mixed>|null
     */
    private static function measurement(string $start, string $end): ?array
    {
        $kri = Cards::canShow('riskIndicatorsByStatus') ? Cards::riskIndicatorsByStatus()['data'] : null;
        $incidents = null;
        if (Cards::canShow('incidentsTotal')) {
            $params = ['apply_filters' => ['dates' => [$start, $end]]];
            $incidents = [
                'total'       => (int) Cards::incidentsTotal($params)['number'],
                'by_severity' => array_values(array_filter(
                    (array) Cards::bySeverity($params)['data'],
                    static fn (mixed $row): bool => is_array($row) && isset($row['label'], $row['number'])
                )),
                'open_now'    => (int) Cards::open()['number'],
            ];
        }
        if ($kri === null && $incidents === null) {
            return null;
        }

        return ['kri' => $kri, 'incidents' => $incidents];
    }

    /**
     * 9.3.2 d 3 : audits internes terminés pendant la période et leurs constats.
     *
     * @return array<string, mixed>|null
     */
    private static function audits(string $start, string $end): ?array
    {
        global $DB;

        if (!Audit::canView()) {
            return null;
        }
        $completed = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title', 'actual_date', 'conclusion'],
                'FROM'   => Audit::getTable(),
                'WHERE'  => array_merge([
                    'status'      => 'completed',
                    'actual_date' => ['>=', $start],
                ], [['actual_date' => ['<=', $end]]], EntityPerimeter::criteria(Audit::getTable())),
                'ORDER'  => 'actual_date ASC',
            ]) as $row
        ) {
            $findings = Audit::getFindings((int) $row['id']);
            $completed[] = [
                'title'      => (string) $row['title'],
                'date'       => Html::convDate($row['actual_date']),
                'conclusion' => (string) ($row['conclusion'] ?? ''),
                'findings'   => count($findings),
                'by_type'    => array_count_values(array_column($findings, 'type')),
            ];
        }
        $statuses = Audit::getStatuses();

        return [
            'completed' => $completed,
            'by_status' => array_map(static fn (array $row): array => [
                'label'  => $statuses[$row['label']] ?? (string) $row['label'],
                'number' => (int) $row['number'],
            ], (array) Cards::auditsByStatus()['data']),
        ];
    }

    /**
     * 9.3.2 d 4 : objectifs de sécurité, statut et dernière mesure de la période.
     *
     * @return array<string, mixed>|null
     */
    private static function objectives(ManagementReview $review, string $start, string $end): ?array
    {
        global $DB;

        if (!Objective::canView()) {
            return null;
        }
        $statuses = Objective::getStatuses();
        $discussed = ManagementReview::getLinkedObjectiveIds((int) $review->getID());
        $objectives = [];
        foreach ($DB->request(['FROM' => Objective::getTable(), 'ORDER' => 'title ASC']) as $row) {
            $inPeriod = array_values(array_filter(
                ObjectiveMeasurement::getMeasurements((int) $row['id']),
                static fn (array $m): bool => Rules::inPeriod($m['measurement_date'] ?? null, $start, $end)
            ));
            $last = $inPeriod === [] ? null : $inPeriod[count($inPeriod) - 1];
            $objectives[] = [
                'title'        => (string) $row['title'],
                'status'       => (string) $row['status'],
                'status_label' => $statuses[$row['status']] ?? (string) $row['status'],
                'target'       => $row['target_value'] !== null
                    ? (string) (float) $row['target_value']
                    : (string) ($row['target_description'] ?? ''),
                'target_date'  => Html::convDate($row['target_date']),
                'measurements' => count($inPeriod),
                'last_value'   => $last !== null && $last['value'] !== null ? (string) (float) $last['value'] : '',
                'last_date'    => $last !== null ? Html::convDate($last['measurement_date']) : '',
                'discussed'    => in_array((int) $row['id'], $discussed, true),
            ];
        }

        return [
            'objectives' => $objectives,
            'by_status'  => array_map(static fn (array $row): array => [
                'label'  => $statuses[$row['label']] ?? (string) $row['label'],
                'number' => (int) $row['number'],
            ], (array) Cards::objectivesByStatus()['data']),
        ];
    }

    /**
     * 9.3.2 f : résultats de l'appréciation des risques et avancement du plan de traitement.
     *
     * @return array<string, mixed>|null
     */
    private static function risks(string $start, string $end): ?array
    {
        if (!Cards::canShow('openRisksCount') || !Risk::canView()) {
            return null;
        }
        $levels = Risk::getRiskLevels();
        $table = Risk::getTable();

        return [
            'open'             => (int) Cards::openRisksCount()['number'],
            'by_level'         => array_map(static fn (array $row): array => [
                'key'    => (string) $row['label'],
                'label'  => $levels[$row['label']] ?? (string) $row['label'],
                'number' => (int) $row['number'],
            ], (array) Cards::risksByLevel()['data']),
            'identified'       => countElementsInTable($table, array_merge(
                ['date_creation' => ['>=', $start . ' 00:00:00']],
                [['date_creation' => ['<=', $end . ' 23:59:59']]],
                EntityPerimeter::criteria($table)
            )),
            'above_appetite'   => (int) Cards::risksAboveAppetiteCount()['number'],
            'above_tolerance'  => (int) Cards::risksAboveToleranceCount()['number'],
            'overdue_actions'  => (int) Cards::overdueTreatmentActionsCount()['number'],
            'without_plan'     => (int) Cards::risksMissingTreatmentPlanCount()['number'],
            'trend'            => self::trendFromSnapshots(0, $start, $end),
        ];
    }

    /**
     * 9.3.2 f : état de la SoA et dernière version approuvée.
     *
     * @return array<string, mixed>|null
     */
    private static function soa(): ?array
    {
        if (!Control::canView()) {
            return null;
        }
        $statuses = Control::getImplementationStatuses();
        $approved = SoaVersion::latest('approved');

        return [
            'by_status' => array_map(static fn (array $row): array => [
                'label'  => $statuses[$row['label']] ?? (string) $row['label'],
                'number' => (int) $row['number'],
            ], (array) Cards::soaByImplementationStatus()['data']),
            'approved'  => $approved === null ? null : [
                'version' => (int) $approved['version'],
                'date'    => Html::convDateTime($approved['date_answered'] ?? $approved['date_mod'] ?? null),
                'changed' => (string) $approved['fingerprint'] !== SoaVersion::currentFingerprint(),
            ],
        ];
    }

    /**
     * 9.3.2 g : score de maturité sur la période et écarts de cohérence à corriger.
     *
     * @return array<string, mixed>|null
     */
    private static function improvement(string $start, string $end): ?array
    {
        if (!Maturity::canView()) {
            return null;
        }
        $current = Maturity::current();
        $history = [];
        foreach (Maturity::history() as $row) {
            $history[$row['month']] = $row['score'];
        }
        $history = Rules::monthsInPeriod($history, $start, $end);
        $first = $history === [] ? null : (int) reset($history);
        $consistency = null;
        if (ConsistencyCheck::canView()) {
            $summary = ConsistencyCheck::summarize(ConsistencyCheck::run());
            $consistency = ['gaps' => $summary['gaps'], 'score' => $summary['score']];
        }

        return [
            'score'       => $current['score'],
            'level'       => Maturity::getLevelLabels()[$current['level']] ?? '',
            'history'     => $history,
            'trend'       => Rules::trend($first, $current['score']),
            'consistency' => $consistency,
        ];
    }

    /**
     * Tendance d'un indicateur mensuel du tableau de bord (0 : risques ouverts, 1 : non-conformités
     * ouvertes), du premier mois enregistré de la période au dernier. Null sans le droit de lire
     * les tendances ou sans relevé.
     *
     * @return array{first_month: string, first: int, last_month: string, last: int,
     *     direction: string, delta: int}|null
     */
    private static function trendFromSnapshots(int $series, string $start, string $end): ?array
    {
        if (!Cards::canShow('monthlyTrend')) {
            return null;
        }
        $data = (array) (Cards::monthlyTrend()['data'] ?? []);
        $values = [];
        foreach ((array) ($data['labels'] ?? []) as $i => $month) {
            if (isset($data['series'][$series]['data'][$i])) {
                $values[(string) $month] = (int) $data['series'][$series]['data'][$i];
            }
        }
        $values = Rules::monthsInPeriod($values, $start, $end);
        if (count($values) < 2) {
            return null;
        }
        $months = array_keys($values);
        $firstMonth = $months[0];
        $lastMonth = $months[count($months) - 1];
        $trend = Rules::trend($values[$firstMonth], $values[$lastMonth]);

        return $trend === null ? null : [
            'first_month' => $firstMonth,
            'first'       => $values[$firstMonth],
            'last_month'  => $lastMonth,
            'last'        => $values[$lastMonth],
        ] + $trend;
    }

    public static function render(ManagementReview $review, string $start, string $end): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/management_review_dossier.html.twig', [
            // Titre court : l'en-tête fixe du PDF n'a la place que d'une ligne (titre de la revue dans le corps).
            'report_title'       => __('Management review preparatory file', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
        ] + self::collect($review, $start, $end));
    }

    /**
     * Génère le dossier, l'archive sur la revue et enregistre sa période et son empreinte. Renvoie
     * l'identifiant du dossier, 0 en cas d'échec.
     */
    public static function generate(ManagementReview $review, string $start, string $end): int
    {
        global $DB;

        $period = Rules::normalizePeriod($start, $end);
        if ($period === null || $review->isNewItem()) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $pdf = PdfRenderer::renderHtmlToPdf(self::render($review, $period['start'], $period['end']));
        $fileName = Rules::fileName((int) $review->getID(), $now);
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('Management review preparatory file - %1$s (%2$s to %3$s)', 'grcmanager'),
                $review->fields['title'],
                Html::convDate($period['start']),
                Html::convDate($period['end'])
            ),
            // La revue de direction couvre tout le SMSI : document à la racine, lisible seulement
            // par qui peut lire la revue (RestrictedDocument).
            'entities_id'   => 0,
            'is_recursive'  => 1,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        (new Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => ManagementReview::class,
            'items_id'     => (int) $review->getID(),
        ]);
        RestrictedDocument::register($documentsId, $review);

        $DB->insert(self::TABLE, [
            'plugin_grcmanager_managementreviews_id' => (int) $review->getID(),
            'documents_id'                           => $documentsId,
            'sha256'                                 => hash('sha256', $pdf),
            'period_start'                           => $period['start'],
            'period_end'                             => $period['end'],
            'users_id'                               => (int) Session::getLoginUserID(),
            'date_creation'                          => $now,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * Purge d'une revue : l'historique des dossiers part avec elle (les Documents restent, comme
     * tout document GLPI détaché de son élément).
     */
    public static function purgeForReview(int $reviewId): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['plugin_grcmanager_managementreviews_id' => $reviewId]);
    }
}
