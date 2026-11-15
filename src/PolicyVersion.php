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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\PolicyVersionBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use Group;
use Html;
use NotificationEvent;
use Session;
use User;

/**
 * Issue #611 : approbation des politiques de sécurité par la direction (A.5.1), sur le modèle de
 * l'approbation de la SoA (\GlpiPlugin\Grcmanager\SoaVersion).
 *
 * - Approbateurs désignés sur la politique (utilisateurs, groupes), sinon ceux de la configuration,
 *   et règle de validation (tous, ou un nombre minimal d'approbations).
 * - La demande fige la version : un PDF de la politique (contenu, documents joints et leurs
 *   empreintes) devient un Document GLPI lié à la version, avec l'empreinte SHA-256 du fichier et
 *   celle du contenu (PolicyApprovalRules::fingerprint()). Les approbateurs sont prévenus.
 * - Chaque approbateur approuve ou refuse (commentaire obligatoire) depuis sa session GLPI, sans
 *   droit sur le plugin (front/policyapproval.php, « Mon espace GRC »). Un refus refuse la version
 *   et la politique reste en brouillon ; la règle atteinte, la politique passe « Approuvée », à la
 *   date de la dernière approbation, et le procès-verbal d'approbation est archivé comme preuve sur
 *   la politique et sur la mesure A.5.1.
 * - Pendant l'approbation et après, le contenu est figé (Policy::prepareInputForUpdate()) : toute
 *   modification passe par une nouvelle version, explicitement préparée.
 *
 * Le statut de la politique ne change que par ces actions explicites ; aucun droit n'est modifié.
 * Les politiques approuvées avant l'activation du circuit gardent leur statut : une ligne
 * « approuvée avant le circuit », sans empreinte, les distingue (markLegacyApproved()).
 */
class PolicyVersion extends PolicyVersionBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const APPROVERS_TABLE = 'glpi_plugin_grcmanager_policyversions_users';
    public const DESIGNATED_TABLE = 'glpi_plugin_grcmanager_policyapprovers';

    public const REQUEST_EVENT = 'policy_approval_request';
    public const DECISION_EVENT = 'policy_approval_decision';

    /** Mesure de l'Annexe A où le procès-verbal d'approbation est archivé. */
    public const CONTROL_CODE = 'A.5.1';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_policyversions';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Policy approval', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-signature';
    }

    // Versions créées par submit() seulement et jamais supprimées : historique des approbations.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function getPageURL(bool $absolute = false): string
    {
        global $CFG_GLPI;

        $base = $absolute ? rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') : $CFG_GLPI['root_doc'];

        return $base . '/plugins/grcmanager/front/policyapproval.php';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            PolicyApprovalRules::VERSION_PENDING   => __('Awaiting approval', 'grcmanager'),
            PolicyApprovalRules::VERSION_APPROVED  => __('Approved', 'grcmanager'),
            PolicyApprovalRules::VERSION_REJECTED  => __('Rejected', 'grcmanager'),
            PolicyApprovalRules::VERSION_WITHDRAWN => __('Withdrawn', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAnswerLabels(): array
    {
        return [
            PolicyApprovalRules::ANSWER_PENDING  => __('Pending', 'grcmanager'),
            PolicyApprovalRules::ANSWER_APPROVED => _x('masculine', 'Approved', 'grcmanager'),
            PolicyApprovalRules::ANSWER_REJECTED => _x('masculine', 'Rejected', 'grcmanager'),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------

    /**
     * Versions soumises d'une politique, la plus récente d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function getVersions(int $policyId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_policies_id' => $policyId],
                'ORDER' => 'id DESC',
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function latest(int $policyId, string $status): ?array
    {
        foreach (self::getVersions($policyId) as $row) {
            if ($row['status'] === $status) {
                return $row;
            }
        }

        return null;
    }

    public static function hasPending(int $policyId): bool
    {
        return self::latest($policyId, PolicyApprovalRules::VERSION_PENDING) !== null;
    }

    /**
     * @return list<string> libellés des versions déjà approuvées
     */
    public static function approvedVersionLabels(int $policyId): array
    {
        $labels = [];
        foreach (self::getVersions($policyId) as $row) {
            if ($row['status'] === PolicyApprovalRules::VERSION_APPROVED) {
                $labels[] = (string) $row['version'];
            }
        }

        return $labels;
    }

    /**
     * @return list<array{users_id: int, name: string, status: string, date_answered: string|null, comment: string}>
     */
    public static function getApprovers(int $versionId): array
    {
        global $DB;

        $approvers = [];
        foreach (
            $DB->request([
                'FROM'  => self::APPROVERS_TABLE,
                'WHERE' => ['plugin_grcmanager_policyversions_id' => $versionId],
                'ORDER' => 'id ASC',
            ]) as $row
        ) {
            $approvers[] = [
                'users_id'      => (int) $row['users_id'],
                'name'          => getUserName((int) $row['users_id']),
                'status'        => (string) $row['status'],
                'date_answered' => $row['date_answered'],
                'comment'       => (string) ($row['comment'] ?? ''),
            ];
        }

        return $approvers;
    }

    /**
     * Versions en attente pour lesquelles $usersId n'a pas encore répondu, avec le titre de la politique.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingForUser(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }
        $versions = [];
        foreach (
            $DB->request([
                'SELECT'     => ['versions.*', 'policies.title'],
                'FROM'       => self::getTable() . ' AS versions',
                'INNER JOIN' => [
                    self::APPROVERS_TABLE . ' AS approvers' => [
                        'FKEY' => ['approvers' => 'plugin_grcmanager_policyversions_id', 'versions' => 'id'],
                    ],
                    Policy::getTable() . ' AS policies' => [
                        'FKEY' => ['policies' => 'id', 'versions' => 'plugin_grcmanager_policies_id'],
                    ],
                ],
                'WHERE'      => [
                    'versions.status'    => PolicyApprovalRules::VERSION_PENDING,
                    'approvers.users_id' => $usersId,
                    'approvers.status'   => PolicyApprovalRules::ANSWER_PENDING,
                ],
                'ORDER'      => 'versions.id DESC',
            ]) as $row
        ) {
            $versions[] = $row;
        }

        return $versions;
    }

    /** $usersId est-il approbateur désigné de cette version (quelle que soit sa réponse) ? */
    public static function isApprover(int $versionId, int $usersId): bool
    {
        return $usersId > 0 && countElementsInTable(self::APPROVERS_TABLE, [
            'plugin_grcmanager_policyversions_id' => $versionId,
            'users_id'                            => $usersId,
        ]) > 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Approbateurs désignés et règle
    // ---------------------------------------------------------------------------------------------

    /**
     * Approbateurs désignés sur la politique elle-même (vide : ceux de la configuration).
     *
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function getDesignated(int $policyId): array
    {
        global $DB;

        $list = [];
        foreach (
            $DB->request([
                'FROM'  => self::DESIGNATED_TABLE,
                'WHERE' => ['plugin_grcmanager_policies_id' => $policyId],
                'ORDER' => 'id ASC',
            ]) as $row
        ) {
            $list[] = ['itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']];
        }

        return PolicyApprovalConfig::sanitizeApprovers($list);
    }

    /**
     * @param list<array{itemtype: string, items_id: int}> $approvers
     */
    public static function setDesignated(Policy $policy, array $approvers, int $quorum): void
    {
        global $DB;

        $policyId = $policy->getID();
        $approvers = PolicyApprovalConfig::sanitizeApprovers($approvers);
        $DB->delete(self::DESIGNATED_TABLE, ['plugin_grcmanager_policies_id' => $policyId]);
        $now = date('Y-m-d H:i:s');
        foreach ($approvers as $approver) {
            $DB->insert(self::DESIGNATED_TABLE, [
                'plugin_grcmanager_policies_id' => $policyId,
                'itemtype'                      => $approver['itemtype'],
                'items_id'                      => $approver['items_id'],
                'date_creation'                 => $now,
            ]);
        }
        $DB->update(Policy::getTable(), [
            'approval_quorum' => PolicyApprovalRules::sanitizeQuorum($quorum),
        ], ['id' => $policyId]);
        AuditLog::record('update', Policy::class, $policyId, 0, 'success', ['approvers' => $approvers]);
    }

    /**
     * Approbateurs effectifs : ceux de la politique, sinon ceux de la configuration.
     *
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function effectiveDesignated(int $policyId): array
    {
        $own = self::getDesignated($policyId);

        return $own !== [] ? $own : PolicyApprovalConfig::defaultApprovers();
    }

    public static function effectiveQuorum(Policy $policy): int
    {
        return PolicyApprovalRules::effectiveQuorum(
            (int) ($policy->fields['approval_quorum'] ?? PolicyApprovalRules::QUORUM_DEFAULT),
            PolicyApprovalConfig::defaultQuorum()
        );
    }

    /**
     * Comptes actifs derrière une liste d'approbateurs (membres actifs des groupes compris).
     *
     * @param list<array{itemtype: string, items_id: int}> $approvers
     * @return list<int>
     */
    public static function resolveUsers(array $approvers): array
    {
        global $DB;

        $users = [];
        $groups = [];
        foreach ($approvers as $approver) {
            if ($approver['itemtype'] === Group::class) {
                $groups[] = $approver['items_id'];
            } else {
                $users[] = $approver['items_id'];
            }
        }
        if ($groups !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['users_id'],
                    'FROM'   => 'glpi_groups_users',
                    'WHERE'  => ['groups_id' => $groups],
                ]) as $row
            ) {
                $users[] = (int) $row['users_id'];
            }
        }
        $users = array_values(array_unique(array_filter($users, static fn (int $id): bool => $id > 0)));
        if ($users === []) {
            return [];
        }
        $active = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => User::getTable(),
                'WHERE'  => ['id' => $users, 'is_active' => 1, 'is_deleted' => 0],
                'ORDER'  => 'id ASC',
            ]) as $row
        ) {
            $active[] = (int) $row['id'];
        }

        return $active;
    }

    /**
     * Libellés d'une liste d'approbateurs (« Groupe Direction », « Alice Martin »).
     *
     * @param list<array{itemtype: string, items_id: int}> $approvers
     * @return list<array{icon: string, name: string}>
     */
    public static function describe(array $approvers): array
    {
        $list = [];
        foreach ($approvers as $approver) {
            $isGroup = $approver['itemtype'] === Group::class;
            $list[] = [
                'icon' => $isGroup ? 'ti-users-group' : 'ti-user',
                'name' => $isGroup
                    ? \Dropdown::getDropdownName(Group::getTable(), $approver['items_id'])
                    : getUserName($approver['items_id']),
            ];
        }

        return $list;
    }

    // ---------------------------------------------------------------------------------------------
    // Empreinte du contenu
    // ---------------------------------------------------------------------------------------------

    /**
     * Documents joints à la politique (hors rapports et procès-verbaux réservés du plugin).
     *
     * @return list<array{id: int, name: string, filename: string, sha1sum: string}>
     */
    public static function policyDocuments(int $policyId): array
    {
        global $DB;

        $list = [];
        foreach (
            $DB->request([
                'SELECT'     => ['d.id', 'd.name', 'd.filename', 'd.sha1sum'],
                'FROM'       => Document_Item::getTable() . ' AS di',
                'INNER JOIN' => [Document::getTable() . ' AS d' => ['FKEY' => ['di' => 'documents_id', 'd' => 'id']]],
                'LEFT JOIN'  => [
                    RestrictedDocument::TABLE . ' AS r' => ['FKEY' => ['r' => 'documents_id', 'd' => 'id']],
                ],
                'WHERE'      => [
                    'di.itemtype'  => Policy::class,
                    'di.items_id'  => $policyId,
                    'd.is_deleted' => 0,
                    'r.id'         => null,
                ],
                'ORDER'      => ['d.id'],
            ]) as $row
        ) {
            $list[] = [
                'id'       => (int) $row['id'],
                'name'     => (string) ($row['name'] ?: $row['filename']),
                'filename' => (string) $row['filename'],
                'sha1sum'  => (string) $row['sha1sum'],
            ];
        }

        return $list;
    }

    public static function currentFingerprint(Policy $policy): string
    {
        return PolicyApprovalRules::fingerprint(
            $policy->fields,
            array_column(self::policyDocuments($policy->getID()), 'sha1sum')
        );
    }

    /**
     * @return 'not_approved'|'legacy'|'up_to_date'|'changed'
     */
    public static function currentState(Policy $policy): string
    {
        $approved = self::latest($policy->getID(), PolicyApprovalRules::VERSION_APPROVED);

        return PolicyApprovalRules::currentState(
            (string) ($policy->fields['status'] ?? ''),
            $approved === null || !empty($approved['is_legacy']) ? null : (string) $approved['fingerprint'],
            self::currentFingerprint($policy)
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------------------------------

    /**
     * Fige la politique et la soumet à ses approbateurs.
     *
     * @return int ID de la version, 0 en cas d'échec (message déjà affiché)
     */
    public static function submit(Policy $policy, string $comment): int
    {
        global $DB;

        $policyId = $policy->getID();
        $error = static function (string $message): int {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return 0;
        };
        if (!PolicyApprovalConfig::isEnabled()) {
            return $error(__('The policy approval workflow is not enabled.', 'grcmanager'));
        }
        if (($policy->fields['status'] ?? '') !== PolicyLifecycle::STATUS_DRAFT) {
            return $error(__('Only a draft policy can be submitted for approval.', 'grcmanager'));
        }
        if (self::hasPending($policyId)) {
            return $error(__('A version of this policy is already awaiting approval.', 'grcmanager'));
        }
        $versionLabel = trim((string) ($policy->fields['version'] ?? ''));
        if ($versionLabel === '') {
            return $error(__('Give the policy a version number first.', 'grcmanager'));
        }
        if (PolicyApprovalRules::needsNewVersion($versionLabel, self::approvedVersionLabels($policyId))) {
            return $error(sprintf(
                __('Version %s has already been approved: change the version number to submit a new version.', 'grcmanager'),
                $versionLabel
            ));
        }

        // Séparation des tâches, comme pour la SoA : qui soumet ne s'approuve pas lui-même.
        $requesterId = (int) Session::getLoginUserID();
        $approverIds = self::resolveUsers(self::effectiveDesignated($policyId));
        if (in_array($requesterId, $approverIds, true)) {
            $approverIds = array_values(array_diff($approverIds, [$requesterId]));
            Session::addMessageAfterRedirect(
                htmlescape(__('You cannot approve your own request: you have been removed from the approvers.', 'grcmanager')),
                false,
                WARNING
            );
        }
        if ($approverIds === []) {
            return $error(__('Designate at least one approver (on the policy or in the configuration).', 'grcmanager'));
        }
        $quorum = self::effectiveQuorum($policy);
        if (!PolicyApprovalRules::isReachable($quorum, count($approverIds))) {
            return $error(sprintf(
                __('The approval rule requires %1$d approvals but only %2$d approver(s) can answer.', 'grcmanager'),
                PolicyApprovalRules::requiredApprovals($quorum, count($approverIds)),
                count($approverIds)
            ));
        }

        $now = date('Y-m-d H:i:s');
        $revision = (int) ($DB->request([
            'SELECT' => ['MAX' => 'revision AS r'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_grcmanager_policies_id' => $policyId],
        ])->current()['r'] ?? 0) + 1;
        $fingerprint = self::currentFingerprint($policy);

        $version = new self();
        $id = (int) $version->add([
            'plugin_grcmanager_policies_id' => $policyId,
            'version'                       => $versionLabel,
            'revision'                      => $revision,
            'status'                        => PolicyApprovalRules::VERSION_PENDING,
            'quorum'                        => $quorum,
            'fingerprint'                   => $fingerprint,
            'users_id'                      => $requesterId,
            'comment'                       => $comment,
        ]);
        if ($id <= 0) {
            return 0;
        }
        foreach ($approverIds as $approverId) {
            $DB->insert(self::APPROVERS_TABLE, [
                'plugin_grcmanager_policyversions_id' => $id,
                'users_id'                            => $approverId,
                'status'                              => PolicyApprovalRules::ANSWER_PENDING,
                'date_creation'                       => $now,
            ]);
        }

        $version->getFromDB($id);
        $pdf = PdfRenderer::renderHtmlToPdf(self::renderFrozenHtml($policy, $version));
        $documentsId = self::storePdf(
            $version,
            $pdf,
            sprintf('politique-%d-v%s-%s.pdf', $policyId, self::fileSafe($versionLabel), date('Ymd-His')),
            sprintf(__('%1$s - version %2$s submitted for approval', 'grcmanager'), $policy->fields['title'], $versionLabel),
            [[self::class, $id]]
        );
        $version->update([
            'id'           => $id,
            'documents_id' => $documentsId,
            'pdf_sha256'   => hash('sha256', $pdf),
        ]);

        AuditLog::record('approval_request', Policy::class, $policyId, 0, 'success', [
            'version' => $versionLabel, 'fingerprint' => $fingerprint, 'approvers' => $approverIds, 'quorum' => $quorum,
        ]);
        NotificationEvent::raiseEvent(self::REQUEST_EVENT, $version, ['entities_id' => 0]);

        return $id;
    }

    /**
     * Réponse d'un approbateur désigné, tant que la version est en attente. Un refus exige un
     * commentaire (vérifié aussi ici).
     */
    public static function answer(int $versionId, int $usersId, bool $approve, string $comment): bool
    {
        global $DB;

        $comment = trim($comment);
        $version = new self();
        if (
            (!$approve && $comment === '')
            || !$version->getFromDB($versionId)
            || $version->fields['status'] !== PolicyApprovalRules::VERSION_PENDING
        ) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $DB->update(self::APPROVERS_TABLE, [
            'status'        => $approve ? PolicyApprovalRules::ANSWER_APPROVED : PolicyApprovalRules::ANSWER_REJECTED,
            'comment'       => $comment,
            'date_answered' => $now,
        ], [
            'plugin_grcmanager_policyversions_id' => $versionId,
            'users_id'                            => $usersId,
            'status'                              => PolicyApprovalRules::ANSWER_PENDING,
        ]);
        if ($DB->affectedRows() === 0) {
            return false;
        }
        $policyId = (int) $version->fields['plugin_grcmanager_policies_id'];
        AuditLog::record('approval_decision', Policy::class, $policyId, 0, 'success', [
            'version' => (string) $version->fields['version'],
            'answer'  => $approve ? PolicyApprovalRules::ANSWER_APPROVED : PolicyApprovalRules::ANSWER_REJECTED,
        ]);

        $status = PolicyApprovalRules::versionStatus(
            array_column(self::getApprovers($versionId), 'status'),
            (int) $version->fields['quorum']
        );
        if ($status === PolicyApprovalRules::VERSION_PENDING) {
            return true;
        }
        $version->update(['id' => $versionId, 'status' => $status, 'date_answered' => $now]);
        $version->getFromDB($versionId);

        $policy = new Policy();
        if ($policy->getFromDB($policyId) && $status === PolicyApprovalRules::VERSION_APPROVED) {
            // Seul chemin vers « Approuvée » quand le circuit est activé ; la date d'approbation
            // est celle de la dernière approbation.
            $policy->update([
                'id'                => $policyId,
                'status'            => PolicyLifecycle::STATUS_APPROVED,
                'approval_date'     => substr($now, 0, 10),
                '_policy_approval'  => true,
            ]);
            $recordId = self::archiveRecord($policy, $version);
            $version->update(['id' => $versionId, 'record_documents_id' => $recordId]);
        }
        NotificationEvent::raiseEvent(self::DECISION_EVENT, $version, ['entities_id' => 0]);

        return true;
    }

    /** Retire une demande encore en attente (le contenu redevient modifiable). */
    public static function withdraw(int $versionId): bool
    {
        $version = new self();
        if (!$version->getFromDB($versionId) || $version->fields['status'] !== PolicyApprovalRules::VERSION_PENDING) {
            return false;
        }
        $version->update([
            'id'            => $versionId,
            'status'        => PolicyApprovalRules::VERSION_WITHDRAWN,
            'date_answered' => date('Y-m-d H:i:s'),
        ]);
        AuditLog::record('update', Policy::class, (int) $version->fields['plugin_grcmanager_policies_id'], 0, 'success', [
            'approval_withdrawn' => (string) $version->fields['version'],
        ]);

        return true;
    }

    /**
     * Prépare une nouvelle version d'une politique approuvée : elle repasse en brouillon avec un
     * nouveau numéro, le contenu redevient modifiable ; la version approuvée reste dans l'historique.
     */
    public static function prepareNewVersion(Policy $policy, string $newVersion): bool
    {
        $newVersion = trim($newVersion);
        $policyId = $policy->getID();
        if (($policy->fields['status'] ?? '') !== PolicyLifecycle::STATUS_APPROVED || self::hasPending($policyId)) {
            return false;
        }
        if (
            $newVersion === ''
            || mb_strlen($newVersion) > 16
            || strcasecmp($newVersion, trim((string) $policy->fields['version'])) === 0
            || PolicyApprovalRules::needsNewVersion($newVersion, self::approvedVersionLabels($policyId))
        ) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Enter a new version number, different from the approved versions.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }

        return $policy->update([
            'id'               => $policyId,
            'status'           => PolicyLifecycle::STATUS_DRAFT,
            'version'          => $newVersion,
            '_policy_approval' => true,
        ]);
    }

    /**
     * Politiques déjà approuvées sans version enregistrée : une ligne « approuvée avant le circuit »,
     * sans empreinte. Aucun statut ne change. Idempotent.
     */
    public static function markLegacyApproved(): int
    {
        global $DB;

        $count = 0;
        foreach (
            $DB->request([
                'FROM'  => Policy::getTable(),
                'WHERE' => ['status' => PolicyLifecycle::STATUS_APPROVED],
            ]) as $row
        ) {
            $approved = self::approvedVersionLabels((int) $row['id']);
            if (PolicyApprovalRules::needsNewVersion((string) $row['version'], $approved)) {
                continue;
            }
            $date = $row['approval_date'] ?: substr((string) ($row['date_mod'] ?? date('Y-m-d')), 0, 10);
            $DB->insert(self::getTable(), [
                'plugin_grcmanager_policies_id' => (int) $row['id'],
                'version'                       => (string) $row['version'],
                'revision'                      => 0,
                'status'                        => PolicyApprovalRules::VERSION_APPROVED,
                'quorum'                        => 0,
                'is_legacy'                     => 1,
                'fingerprint'                   => '',
                'date_answered'                 => $date . ' 00:00:00',
                'date_creation'                 => date('Y-m-d H:i:s'),
                'date_mod'                      => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------------------------------------------
    // Documents : version figée, procès-verbal, historique
    // ---------------------------------------------------------------------------------------------

    private static function fileSafe(string $text): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $text);
    }

    private static function renderFrozenHtml(Policy $policy, self $version): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/policy_version.html.twig', [
            'report_title'       => sprintf(
                __('%1$s, version %2$s', 'grcmanager'),
                (string) $policy->fields['title'],
                (string) $version->fields['version']
            ),
            'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
            'policy'             => [
                'title'       => (string) $policy->fields['title'],
                'version'     => (string) $policy->fields['version'],
                'owner'       => (int) $policy->fields['users_id'] > 0 ? getUserName((int) $policy->fields['users_id']) : '',
                'description' => (string) ($policy->fields['description'] ?? ''),
            ],
            'documents'          => self::policyDocuments($policy->getID()),
            'requested_by'       => getUserName((int) $version->fields['users_id']),
            'requested_at'       => Html::convDateTime($version->fields['date_creation']),
            'comment'            => (string) ($version->fields['comment'] ?? ''),
            'approvers'          => array_column(self::getApprovers($version->getID()), 'name'),
            'rule'               => self::ruleLabel((int) $version->fields['quorum']),
            'fingerprint'        => (string) $version->fields['fingerprint'],
            'control'            => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
        ]);
    }

    public static function ruleLabel(int $quorum): string
    {
        return PolicyApprovalConfig::quorumChoices(false)[$quorum] ?? (string) $quorum;
    }

    /**
     * Historique des approbations d'une politique, pour l'écran et le PDF.
     *
     * @param list<array<string, mixed>> $rows lignes de versions
     * @return list<array<string, mixed>>
     */
    public static function present(array $rows): array
    {
        $statuses = self::getStatuses();
        $answers = self::getAnswerLabels();
        $history = [];
        foreach ($rows as $row) {
            $approvers = [];
            foreach (self::getApprovers((int) $row['id']) as $approver) {
                $approvers[] = $approver + [
                    'status_label'  => $answers[$approver['status']] ?? $approver['status'],
                    'answered_text' => Html::convDateTime($approver['date_answered']),
                ];
            }
            $history[] = [
                'id'           => (int) $row['id'],
                'version'      => (string) $row['version'],
                'status'       => (string) $row['status'],
                'status_label' => $statuses[$row['status']] ?? (string) $row['status'],
                'is_legacy'    => !empty($row['is_legacy']),
                'requested_at' => Html::convDateTime($row['date_creation']),
                'requested_by' => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'answered_at'  => !empty($row['is_legacy'])
                    ? Html::convDate(substr((string) $row['date_answered'], 0, 10))
                    : Html::convDateTime($row['date_answered']),
                'comment'      => (string) ($row['comment'] ?? ''),
                'rule'         => self::ruleLabel((int) $row['quorum']),
                'fingerprint'  => (string) $row['fingerprint'],
                'pdf_sha256'   => (string) $row['pdf_sha256'],
                'pdf_url'      => (int) $row['documents_id'] > 0 ? self::getPageURL() . '?pdf=' . (int) $row['id'] : null,
                'approvers'    => $approvers,
            ];
        }

        return $history;
    }

    /**
     * Procès-verbal des approbations d'une politique (toutes les versions, ou une seule).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function renderRecordHtml(Policy $policy, array $rows): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/policy_approval_record.html.twig', [
            'report_title'       => sprintf(__('Approval record: %s', 'grcmanager'), (string) $policy->fields['title']),
            'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
            'policy_title'       => (string) $policy->fields['title'],
            'control'            => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
            'history'            => self::present($rows),
        ]);
    }

    /**
     * Procès-verbal de la version approuvée, archivé comme preuve sur la politique et sur A.5.1.
     */
    private static function archiveRecord(Policy $policy, self $version): int
    {
        global $DB;

        $pdf = PdfRenderer::renderHtmlToPdf(self::renderRecordHtml($policy, [$version->fields]));
        $targets = [[Policy::class, $policy->getID()]];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Control::getTable(),
                'WHERE'  => ['code' => self::CONTROL_CODE],
            ]) as $control
        ) {
            $targets[] = [Control::class, (int) $control['id']];
        }

        return self::storePdf(
            $version,
            $pdf,
            sprintf(
                'approbation-politique-%d-v%s-%s.pdf',
                $policy->getID(),
                self::fileSafe((string) $version->fields['version']),
                date('Ymd-His')
            ),
            sprintf(
                __('Approval record - %1$s %2$s', 'grcmanager'),
                (string) $policy->fields['title'],
                (string) $version->fields['version']
            ),
            $targets
        );
    }

    /**
     * Document GLPI réservé (lisible avec le droit de lire les politiques), lié aux éléments donnés.
     *
     * @param list<array{0: string, 1: int}> $targets
     */
    private static function storePdf(self $version, string $pdf, string $fileName, string $name, array $targets): int
    {
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => $name,
            'entities_id'   => 0,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        // Réservé avant d'être lié à la politique : il n'entre ni dans l'empreinte du contenu ni
        // dans les documents remis au public de la politique.
        RestrictedDocument::register($documentsId, $version);
        foreach ($targets as [$itemtype, $itemsId]) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
            ]);
        }

        return $documentsId;
    }

    /**
     * PDF figé d'une version : lecteurs des politiques, ou approbateur désigné de cette version.
     */
    public static function canReadPdf(int $versionId, int $usersId): bool
    {
        return self::canView() || self::isApprover($versionId, $usersId);
    }

    /**
     * Politique purgée : ses versions, réponses et approbateurs désignés disparaissent avec elle ;
     * les documents (version figée, procès-verbal) restent dans GLPI.
     */
    public static function purgePolicy(int $policyId): void
    {
        global $DB;

        $ids = array_map(static fn (array $row): int => (int) $row['id'], self::getVersions($policyId));
        if ($ids !== []) {
            $DB->delete(self::APPROVERS_TABLE, ['plugin_grcmanager_policyversions_id' => $ids]);
            $DB->delete(self::getTable(), ['id' => $ids]);
        }
        $DB->delete(self::DESIGNATED_TABLE, ['plugin_grcmanager_policies_id' => $policyId]);
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet « Approbation » de la politique
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Policy || $item->isNewItem() || !Policy::canView()) {
            return '';
        }
        // Circuit désactivé : l'onglet ne reste que pour l'historique des versions réellement soumises.
        $submitted = array_filter(self::getVersions($item->getID()), static fn (array $row): bool => empty($row['is_legacy']));
        if (!PolicyApprovalConfig::isEnabled() && $submitted === []) {
            return '';
        }

        return self::createTabEntry(__('Approval', 'grcmanager'), 0, null, 'ti ti-signature');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Policy || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForPolicy($item);

        return true;
    }

    private static function showForPolicy(Policy $policy): void
    {
        $policyId = $policy->getID();
        $own = self::getDesignated($policyId);
        $effective = self::effectiveDesignated($policyId);
        $canUpdate = $policy->can($policyId, UPDATE);
        $pending = self::latest($policyId, PolicyApprovalRules::VERSION_PENDING);
        $usersId = (int) Session::getLoginUserID();
        $status = (string) ($policy->fields['status'] ?? '');
        $policyQuorum = (int) ($policy->fields['approval_quorum'] ?? PolicyApprovalRules::QUORUM_DEFAULT);

        TemplateRenderer::getInstance()->display('@grcmanager/policy_approval_tab.html.twig', [
            'policy_id'        => $policyId,
            'enabled'          => PolicyApprovalConfig::isEnabled(),
            'status'           => $status,
            'version'          => (string) ($policy->fields['version'] ?? ''),
            'state'            => self::currentState($policy),
            'pending'          => $pending === null ? null : [
                'id'          => (int) $pending['id'],
                'version'     => (string) $pending['version'],
                'is_approver' => self::isApprover((int) $pending['id'], $usersId),
            ],
            'uses_default'     => $own === [],
            'approvers'        => self::describe($effective),
            'approver_count'   => count(self::resolveUsers($effective)),
            'rule'             => self::ruleLabel(self::effectiveQuorum($policy)),
            'can_update'       => $canUpdate,
            'can_submit'       => $canUpdate && $status === PolicyLifecycle::STATUS_DRAFT && $pending === null,
            'can_new_version'  => $canUpdate && $status === PolicyLifecycle::STATUS_APPROVED && $pending === null,
            'history'          => self::present(self::getVersions($policyId)),
            'action'           => self::getPageURL(),
            'export_url'       => self::getPageURL() . '?export=' . $policyId,
            'answer_url'       => self::getPageURL(),
            'csrf_token'       => Session::getNewCSRFToken(),
            'quorum_choices'   => PolicyApprovalConfig::quorumChoices(true),
            'policy_quorum'    => $policyQuorum,
            'user_dropdown'    => $canUpdate ? User::dropdown([
                'name'     => 'approver_users',
                'value'    => array_column(array_filter($own, static fn (array $a): bool => $a['itemtype'] === User::class), 'items_id'),
                'multiple' => true,
                'width'    => '100%',
                'right'    => 'all',
                'display'  => false,
                'rand'     => 'grcpolicyapprovers',
            ]) : '',
            'group_dropdown'   => $canUpdate ? Group::dropdown([
                'name'     => 'approver_groups',
                'values'   => array_column(array_filter($own, static fn (array $a): bool => $a['itemtype'] === Group::class), 'items_id'),
                'multiple' => true,
                'width'    => '100%',
                'entity'   => 0,
                'entity_sons' => true,
                'display'  => false,
                'rand'     => 'grcpolicyapprovergroups',
            ]) : '',
        ]);
    }
}
