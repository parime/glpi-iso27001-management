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

namespace GlpiPlugin\Grcmanager\Services\Workspace;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\DataSubjectRequest;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Objective;
use GlpiPlugin\Grcmanager\PeriodicReview;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;
use GlpiPlugin\Grcmanager\Services\Objective\ObjectiveStatuses;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Privacy\DataSubjectRequestRules;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use GlpiPlugin\Grcmanager\SoaVersion;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\Training;
use GlpiPlugin\Grcmanager\TrainingQuiz;
use Html;
use Session;

/**
 * Issue #539 : lecture, pour une personne, de tout ce qui l'attend dans le plugin (« Mon espace
 * GRC »). Chaque source n'est lue que si la personne peut voir les éléments concernés : droit de
 * lecture de l'objet (droit dédié pour les registres sensibles), entités de la session pour les
 * objets rattachés à une entité. Trois sources ne demandent aucun droit sur le plugin, comme les
 * pages qu'elles ouvrent : les lignes de revue des accès confiées à la personne (« Mes revues
 * d'accès »), ses formations (« Mes formations »), les politiques qu'elle doit lire (« Politiques à
 * lire ») et les versions de la SoA qu'elle doit approuver.
 *
 * Le tri, le filtrage et les retards sont calculés par MySpaceRules (PHP pur, testé seul).
 */
final class MySpaceProvider
{
    /**
     * @return list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail: string, urgency: string, days_late: int}>
     */
    public static function items(int $usersId, string $today): array
    {
        if ($usersId <= 0) {
            return [];
        }

        return MySpaceRules::build(array_merge(
            self::risks($usersId),
            self::treatmentActions($usersId),
            self::controlTests($usersId),
            self::evidences($usersId),
            self::nonconformities($usersId),
            self::periodicReviews($usersId),
            self::accessReviews($usersId),
            self::policies($usersId),
            self::policyAcknowledgements($usersId),
            self::trainings($usersId),
            self::supplierRisks($usersId),
            self::obligations($usersId),
            self::soaApprovals($usersId),
            self::dataSubjectRequests($usersId),
            self::audits($usersId),
            self::objectives($usersId),
            self::events($usersId)
        ), $today);
    }

    /**
     * Carte « Mes tâches GRC » : nombre d'éléments en attente, lien vers la page.
     *
     * @param array<string, mixed> $params
     * @return array{number: int, label: string, icon: string, url: string, alt: string}
     */
    public static function mySpaceCount(array $params = []): array
    {
        $items = self::items((int) Session::getLoginUserID(), date('Y-m-d'));
        $summary = MySpaceRules::summary($items);

        return [
            'number' => $summary['total'],
            'label'  => (string) ($params['label'] ?? __('My GRC to-dos', 'grcmanager')),
            'icon'   => MySpace::getIcon(),
            'url'    => MySpace::getSearchURL(),
            'alt'    => sprintf(__('%d overdue', 'grcmanager'), $summary['overdue']),
        ];
    }

    /**
     * Carte « Mon espace GRC » : les éléments en attente, les plus urgents d'abord.
     *
     * @param array<string, mixed> $params
     * @return array{data: list<array<string, string>>, label: string, icon: string, date: string}
     */
    public static function mySpaceList(array $params = []): array
    {
        $sources = MySpace::getSources();
        $data = [];
        foreach (self::items((int) Session::getLoginUserID(), date('Y-m-d')) as $item) {
            $due = $item['due'] !== null ? (string) Html::convDate($item['due']) : __('No due date', 'grcmanager');
            if ($item['urgency'] === MySpaceRules::URGENCY_OVERDUE) {
                $late = sprintf(__('%d day(s) late', 'grcmanager'), $item['days_late']);
                $due = sprintf(__('%1$s (%2$s)'), $due, $late);
            }
            $data[] = [
                'label'   => sprintf(__('%1$s: %2$s'), $sources[$item['source']][0] ?? $item['source'], $item['name']),
                'content' => \htmlescape($due),
                'author'  => '',
                'url'     => $item['url'],
            ];
        }

        return [
            'data'  => $data,
            'label' => (string) ($params['label'] ?? __('My GRC space', 'grcmanager')),
            'icon'  => MySpace::getIcon(),
            'date'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Restriction aux entités de la session d'un objet rattaché à une entité (aucune sinon).
     *
     * @param class-string<CommonDBTM> $class
     * @return array<int|string, mixed>
     */
    private static function entityCriteria(string $class): array
    {
        $item = new $class();

        return $item->isEntityAssign()
            ? getEntitiesRestrictCriteria($class::getTable(), '', '', $item->maybeRecursive())
            : [];
    }

    /**
     * @param array<int|string, mixed> $where
     * @return iterable<array<string, mixed>>
     */
    private static function rows(string $class, array $where): iterable
    {
        global $DB;

        /** @var class-string<CommonDBTM> $class */
        return $DB->request([
            'FROM'  => $class::getTable(),
            // Deux groupes combinés par AND : leurs clés (OR, numériques) ne se mélangent pas.
            'WHERE' => array_values(array_filter([$where, self::entityCriteria($class)])),
        ]);
    }

    /**
     * @return array{source: string, id: int, name: string, due: ?string, url: string, detail: string,
     *     open_task: bool}
     */
    private static function candidate(
        string $source,
        int $id,
        string $name,
        mixed $due,
        string $url,
        bool $openTask = false,
        string $detail = ''
    ): array {
        return [
            'source'    => $source,
            'id'        => $id,
            'name'      => $name,
            'due'       => MySpaceRules::normalizeDate($due),
            'url'       => $url,
            'detail'    => $detail,
            'open_task' => $openTask,
        ];
    }

    /** Texte libre ramené à une ligne courte. */
    private static function excerpt(string $text, int $length = 120): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($text))));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
    }

    /**
     * Risques dont la personne est propriétaire, à revoir.
     *
     * @return list<array<string, mixed>>
     */
    private static function risks(int $usersId): array
    {
        if (!Risk::canView()) {
            return [];
        }
        $list = [];
        $where = ['users_id' => $usersId, 'status' => ['<>', 'closed'], ['NOT' => ['review_date' => null]]];
        foreach (self::rows(Risk::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'risk_review',
                $id,
                (string) $row['title'],
                $row['review_date'],
                Risk::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * Actions de traitement non terminées confiées à la personne, avec le risque qu'elles traitent.
     *
     * @return list<array<string, mixed>>
     */
    private static function treatmentActions(int $usersId): array
    {
        global $DB;

        if (!RiskTreatmentAction::canView() || !Risk::canView()) {
            return [];
        }
        $list = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'actions.id', 'actions.description', 'actions.due_date', 'actions.plugin_grcmanager_risks_id',
                    'risks.title',
                ],
                'FROM'       => RiskTreatmentAction::getTable() . ' AS actions',
                'INNER JOIN' => [
                    Risk::getTable() . ' AS risks' => [
                        'FKEY' => ['actions' => 'plugin_grcmanager_risks_id', 'risks' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'actions.users_id' => $usersId,
                    'actions.status'   => ['<>', TreatmentPlanRules::STATUS_DONE],
                ],
            ]) as $row
        ) {
            $list[] = self::candidate(
                'treatment_action',
                (int) $row['id'],
                self::excerpt((string) $row['description']),
                $row['due_date'],
                Risk::getFormURLWithID((int) $row['plugin_grcmanager_risks_id']),
                true,
                sprintf(__('%1$s: %2$s'), Risk::getTypeName(1), (string) $row['title'])
            );
        }

        return $list;
    }

    /**
     * Tests d'efficacité des mesures dont la personne est testeuse (même échéancier que le
     * calendrier de conformité et les rappels).
     *
     * @return list<array<string, mixed>>
     */
    private static function controlTests(int $usersId): array
    {
        if (!ControlTest::canView()) {
            return [];
        }
        $list = [];
        foreach (ControlTest::getSchedule() as $entry) {
            if ((int) $entry['control']['users_id_tester'] !== $usersId) {
                continue;
            }
            $id = (int) $entry['control']['id'];
            $code = (string) $entry['control']['code'];
            $list[] = self::candidate(
                'control_test',
                $id,
                trim($code . ' ' . Control::getControlTitle($code)),
                $entry['next'],
                ControlTest::getControlTabURL($id)
            );
        }

        return $list;
    }

    /**
     * Preuves dont la personne est responsable, expirées ou à renouveler. Le lien ouvre l'élément
     * prouvé (mesure, fournisseur, audit, politique), que la personne peut lire.
     *
     * @return list<array<string, mixed>>
     */
    private static function evidences(int $usersId): array
    {
        if (!Evidence::canView()) {
            return [];
        }
        $list = [];
        foreach (self::rows(Evidence::class, ['users_id' => $usersId, ['NOT' => ['valid_until' => null]]]) as $row) {
            $evidence = new Evidence();
            if (!$evidence->getFromDB((int) $row['id']) || !$evidence->canViewItem()) {
                continue;
            }
            $parent = $evidence->getParent();
            if ($parent === null) {
                continue;
            }
            $list[] = self::candidate(
                'evidence',
                (int) $row['id'],
                (string) Dropdown::getDropdownName('glpi_documents', (int) $row['documents_id']),
                $row['valid_until'],
                $parent::getFormURLWithID($parent->getID()),
                false,
                sprintf(__('%1$s: %2$s'), $parent::getTypeName(1), $parent->getName())
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nonconformities(int $usersId): array
    {
        if (!Nonconformity::canView()) {
            return [];
        }
        $list = [];
        $where = ['users_id' => $usersId, 'status' => ['open', 'in_progress']];
        foreach (self::rows(Nonconformity::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'nonconformity',
                $id,
                (string) $row['title'],
                $row['due_date'],
                Nonconformity::getFormURLWithID($id),
                true,
                self::excerpt((string) $row['corrective_action'])
            );
        }

        return $list;
    }

    /**
     * Revues périodiques actives confiées à la personne : échéance = dernière revue (ou date
     * d'activation) + fréquence, comme le calcul du retard de l'écran des revues.
     *
     * @return list<array<string, mixed>>
     */
    private static function periodicReviews(int $usersId): array
    {
        if (!PeriodicReview::canView()) {
            return [];
        }
        $list = [];
        foreach (self::rows(PeriodicReview::class, ['users_id_assign' => $usersId, 'is_active' => 1]) as $row) {
            $months = (int) $row['frequency_months'];
            $due = PeriodicReviewCatalog::nextDueDate($row['last_review_date'] ?: null, $months)
                ?? PeriodicReviewCatalog::nextDueDate($row['date_activation'] ?: null, $months);
            $list[] = self::candidate(
                'periodic_review',
                (int) $row['id'],
                PeriodicReview::getLabel((string) $row['review_key']),
                $due,
                PeriodicReview::getSearchURL()
            );
        }

        return $list;
    }

    /**
     * Campagnes de revue des accès où il reste des comptes à décider : mêmes lignes que « Mes
     * revues d'accès » (AccessReview::getLinesForReviewer()), sans droit sur le plugin.
     *
     * @return list<array<string, mixed>>
     */
    private static function accessReviews(int $usersId): array
    {
        $list = [];
        foreach (MySpaceRules::accessReviewCampaigns(AccessReview::getLinesForReviewer($usersId)) as $campaign) {
            $list[] = self::candidate(
                'access_review',
                $campaign['id'],
                $campaign['name'],
                $campaign['due'],
                AccessReview::getMineURL(),
                true,
                sprintf(
                    __('%1$d of %2$d account(s) still to decide', 'grcmanager'),
                    $campaign['pending'],
                    $campaign['total']
                )
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function policies(int $usersId): array
    {
        if (!Policy::canView()) {
            return [];
        }
        $list = [];
        $where = [
            'users_id' => $usersId,
            'status'   => PolicyLifecycle::STATUS_APPROVED,
            ['NOT' => ['next_review_date' => null]],
        ];
        foreach (self::rows(Policy::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'policy_review',
                $id,
                (string) $row['title'],
                $row['next_review_date'],
                Policy::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * Issue #535 : politiques que la personne doit lire et confirmer (campagne en vigueur), sans
     * droit sur le plugin : le lien ouvre l'onglet « Politiques à lire » de ses préférences.
     *
     * @return list<array<string, mixed>>
     */
    private static function policyAcknowledgements(int $usersId): array
    {
        $list = [];
        foreach (PolicyAcknowledgement::getMine($usersId) as $row) {
            if (!$row['pending']) {
                continue;
            }
            $list[] = self::candidate(
                'policy_acknowledgement',
                $row['line_id'],
                $row['title'],
                $row['due_date'],
                PolicyAcknowledgement::getMineURL(),
                true,
                sprintf(__('Version %s', 'grcmanager'), $row['version'])
            );
        }

        return $list;
    }

    /**
     * Formations à suivre (participation en attente) et à renouveler. Comme l'onglet « Mes
     * formations », aucune autre donnée que celles de la personne ; sans droit sur les formations,
     * le lien ouvre cet onglet.
     *
     * @return list<array<string, mixed>>
     */
    private static function trainings(int $usersId): array
    {
        global $DB, $CFG_GLPI;

        $canView = Training::canView();
        $url = static fn (int $id): string => $canView
            ? Training::getFormURLWithID($id)
            : $CFG_GLPI['root_doc'] . '/front/preference.php?forcetab=' . rawurlencode(TrainingQuiz::class . '$1');
        $list = [];
        $completed = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'links.completion_status', 'links.completion_date', 't.id', 't.title', 't.date_delivered',
                    't.renewal_period_months', 't.plugin_grcmanager_trainings_id',
                ],
                'FROM'       => TrainingQuiz::TABLE . ' AS links',
                'INNER JOIN' => [
                    Training::getTable() . ' AS t' => [
                        'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                    ],
                ],
                'WHERE'      => ['links.users_id' => $usersId, 'links.completion_status' => ['pending', 'completed']],
            ]) as $row
        ) {
            $id = (int) $row['id'];
            if ($row['completion_status'] === 'pending') {
                $list[] = self::candidate(
                    'training',
                    $id,
                    (string) $row['title'],
                    $row['date_delivered'],
                    $url($id),
                    true
                );
                continue;
            }
            $completed[] = [
                'id'              => $id,
                'program'         => (int) $row['plugin_grcmanager_trainings_id'] ?: $id,
                'title'           => (string) $row['title'],
                'completion_date' => MySpaceRules::normalizeDate($row['completion_date']),
                'months'          => (int) $row['renewal_period_months'],
            ];
        }
        foreach (MySpaceRules::trainingRenewals($completed) as $renewal) {
            $list[] = self::candidate(
                'training_renewal',
                $renewal['id'],
                $renewal['title'],
                $renewal['due'],
                $url($renewal['id'])
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function supplierRisks(int $usersId): array
    {
        if (!SupplierRisk::canView()) {
            return [];
        }
        $list = [];
        $where = ['users_id' => $usersId, 'status' => ['<>', 'closed'], ['NOT' => ['review_date' => null]]];
        foreach (self::rows(SupplierRisk::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'supplier_review',
                $id,
                (string) $row['title'],
                $row['review_date'],
                SupplierRisk::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function obligations(int $usersId): array
    {
        if (!ComplianceObligation::canView()) {
            return [];
        }
        $list = [];
        $where = ['users_id' => $usersId, ['NOT' => ['review_date' => null]]];
        foreach (self::rows(ComplianceObligation::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'obligation_review',
                $id,
                (string) $row['title'],
                $row['review_date'],
                ComplianceObligation::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * Versions de la SoA que la personne doit approuver : la page d'approbation leur est ouverte
     * sans droit sur la SoA (front/soaapproval.php).
     *
     * @return list<array<string, mixed>>
     */
    private static function soaApprovals(int $usersId): array
    {
        global $CFG_GLPI;

        $list = [];
        foreach (SoaVersion::pendingForUser($usersId) as $row) {
            $list[] = self::candidate(
                'soa_approval',
                (int) $row['id'],
                sprintf(__('Statement of Applicability, version %d', 'grcmanager'), (int) $row['version']),
                null,
                $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php',
                true
            );
        }

        return $list;
    }

    /**
     * Demandes d'exercice des droits ouvertes, traitées par la personne. Comme dans le calendrier,
     * ni l'identité du demandeur ni le titre libre : le type de demande seulement.
     *
     * @return list<array<string, mixed>>
     */
    private static function dataSubjectRequests(int $usersId): array
    {
        if (!DataSubjectRequest::canView()) {
            return [];
        }
        $list = [];
        foreach (self::rows(DataSubjectRequest::class, ['users_id' => $usersId, 'is_deleted' => 0]) as $row) {
            if (DataSubjectRequestRules::isClosed((string) $row['status'])) {
                continue;
            }
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'data_subject_request',
                $id,
                (string) (DataSubjectRequest::getTypes()[(string) $row['request_type']]
                    ?? DataSubjectRequest::getTypeName(1)),
                $row['date_due'],
                DataSubjectRequest::getFormURLWithID($id),
                true
            );
        }

        return $list;
    }

    /**
     * Audits dont la personne est auditrice ou responsable : en cours, ou prévus sous peu.
     *
     * @return list<array<string, mixed>>
     */
    private static function audits(int $usersId): array
    {
        if (!Audit::canView()) {
            return [];
        }
        $list = [];
        $where = [
            'status' => ['planned', 'in_progress'],
            'OR'     => ['users_id' => $usersId, 'users_id_responsible' => $usersId],
        ];
        foreach (self::rows(Audit::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'audit',
                $id,
                (string) $row['title'],
                $row['actual_date'] ?: $row['planned_date'],
                Audit::getFormURLWithID($id),
                $row['status'] === 'in_progress'
            );
        }

        return $list;
    }

    /**
     * Objectifs de sécurité dont la personne est propriétaire, ni atteints ni manqués.
     *
     * @return list<array<string, mixed>>
     */
    private static function objectives(int $usersId): array
    {
        if (!Objective::canView()) {
            return [];
        }
        $list = [];
        $where = [
            'users_id' => $usersId,
            ['NOT' => ['status' => ObjectiveStatuses::TERMINAL]],
            ['NOT' => ['target_date' => null]],
        ];
        foreach (self::rows(Objective::class, $where) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'objective',
                $id,
                (string) $row['title'],
                $row['target_date'],
                Objective::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * Événements prévus du calendrier de conformité dont la personne est responsable.
     *
     * @return list<array<string, mixed>>
     */
    private static function events(int $usersId): array
    {
        if (!ComplianceEvent::canView()) {
            return [];
        }
        $list = [];
        foreach (self::rows(ComplianceEvent::class, ['users_id' => $usersId, 'status' => 'planned']) as $row) {
            $id = (int) $row['id'];
            $list[] = self::candidate(
                'event',
                $id,
                (string) $row['name'],
                $row['date_begin'],
                ComplianceEvent::getFormURLWithID($id),
                false,
                (string) (ComplianceEvent::getEventTypes()[(string) $row['event_type']] ?? '')
            );
        }

        return $list;
    }
}
