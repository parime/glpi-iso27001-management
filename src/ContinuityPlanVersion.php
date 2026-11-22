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
use GlpiPlugin\Grcmanager\Install\ContinuityPlanTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;
use Html;
use Session;
use User;

/**
 * Issue #1083 : versions et approbation d'un plan de continuité ou de reprise (onglet « Versions et
 * approbation »). Reprend le circuit des politiques (PolicyVersion, issue #611) :
 * - approbateurs : l'approbateur désigné sur le plan, sinon les approbateurs par défaut des politiques
 *   (Configuration > GRC Manager), avec leur règle de validation ;
 * - la demande fige la version : contenu en JSON (ContinuityPlan::snapshot()) et son empreinte
 *   SHA-256, conservés dans l'historique et imprimables en PDF ;
 * - chaque approbateur approuve ou refuse (commentaire obligatoire pour un refus) ; un refus renvoie
 *   le plan en brouillon ; la règle atteinte, le plan passe « Approuvé » et sa date de revue est
 *   calculée ;
 * - le contenu est figé pendant l'approbation et après : une modification passe par une nouvelle
 *   version, explicitement préparée. La version approuvée précédente reste en vigueur jusque-là.
 *
 * Le statut du plan ne change que par ces actions explicites ; aucun droit n'est modifié.
 */
final class ContinuityPlanVersion extends CommonGLPI
{
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    public const VERSION_FK = 'plugin_grcmanager_continuityplanversions_id';

    public static function getTypeName($nb = 0)
    {
        return __('Versions and approval', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-signature';
    }

    /**
     * @return array<string, array{0: string, 1: string}> statut => [libellé, classes du badge]
     */
    public static function versionStatusLabels(): array
    {
        return [
            PolicyApprovalRules::VERSION_PENDING   => [__(
                'Awaiting approval',
                'grcmanager'
            ), 'bg-blue-subtle text-blue-emphasis'],
            PolicyApprovalRules::VERSION_APPROVED  => [__(
                'Approved',
                'grcmanager'
            ), 'bg-green-subtle text-green-emphasis'],
            PolicyApprovalRules::VERSION_REJECTED  => [__('Rejected', 'grcmanager'), 'bg-red-subtle text-red-emphasis'],
            PolicyApprovalRules::VERSION_WITHDRAWN => [__(
                'Withdrawn',
                'grcmanager'
            ), 'bg-secondary-subtle text-secondary-emphasis'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function answerLabels(): array
    {
        return [
            PolicyApprovalRules::ANSWER_PENDING  => __('Pending', 'grcmanager'),
            PolicyApprovalRules::ANSWER_APPROVED => _x('masculine', 'Approved', 'grcmanager'),
            PolicyApprovalRules::ANSWER_REJECTED => _x('masculine', 'Rejected', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Versions soumises d'un plan, la plus récente d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function getVersions(int $planId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => ContinuityPlanTables::VERSIONS,
                'WHERE' => [ContinuityPlan::FK => $planId],
                'ORDER' => 'id DESC',
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Une version du plan, avec son contenu figé décodé ; null si elle n'est pas de ce plan.
     *
     * @return array<string, mixed>|null
     */
    public static function getVersion(int $planId, int $versionId): ?array
    {
        foreach (self::getVersions($planId) as $row) {
            if ((int) $row['id'] === $versionId) {
                $snapshot = json_decode((string) $row['snapshot'], true);
                $row['snapshot'] = is_array($snapshot) ? $snapshot : [];

                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pending(int $planId): ?array
    {
        foreach (self::getVersions($planId) as $row) {
            if ($row['status'] === PolicyApprovalRules::VERSION_PENDING) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Réponses des approbateurs d'une version.
     *
     * @return list<array{users_id: int, name: string, status: string, comment: string, date_answered: string}>
     */
    public static function getApprovers(int $versionId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => ContinuityPlanTables::APPROVERS,
                'WHERE' => [self::VERSION_FK => $versionId],
                'ORDER' => 'id ASC',
            ]) as $row
        ) {
            $rows[] = [
                'users_id'      => (int) $row['users_id'],
                'name'          => (string) getUserName((int) $row['users_id']),
                'status'        => (string) $row['status'],
                'comment'       => (string) $row['comment'],
                'date_answered' => (string) ($row['date_answered'] ?? ''),
            ];
        }

        return $rows;
    }

    /** La version en attente du plan attend-elle la réponse de cette personne ? */
    public static function awaitsUser(int $planId, int $usersId): bool
    {
        $pending = self::pending($planId);
        if ($pending === null || $usersId <= 0) {
            return false;
        }
        foreach (self::getApprovers((int) $pending['id']) as $approver) {
            if ($approver['users_id'] === $usersId && $approver['status'] === PolicyApprovalRules::ANSWER_PENDING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Approbateurs du plan : l'approbateur désigné, sinon ceux des politiques (configuration).
     *
     * @return array{approvers: list<array{itemtype: string, items_id: int}>, quorum: int, uses_default: bool}
     */
    public static function approversFor(ContinuityPlan $plan): array
    {
        $designated = (int) ($plan->fields['users_id_approver'] ?? 0);
        if ($designated > 0) {
            return [
                'approvers'    => [['itemtype' => User::class, 'items_id' => $designated]],
                'quorum'       => PolicyApprovalRules::QUORUM_ALL,
                'uses_default' => false,
            ];
        }

        return [
            'approvers'    => PolicyApprovalConfig::defaultApprovers(),
            'quorum'       => PolicyApprovalConfig::defaultQuorum(),
            'uses_default' => true,
        ];
    }

    /**
     * @return list<string> libellés des versions déjà approuvées
     */
    private static function approvedLabels(int $planId): array
    {
        $labels = [];
        foreach (self::getVersions($planId) as $row) {
            if ($row['status'] === PolicyApprovalRules::VERSION_APPROVED) {
                $labels[] = (string) $row['version'];
            }
        }

        return $labels;
    }

    // ------------------------------------------------------------------
    // Actions du circuit
    // ------------------------------------------------------------------

    /**
     * Fige la version actuelle et l'envoie aux approbateurs. Message d'erreur et 0 si refusé.
     *
     * @return int identifiant de la version créée
     */
    public static function submit(ContinuityPlan $plan, string $comment): int
    {
        global $DB;

        $error = static function (string $message): int {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return 0;
        };
        $planId = (int) $plan->getID();
        if ((string) $plan->fields['status'] !== Rules::STATUS_DRAFT || self::pending($planId) !== null) {
            return $error(__('Only a draft plan can be submitted for approval.', 'grcmanager'));
        }
        $version = (string) $plan->fields['version'];
        if (PolicyApprovalRules::needsNewVersion($version, self::approvedLabels($planId))) {
            return $error(__(
                'This version number has already been approved: give the plan a new version number.',
                'grcmanager'
            ));
        }
        $circuit = self::approversFor($plan);
        $users = PolicyVersion::resolveUsers($circuit['approvers']);
        $quorum = PolicyApprovalRules::sanitizeQuorum($circuit['quorum'], false);
        if (!PolicyApprovalRules::isReachable($quorum, count($users))) {
            return $error(__(
                'No active approver: designate one on the plan, or set the default approvers of policies in '
                    . 'the configuration.',
                'grcmanager'
            ));
        }
        $snapshot = $plan->snapshot();
        $now = date('Y-m-d H:i:s');
        $DB->insert(ContinuityPlanTables::VERSIONS, [
            ContinuityPlan::FK => $planId,
            'version'          => $version,
            'status'           => PolicyApprovalRules::VERSION_PENDING,
            'quorum'           => $quorum,
            'snapshot'         => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'fingerprint'      => Rules::fingerprint($snapshot),
            'users_id'         => (int) Session::getLoginUserID(),
            'comment'          => trim($comment),
            'date_creation'    => $now,
        ]);
        $versionId = (int) $DB->insertId();
        foreach ($users as $usersId) {
            $DB->insert(ContinuityPlanTables::APPROVERS, [
                self::VERSION_FK => $versionId,
                'users_id'       => $usersId,
                'status'         => PolicyApprovalRules::ANSWER_PENDING,
            ]);
        }
        $plan->workflowUpdate(['status' => Rules::STATUS_PENDING]);
        AuditLog::record('approval_request', ContinuityPlan::class, $planId, 0, 'success', [
            'version' => $version, 'approvers' => count($users),
        ]);

        return $versionId;
    }

    /**
     * Réponse d'un approbateur de la version en attente. Un refus exige un commentaire.
     */
    public static function answer(int $versionId, int $usersId, bool $approve, string $comment): bool
    {
        global $DB;

        $comment = trim($comment);
        $row = $DB->request(['FROM' => ContinuityPlanTables::VERSIONS, 'WHERE' => ['id' => $versionId]])->current();
        if (
            !is_array($row) || (!$approve && $comment === '')
            || $row['status'] !== PolicyApprovalRules::VERSION_PENDING
        ) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $DB->update(ContinuityPlanTables::APPROVERS, [
            'status'        => $approve ? PolicyApprovalRules::ANSWER_APPROVED : PolicyApprovalRules::ANSWER_REJECTED,
            'comment'       => $comment,
            'date_answered' => $now,
        ], [
            self::VERSION_FK => $versionId,
            'users_id'       => $usersId,
            'status'         => PolicyApprovalRules::ANSWER_PENDING,
        ]);
        if ($DB->affectedRows() === 0) {
            return false;
        }
        $planId = (int) $row[ContinuityPlan::FK];
        AuditLog::record('approval_decision', ContinuityPlan::class, $planId, 0, 'success', [
            'version' => (string) $row['version'],
            'answer'  => $approve ? PolicyApprovalRules::ANSWER_APPROVED : PolicyApprovalRules::ANSWER_REJECTED,
        ]);
        $status = PolicyApprovalRules::versionStatus(
            array_column(self::getApprovers($versionId), 'status'),
            (int) $row['quorum']
        );
        if ($status === PolicyApprovalRules::VERSION_PENDING) {
            return true;
        }
        $DB->update(
            ContinuityPlanTables::VERSIONS,
            ['status' => $status, 'date_answered' => $now],
            ['id' => $versionId]
        );
        $plan = new ContinuityPlan();
        if ($plan->getFromDB($planId)) {
            if ($status === PolicyApprovalRules::VERSION_APPROVED) {
                $today = substr($now, 0, 10);
                $months = Rules::effectiveReviewMonths(
                    (int) $plan->fields['review_months'],
                    ContinuityPlan::settings()['review_months']
                );
                $plan->workflowUpdate([
                    'status'           => Rules::STATUS_APPROVED,
                    'date_approved'    => $today,
                    'next_review_date' => Rules::nextReviewDate($today, $months),
                ]);
            } else {
                $plan->workflowUpdate(['status' => Rules::STATUS_DRAFT]);
            }
        }

        return true;
    }

    /** Retire une demande encore en attente : le plan redevient un brouillon modifiable. */
    public static function withdraw(ContinuityPlan $plan): bool
    {
        global $DB;

        $pending = self::pending((int) $plan->getID());
        if ($pending === null) {
            return false;
        }
        $DB->update(ContinuityPlanTables::VERSIONS, [
            'status'        => PolicyApprovalRules::VERSION_WITHDRAWN,
            'date_answered' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $pending['id']]);
        $plan->workflowUpdate(['status' => Rules::STATUS_DRAFT]);
        AuditLog::record('approval_withdraw', ContinuityPlan::class, (int) $plan->getID(), 0, 'success', [
            'version' => (string) $pending['version'],
        ]);

        return true;
    }

    /**
     * Rouvre un plan approuvé sous un nouveau numéro de version. La version approuvée reste dans
     * l'historique et en vigueur jusqu'à l'approbation de la nouvelle.
     */
    public static function prepareNewVersion(ContinuityPlan $plan, string $newVersion): bool
    {
        $newVersion = mb_substr(trim($newVersion), 0, 16);
        $planId = (int) $plan->getID();
        if (
            $newVersion === '' || (string) $plan->fields['status'] !== Rules::STATUS_APPROVED
            || self::pending($planId) !== null
            || PolicyApprovalRules::needsNewVersion($newVersion, self::approvedLabels($planId))
        ) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Give a version number that has not been approved yet.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }

        return $plan->workflowUpdate(['status' => Rules::STATUS_DRAFT, 'version' => $newVersion]);
    }

    /** Un plan purgé emporte ses versions et les réponses de leurs approbateurs. */
    public static function purgePlan(int $planId): void
    {
        global $DB;

        $ids = array_map(static fn (array $row): int => (int) $row['id'], self::getVersions($planId));
        if ($ids !== []) {
            $DB->delete(ContinuityPlanTables::APPROVERS, [self::VERSION_FK => $ids]);
        }
        $DB->delete(ContinuityPlanTables::VERSIONS, [ContinuityPlan::FK => $planId]);
    }

    /**
     * Historique présenté : versions, décisions et empreinte.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(int $planId): array
    {
        $labels = self::versionStatusLabels();
        $answers = self::answerLabels();
        $rows = [];
        foreach (self::getVersions($planId) as $row) {
            $status = $labels[(string) $row['status']]
                ?? [(string) $row['status'], 'bg-secondary-subtle text-secondary-emphasis'];
            $rows[] = [
                'id'           => (int) $row['id'],
                'version'      => (string) $row['version'],
                'status'       => (string) $row['status'],
                'status_label' => $status[0],
                'status_class' => $status[1],
                'requested_at' => Html::convDateTime((string) $row['date_creation']),
                'requested_by' => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
                'answered_at'  => empty($row['date_answered'])
                    ? '' : Html::convDateTime((string) $row['date_answered']),
                'comment'      => (string) $row['comment'],
                'fingerprint'  => (string) $row['fingerprint'],
                'pdf_url'      => ContinuityPlan::getExportURL($planId, (int) $row['id']),
                'approvers'    => array_map(static fn (array $approver): array => $approver + [
                    'status_label'  => $answers[$approver['status']] ?? $approver['status'],
                    'answered_text' => $approver['date_answered'] === ''
                        ? '' : Html::convDateTime($approver['date_answered']),
                ], self::getApprovers((int) $row['id'])),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Onglet du plan
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof ContinuityPlan || $item->isNewItem() || !ContinuityPlan::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(),
            count(self::getVersions((int) $item->getID())),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof ContinuityPlan || $item->isNewItem() || !$item->canViewItem()) {
            return false;
        }
        $planId = (int) $item->getID();
        $status = (string) $item->fields['status'];
        $canUpdate = (bool) $item->can($planId, UPDATE);
        $pending = self::pending($planId);
        $circuit = self::approversFor($item);
        $statuses = ContinuityPlan::statusLabels();
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/continuity_plan_versions.html.twig', [
            'plan_id'         => $planId,
            'version'         => (string) $item->fields['version'],
            'status'          => $status,
            'status_badge'    => $statuses[$status] ?? $statuses[Rules::STATUS_DRAFT],
            'date_approved'   => (string) ($item->fields['date_approved'] ?? ''),
            'next_review'     => (string) ($item->fields['next_review_date'] ?? ''),
            'pending'         => $pending === null ? null : [
                'id'          => (int) $pending['id'],
                'version'     => (string) $pending['version'],
                'is_approver' => self::awaitsUser($planId, (int) Session::getLoginUserID()),
            ],
            'approvers'       => PolicyVersion::describe($circuit['approvers']),
            'approver_count'  => count(PolicyVersion::resolveUsers($circuit['approvers'])),
            'uses_default'    => $circuit['uses_default'],
            'rule'            => PolicyVersion::ruleLabel(
                PolicyApprovalRules::sanitizeQuorum($circuit['quorum'], false)
            ),
            'can_submit'      => $canUpdate && $status === Rules::STATUS_DRAFT && $pending === null,
            'can_withdraw'    => $canUpdate && $pending !== null,
            'can_new_version' => $canUpdate && $status === Rules::STATUS_APPROVED && $pending === null,
            'history'         => self::present($planId),
            'action'          => ContinuityPlan::getFormURL(),
            'export_url'      => ContinuityPlan::getExportURL($planId),
        ]);

        return true;
    }
}
