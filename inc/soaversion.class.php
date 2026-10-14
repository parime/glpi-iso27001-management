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

use GlpiPlugin\Grcmanager\Compatibility\Base\SoaVersionBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\Control\SoaReportBuilder;

/**
 * Issue #112 : version figée de la Déclaration d'Applicabilité soumise à l'approbation de la
 * direction (clause 5.1, engagement de la direction ; 6.1.3 d). Approbation native, sans
 * dépendance à assetsign (choix du mainteneur) :
 *
 * - une demande fige le rapport PDF de la SoA (SoaReportBuilder, le même que l'export) dans un
 *   Document GLPI lié à la version, avec l'empreinte SHA-256 du fichier et celle du contenu
 *   (SoaApprovalLogic::fingerprint()) ;
 * - chaque approbateur désigné approuve ou refuse depuis sa session GLPI authentifiée
 *   (front/soaapproval.php), date et commentaire conservés ; un refus suffit à refuser la version,
 *   l'accord de tous l'approuve ;
 * - une nouvelle demande rend obsolète toute version encore en attente ; toute modification
 *   ultérieure de la SoA est détectée par l'empreinte de contenu et appelle une nouvelle version.
 */
class PluginGrcmanagerSoaVersion extends SoaVersionBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const APPROVERS_TABLE = 'glpi_plugin_grcmanager_soaversions_users';

    public const REQUEST_EVENT = 'soa_approval_request';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_soaversions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Version approuvée de la SoA', 'Versions approuvées de la SoA', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-signature';
    }

    // Versions créées uniquement par requestApproval() et jamais supprimées : c'est l'historique
    // des approbations que l'auditeur consulte.
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

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            SoaApprovalLogic::VERSION_PENDING  => __('En attente d\'approbation', 'grcmanager'),
            SoaApprovalLogic::VERSION_APPROVED => __('Approuvée', 'grcmanager'),
            SoaApprovalLogic::VERSION_REJECTED => __('Refusée', 'grcmanager'),
            SoaApprovalLogic::VERSION_OBSOLETE => __('Obsolète', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAnswerLabels(): array
    {
        return [
            SoaApprovalLogic::ANSWER_PENDING  => __('En attente', 'grcmanager'),
            SoaApprovalLogic::ANSWER_APPROVED => __('Approuvé', 'grcmanager'),
            SoaApprovalLogic::ANSWER_REJECTED => __('Refusé', 'grcmanager'),
        ];
    }

    public static function currentFingerprint(): string
    {
        global $DB;

        return SoaApprovalLogic::fingerprint($DB->request(['FROM' => PluginGrcmanagerControl::getTable()]));
    }

    /**
     * @return array<string, mixed>|null dernière version au statut donné
     */
    public static function latest(string $status): ?array
    {
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['status' => $status],
                'ORDER' => 'version DESC',
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
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
                'WHERE' => ['plugin_grcmanager_soaversions_id' => $versionId],
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
     * Versions en attente pour lesquelles $usersId n'a pas encore répondu.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingForUser(int $usersId): array
    {
        global $DB;

        $versions = [];
        foreach (
            $DB->request([
                'SELECT'     => ['versions.*'],
                'FROM'       => self::getTable() . ' AS versions',
                'INNER JOIN' => [
                    self::APPROVERS_TABLE . ' AS approvers' => [
                        'FKEY' => ['approvers' => 'plugin_grcmanager_soaversions_id', 'versions' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'versions.status'    => SoaApprovalLogic::VERSION_PENDING,
                    'approvers.users_id' => $usersId,
                    'approvers.status'   => SoaApprovalLogic::ANSWER_PENDING,
                ],
                'ORDER'      => 'versions.version DESC',
            ]) as $row
        ) {
            $versions[] = $row;
        }

        return $versions;
    }

    /**
     * Fige la SoA actuelle et la soumet aux approbateurs.
     *
     * @param list<int> $approverIds
     * @return int ID de la nouvelle version, 0 en cas d'échec
     */
    public static function requestApproval(array $approverIds, string $comment): int
    {
        global $DB;

        $approverIds = array_values(array_unique(array_filter(
            array_map('intval', $approverIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($approverIds === []) {
            Session::addMessageAfterRedirect(__('Choisissez au moins un approbateur.', 'grcmanager'), false, ERROR);

            return 0;
        }

        $lastVersion = (int) ($DB->request([
            'SELECT' => ['MAX' => 'version AS v'],
            'FROM'   => self::getTable(),
        ])->current()['v'] ?? 0);
        $versionNumber = $lastVersion + 1;
        $fingerprint   = self::currentFingerprint();
        $requestedBy   = (int) Session::getLoginUserID();
        $now           = date('Y-m-d H:i:s');

        // Une version encore en attente devient obsolète : elle ne décrit plus la SoA à approuver.
        $DB->update(
            self::getTable(),
            ['status' => SoaApprovalLogic::VERSION_OBSOLETE, 'date_mod' => $now],
            ['status' => SoaApprovalLogic::VERSION_PENDING]
        );

        $version = new self();
        $id = (int) $version->add([
            'version'     => $versionNumber,
            'status'      => SoaApprovalLogic::VERSION_PENDING,
            'fingerprint' => $fingerprint,
            'users_id'    => $requestedBy,
            'comment'     => $comment,
        ]);
        if ($id <= 0) {
            return 0;
        }

        foreach ($approverIds as $approverId) {
            $DB->insert(self::APPROVERS_TABLE, [
                'plugin_grcmanager_soaversions_id' => $id,
                'users_id'                         => $approverId,
                'status'                           => SoaApprovalLogic::ANSWER_PENDING,
                'date_creation'                    => $now,
            ]);
        }

        $pdf = PdfRenderer::renderHtmlToPdf(SoaReportBuilder::renderHtml([
            'version'      => $versionNumber,
            'requested_at' => Html::convDateTime($now),
            'requested_by' => getUserName($requestedBy),
            'fingerprint'  => $fingerprint,
            'approvers'    => array_map(static fn (int $userId): string => getUserName($userId), $approverIds),
        ]));
        $documentsId = self::attachPdf($id, $versionNumber, $pdf);

        $version->update([
            'id'           => $id,
            'documents_id' => $documentsId,
            'pdf_sha256'   => hash('sha256', $pdf),
        ]);

        NotificationEvent::raiseEvent(self::REQUEST_EVENT, $version, ['entities_id' => 0]);

        return $id;
    }

    /**
     * Réponse d'un approbateur désigné (seulement tant que la version est en attente).
     */
    public static function answer(int $versionId, int $usersId, bool $approve, string $comment): bool
    {
        global $DB;

        $version = new self();
        if (!$version->getFromDB($versionId) || $version->fields['status'] !== SoaApprovalLogic::VERSION_PENDING) {
            return false;
        }

        $DB->update(self::APPROVERS_TABLE, [
            'status'        => $approve ? SoaApprovalLogic::ANSWER_APPROVED : SoaApprovalLogic::ANSWER_REJECTED,
            'comment'       => $comment,
            'date_answered' => date('Y-m-d H:i:s'),
        ], [
            'plugin_grcmanager_soaversions_id' => $versionId,
            'users_id'                         => $usersId,
            'status'                           => SoaApprovalLogic::ANSWER_PENDING,
        ]);
        if ($DB->affectedRows() === 0) {
            return false;
        }

        $status = SoaApprovalLogic::versionStatus(array_column(self::getApprovers($versionId), 'status'));
        if ($status !== SoaApprovalLogic::VERSION_PENDING) {
            $version->update([
                'id'            => $versionId,
                'status'        => $status,
                'date_answered' => date('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }

    private static function attachPdf(int $versionId, int $versionNumber, string $pdf): int
    {
        // Document::add() déplace le fichier depuis GLPI_TMP_DIR (même procédé qu'assetsign).
        $fileName = sprintf('soa-version-%d-%s.pdf', $versionNumber, date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }

        // Document::add() empile des messages techniques (« Création du répertoire PDF/8f »,
        // « Document copié avec succès ») qui n'ont pas de sens pour l'utilisateur : on les retire.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $document = new Document();
        $documentsId = (int) $document->add([
            'name'          => sprintf(__('Déclaration d\'Applicabilité - version %d', 'grcmanager'), $versionNumber),
            'entities_id'   => 0,
            'is_recursive'  => 1,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId > 0) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => self::class,
                'items_id'     => $versionId,
            ]);
        }

        return $documentsId;
    }
}
