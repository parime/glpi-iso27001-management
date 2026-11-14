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
use CronTask;
use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\AuditEvidenceRequestBase;
use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestCsv;
use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestRules as Rules;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;
use Html;
use NotificationEvent;
use Session;
use User;

/**
 * Issue #538 : demandes de preuves d'un audit (onglet « Demandes de preuves » de l'audit).
 *
 * Pendant un audit de certification, l'auditeur remet une liste de pièces à fournir. Chaque demande
 * vise une mesure de l'Annexe A ou une exigence libre, est confiée à une personne avec une
 * échéance, et suit le cycle « à fournir » -> « soumise » -> « acceptée » ou « à compléter » (avec
 * un motif) : EvidenceRequestRules.
 *
 * Droits :
 * - créer, modifier, revoir (accepter, renvoyer à compléter), relancer et supprimer une demande :
 *   le droit de modifier l'audit ; la lire : le droit de lire l'audit ;
 * - fournir les pièces et soumettre : la seule personne désignée, même sans aucun droit sur le
 *   plugin ni sur les documents (page « Mes demandes de preuves »). C'est la demande qui l'autorise à
 *   déposer un fichier ; elle ne peut joindre une preuve existante de la mesure que si elle peut
 *   lire ce document dans ses entités ;
 * - télécharger une pièce : la personne désignée, l'équipe de l'audit et le groupe RSSI
 *   (AuditTeam), et l'auditeur dont l'accès est en vigueur, pour les seules pièces soumises
 *   (AuditorAccess). Chaque téléchargement est historisé (DocumentAccess).
 *
 * Un fichier déposé devient un document de l'audit, au registre des documents restreints : seule
 * l'équipe de l'audit le lit par GLPI. Une preuve existante de la mesure n'est que citée : elle
 * garde son audience, rien ne la rattache à l'audit.
 *
 * Aucune action automatique ne change un statut, une échéance ou une attribution : la tâche
 * planifiée ne fait que relancer la personne désignée.
 */
class AuditEvidenceRequest extends AuditEvidenceRequestBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const PIECES_TABLE = 'glpi_plugin_grcmanager_auditevidencepieces';

    public const NEW_EVENT = 'evidence_request_new';
    public const REMINDER_EVENT = 'evidence_request_reminder';
    public const SUBMITTED_EVENT = 'evidence_request_submitted';
    public const TO_COMPLETE_EVENT = 'evidence_request_to_complete';

    public const ORIGIN_UPLOAD = 'upload';
    public const ORIGIN_EXISTING = 'existing';

    /** Champs que seules les actions du cycle (soumission, revue, relance) écrivent. */
    private const WORKFLOW_FIELDS = [
        'status', 'submission_comment', 'review_comment', 'users_id_reviewer', 'date_submitted',
        'date_last_submission', 'date_review', 'date_reminder', 'users_id_requester',
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_auditevidencerequests';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evidence request', 'Evidence requests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-search';
    }

    public static function getNameField()
    {
        return 'reference';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditevidencerequest.form.php';
    }

    /** Page « Mes demandes de preuves » de la personne connectée. */
    public static function getMineURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/auditevidencerequest.mine.php';
    }

    /** Même page, adresse absolue (notifications). */
    public static function getMineAbsoluteURL(): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/auditevidencerequest.mine.php';
    }

    public static function getDownloadURL(int $pieceId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/auditevidencerequest.send.php?id=' . $pieceId;
    }

    /** Onglet « Demandes de preuves » de l'audit. */
    public static function getAuditTabURL(int $auditId): string
    {
        return Audit::getFormURLWithID($auditId) . '&forcetab=' . rawurlencode(self::class . '$1');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> statut => libellé, classe, icône
     */
    public static function getStatuses(): array
    {
        return [
            Rules::STATUS_REQUESTED   => [__('To provide', 'grcmanager'), 'bg-orange-lt', 'ti-hourglass'],
            Rules::STATUS_SUBMITTED   => [_x('feminine', 'Submitted', 'grcmanager'), 'bg-blue-lt', 'ti-send'],
            Rules::STATUS_TO_COMPLETE => [__('To complete', 'grcmanager'), 'bg-red-lt', 'ti-alert-circle'],
            Rules::STATUS_ACCEPTED    => [_x('feminine', 'Accepted', 'grcmanager'), 'bg-green-lt', 'ti-circle-check'],
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        return self::getStatuses()[$status][0] ?? $status;
    }

    // ---------------------------------------------------------------------------------------------
    // Droits : une demande est un élément de son audit
    // ---------------------------------------------------------------------------------------------

    public static function loadAudit(int $auditId): ?Audit
    {
        $audit = new Audit();

        return $auditId > 0 && $audit->getFromDB($auditId) ? $audit : null;
    }

    public function getAudit(): ?Audit
    {
        return self::loadAudit((int) ($this->fields['plugin_grcmanager_audits_id'] ?? 0));
    }

    public function canViewItem(): bool
    {
        $audit = $this->getAudit();

        return $audit !== null && $audit->can($audit->getID(), READ);
    }

    public function canUpdateItem(): bool
    {
        $audit = $this->getAudit();

        return $audit !== null && $audit->can($audit->getID(), UPDATE);
    }

    public function canCreateItem(): bool
    {
        return $this->canUpdateItem();
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    public function isAssignee(int $usersId): bool
    {
        return $usersId > 0 && (int) ($this->fields['users_id'] ?? 0) === $usersId;
    }

    // ---------------------------------------------------------------------------------------------
    // Création et modification
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        if (self::loadAudit((int) ($input['plugin_grcmanager_audits_id'] ?? 0)) === null) {
            Session::addMessageAfterRedirect(__('Audit not found.', 'grcmanager'), false, ERROR);

            return false;
        }
        foreach (self::WORKFLOW_FIELDS as $field) {
            unset($input[$field]);
        }
        $input = self::normalizeInput($input);
        if (($input['reference'] ?? '') === '' && trim((string) ($input['description'] ?? '')) === '') {
            Session::addMessageAfterRedirect(
                __('Give at least a reference or a description of the requested evidence.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        $input['status'] = Rules::STATUS_REQUESTED;
        $input['users_id_requester'] = (int) Session::getLoginUserID();

        return $input;
    }

    /**
     * L'audit d'une demande ne change jamais ; son statut et ses dates ne changent que par les
     * actions du cycle (option interne `_workflow`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForUpdate($input)
    {
        unset($input['plugin_grcmanager_audits_id']);
        if (empty($input['_workflow'])) {
            foreach (self::WORKFLOW_FIELDS as $field) {
                unset($input[$field]);
            }
        }
        $input = self::normalizeInput($input);
        if (
            array_key_exists('reference', $input) && $input['reference'] === ''
            && trim((string) ($input['description'] ?? ($this->fields['description'] ?? ''))) === ''
        ) {
            unset($input['reference']);
        }
        // Nouvelle échéance : la relance « une semaine avant » repart de zéro.
        if (
            array_key_exists('due_date', $input) && empty($input['_workflow'])
            && $input['due_date'] !== ($this->fields['due_date'] ?? null)
        ) {
            $input['date_reminder'] = null;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $input): array
    {
        if (array_key_exists('reference', $input)) {
            $input['reference'] = Rules::reference($input['reference']);
        }
        if (array_key_exists('description', $input)) {
            $input['description'] = trim((string) $input['description']);
        }
        if (array_key_exists('due_date', $input)) {
            $input['due_date'] = Rules::parseDate($input['due_date']);
        }
        if (array_key_exists('plugin_grcmanager_controls_id', $input)) {
            $controlId = max(0, (int) $input['plugin_grcmanager_controls_id']);
            $control = new Control();
            $known = $controlId > 0 && $control->getFromDB($controlId);
            $input['plugin_grcmanager_controls_id'] = $known ? $controlId : 0;
        }
        if (array_key_exists('users_id', $input)) {
            $usersId = max(0, (int) $input['users_id']);
            $input['users_id'] = $usersId > 0 && self::isActiveUser($usersId) ? $usersId : 0;
        }

        return $input;
    }

    private static function isActiveUser(int $usersId): bool
    {
        $user = new User();

        return $user->getFromDB($usersId) && (int) $user->fields['is_active'] === 1
            && (int) $user->fields['is_deleted'] === 0;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        if ((int) ($this->fields['users_id'] ?? 0) > 0) {
            $this->raise(self::NEW_EVENT);
        }
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        // Nouvelle personne désignée : elle est prévenue comme pour une nouvelle demande.
        if (
            empty($this->input['_workflow']) && in_array('users_id', $this->updates, true)
            && (int) $this->fields['users_id'] > 0
            && Rules::isWaitingForAssignee((string) $this->fields['status'])
        ) {
            $this->raise(self::NEW_EVENT);
        }
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // Les fichiers déposés restent des documents de l'audit (onglet Documents).
        $DB->delete(self::PIECES_TABLE, ['plugin_grcmanager_auditevidencerequests_id' => $this->getID()]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function raise(string $event, array $options = []): void
    {
        NotificationEvent::raiseEvent($event, $this, ['entities_id' => 0] + $options);
    }

    /** Audit purgé : ses demandes et leurs pièces disparaissent avec lui. */
    public static function purgeAudit(int $auditId): void
    {
        global $DB;

        $ids = array_column(iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_grcmanager_audits_id' => $auditId],
        ]), false), 'id');
        if ($ids !== []) {
            $DB->delete(self::PIECES_TABLE, ['plugin_grcmanager_auditevidencerequests_id' => $ids]);
            $DB->delete(self::getTable(), ['id' => $ids]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------

    /**
     * Demandes d'un audit, dans l'ordre de la liste de l'auditeur.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForAudit(int $auditId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_audits_id' => $auditId],
            'ORDER' => ['id'],
        ]), false));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRow(int $requestId): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $requestId]])->current();

        return $row === null ? null : $row;
    }

    /**
     * Avancement d'un audit (toutes ses demandes).
     *
     * @return array<string, mixed>
     */
    public static function progressForAudit(int $auditId): array
    {
        return Rules::progress(array_map(
            static fn (array $row): string => (string) $row['status'],
            self::getForAudit($auditId)
        ));
    }

    /**
     * Pièces d'une demande, avec le nom du document.
     *
     * @return list<array{id: int, documents_id: int, name: string, origin: string, users_id: int,
     *     is_submitted: bool, date_submitted: ?string, date_creation: ?string}>
     */
    public static function getPieces(int $requestId): array
    {
        global $DB;

        $pieces = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'p.id', 'p.documents_id', 'p.origin', 'p.users_id', 'p.is_submitted', 'p.date_submitted',
                    'p.date_creation', 'd.name', 'd.filename',
                ],
                'FROM'      => self::PIECES_TABLE . ' AS p',
                'LEFT JOIN' => [Document::getTable() . ' AS d' => ['FKEY' => ['p' => 'documents_id', 'd' => 'id']]],
                'WHERE'     => ['p.plugin_grcmanager_auditevidencerequests_id' => $requestId],
                'ORDER'     => ['p.id'],
            ]) as $row
        ) {
            $pieces[] = [
                'id'             => (int) $row['id'],
                'documents_id'   => (int) $row['documents_id'],
                'name'           => (string) (($row['name'] ?? '') !== '' ? $row['name'] : ($row['filename'] ?? '')),
                'origin'         => (string) $row['origin'],
                'users_id'       => (int) $row['users_id'],
                'is_submitted'   => (int) $row['is_submitted'] === 1,
                'date_submitted' => $row['date_submitted'] ?: null,
                'date_creation'  => $row['date_creation'] ?: null,
            ];
        }

        return $pieces;
    }

    /**
     * @return array<string, mixed>|null pièce avec l'id de sa demande
     */
    public static function getPieceRow(int $pieceId): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::PIECES_TABLE, 'WHERE' => ['id' => $pieceId]])->current();

        return $row === null ? null : $row;
    }

    /**
     * Demandes confiées à la personne, les plus urgentes d'abord (Mon espace GRC, page « Mes
     * demandes de preuves »). Les demandes d'un audit annulé ne sont plus montrées.
     *
     * @return list<array<string, mixed>>
     */
    public static function getAssignedTo(int $usersId, bool $waitingOnly = false): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }
        $where = ['r.users_id' => $usersId, 'NOT' => ['a.status' => 'cancelled']];
        if ($waitingOnly) {
            $where['r.status'] = [Rules::STATUS_REQUESTED, Rules::STATUS_TO_COMPLETE];
        }

        return array_values(iterator_to_array($DB->request([
            'SELECT'     => ['r.*', 'a.title AS audit_title', 'a.status AS audit_status'],
            'FROM'       => self::getTable() . ' AS r',
            'INNER JOIN' => [
                Audit::getTable() . ' AS a' => ['FKEY' => ['r' => 'plugin_grcmanager_audits_id', 'a' => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => ['r.due_date', 'r.id'],
        ]), false));
    }

    /**
     * Demandes soumises qui attendent la revue, sur les audits dont la personne est l'auditeur ou
     * le responsable.
     *
     * @return list<array<string, mixed>>
     */
    public static function getToReviewBy(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }

        return array_values(iterator_to_array($DB->request([
            'SELECT'     => ['r.*', 'a.title AS audit_title'],
            'FROM'       => self::getTable() . ' AS r',
            'INNER JOIN' => [
                Audit::getTable() . ' AS a' => ['FKEY' => ['r' => 'plugin_grcmanager_audits_id', 'a' => 'id']],
            ],
            'WHERE'      => [
                'r.status' => Rules::STATUS_SUBMITTED,
                'a.status' => ['planned', 'in_progress'],
                'OR'       => ['a.users_id' => $usersId, 'a.users_id_responsible' => $usersId],
            ],
            'ORDER'      => ['r.date_last_submission', 'r.id'],
        ]), false));
    }

    /** Libellé d'une demande : sa référence, sinon le début de sa description. */
    public static function label(array $row): string
    {
        $reference = trim((string) ($row['reference'] ?? ''));
        if ($reference !== '') {
            return $reference;
        }
        $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['description'] ?? '')));

        return mb_strlen($description) > 80 ? mb_substr($description, 0, 79) . '…' : $description;
    }

    public static function controlLabel(int $controlId): string
    {
        if ($controlId <= 0) {
            return '';
        }
        $control = new Control();
        if (!$control->getFromDB($controlId)) {
            return '';
        }
        $code = (string) $control->fields['code'];

        return trim($code . ' ' . Control::getControlTitle($code));
    }

    // ---------------------------------------------------------------------------------------------
    // Pièces fournies par la personne désignée
    // ---------------------------------------------------------------------------------------------

    /**
     * Charge une demande à laquelle la personne peut encore joindre une pièce.
     *
     * @return self|string la demande, ou le message d'erreur
     */
    private static function loadForAssignee(int $requestId, int $usersId): self|string
    {
        $request = new self();
        if (!$request->getFromDB($requestId) || !$request->isAssignee($usersId)) {
            return __('This evidence request is not assigned to you.', 'grcmanager');
        }
        $audit = $request->getAudit();
        if ($audit === null || ($audit->fields['status'] ?? '') === 'cancelled') {
            return __('This audit has been cancelled.', 'grcmanager');
        }
        if (!Rules::canAddPiece((string) $request->fields['status'])) {
            return __('This request is no longer waiting for evidence.', 'grcmanager');
        }

        return $request;
    }

    /**
     * Dépôt d'un fichier par la personne désignée : il devient un document de l'audit, réservé à
     * l'équipe de l'audit (RestrictedDocument), et une pièce en préparation de la demande.
     *
     * @return string message d'erreur, chaîne vide si la pièce est ajoutée
     */
    public static function addUploadedPiece(int $requestId, int $usersId, string $path, string $clientName): string
    {
        global $DB;

        $request = self::loadForAssignee($requestId, $usersId);
        if (is_string($request)) {
            return $request;
        }
        $auditId = (int) $request->fields['plugin_grcmanager_audits_id'];
        // La demande autorise le dépôt : pas de droit GLPI « créer un document » exigé.
        $documentsId = ControlTest::createEvidenceDocument($path, $clientName, [
            'itemtype' => Audit::class,
            'items_id' => $auditId,
            'comment'  => sprintf(
                __('Evidence provided for the request "%s"', 'grcmanager'),
                self::label($request->fields)
            ),
        ], false);
        if ($documentsId <= 0) {
            // Motif déjà en session (createEvidenceDocument()).
            return '-';
        }
        $audit = $request->getAudit();
        if ($audit !== null) {
            RestrictedDocument::registerIfAbsent($documentsId, $audit);
        }
        $DB->insert(self::PIECES_TABLE, [
            'plugin_grcmanager_auditevidencerequests_id' => $requestId,
            'documents_id'                               => $documentsId,
            'origin'                                     => self::ORIGIN_UPLOAD,
            'users_id'                                   => $usersId,
            'is_submitted'                               => 0,
            'date_creation'                              => date('Y-m-d H:i:s'),
        ]);

        return '';
    }

    /**
     * Preuves existantes que la personne peut joindre : documents rattachés à la mesure visée,
     * lisibles par elle dans ses entités, hors documents restreints d'une autre source (rapport
     * d'incident, copie de quiz…), et pas encore joints.
     *
     * @param array<string, mixed> $request
     * @return list<array{documents_id: int, name: string, validity: string, valid_until: ?string}>
     */
    public static function getSelectableDocuments(array $request): array
    {
        $controlId = (int) ($request['plugin_grcmanager_controls_id'] ?? 0);
        $control = new Control();
        if ($controlId <= 0 || !$control->getFromDB($controlId)) {
            return [];
        }
        $already = array_column(self::getPieces((int) $request['id']), 'documents_id');
        $today = date('Y-m-d');
        $list = [];
        foreach (Evidence::getDocumentsFor($control) as $doc) {
            $documentsId = $doc['documents_id'];
            if (in_array($documentsId, $already, true) || !Evidence::canReadDocument($documentsId)) {
                continue;
            }
            $source = RestrictedDocument::sourceOf($documentsId);
            $allowedSource = $source === null
                || ($source['itemtype'] === Control::class && $source['items_id'] === $controlId)
                || ($source['itemtype'] === Audit::class
                    && $source['items_id'] === (int) $request['plugin_grcmanager_audits_id']);
            if (!$allowedSource) {
                continue;
            }
            $list[] = [
                'documents_id' => $documentsId,
                'name'         => $doc['name'],
                'validity'     => EvidenceValidity::status($doc['valid_until'], $today),
                'valid_until'  => $doc['valid_until'],
            ];
        }

        return $list;
    }

    /**
     * @return string message d'erreur, chaîne vide si la preuve est jointe
     */
    public static function addExistingPiece(int $requestId, int $usersId, int $documentsId): string
    {
        global $DB;

        $request = self::loadForAssignee($requestId, $usersId);
        if (is_string($request)) {
            return $request;
        }
        $selectable = array_column(self::getSelectableDocuments($request->fields), 'documents_id');
        if (!in_array($documentsId, $selectable, true)) {
            return __('This document cannot be added to the request.', 'grcmanager');
        }
        $DB->insert(self::PIECES_TABLE, [
            'plugin_grcmanager_auditevidencerequests_id' => $requestId,
            'documents_id'                               => $documentsId,
            'origin'                                     => self::ORIGIN_EXISTING,
            'users_id'                                   => $usersId,
            'is_submitted'                               => 0,
            'date_creation'                              => date('Y-m-d H:i:s'),
        ]);

        return '';
    }

    /**
     * Retrait d'une pièce en préparation (jamais d'une pièce soumise). Un fichier déposé pour la
     * demande est supprimé avec elle ; une preuve existante n'est que retirée de la demande.
     *
     * @return string message d'erreur, chaîne vide si la pièce est retirée
     */
    public static function removePiece(int $pieceId, int $usersId): string
    {
        global $DB;

        $piece = self::getPieceRow($pieceId);
        if ($piece === null) {
            return __('This document cannot be removed.', 'grcmanager');
        }
        $request = self::loadForAssignee((int) $piece['plugin_grcmanager_auditevidencerequests_id'], $usersId);
        if (is_string($request)) {
            return $request;
        }
        if ((int) $piece['is_submitted'] === 1) {
            return __('A document already submitted to the audit cannot be removed.', 'grcmanager');
        }
        $DB->delete(self::PIECES_TABLE, ['id' => $pieceId]);
        if ($piece['origin'] === self::ORIGIN_UPLOAD) {
            $document = new Document();
            if ($document->getFromDB((int) $piece['documents_id'])) {
                $document->delete(['id' => $document->getID()], true);
            }
        }

        return '';
    }

    /**
     * Soumission par la personne désignée : les pièces en préparation deviennent visibles de
     * l'auditeur, et l'audit est prévenu.
     *
     * @return string message d'erreur, chaîne vide si la demande est soumise
     */
    public static function submit(int $requestId, int $usersId, string $comment = ''): string
    {
        global $DB;

        $request = self::loadForAssignee($requestId, $usersId);
        if (is_string($request)) {
            return $request;
        }
        if (!Rules::canSubmit((string) $request->fields['status'], count(self::getPieces($requestId)))) {
            return __('Add at least one document before submitting.', 'grcmanager');
        }
        $now = date('Y-m-d H:i:s');
        $request->update([
            'id'                   => $requestId,
            'status'               => Rules::STATUS_SUBMITTED,
            'submission_comment'   => trim($comment),
            'date_submitted'       => $request->fields['date_submitted'] ?: $now,
            'date_last_submission' => $now,
            '_workflow'            => true,
        ]);
        $DB->update(
            self::PIECES_TABLE,
            ['is_submitted' => 1, 'date_submitted' => $now],
            ['plugin_grcmanager_auditevidencerequests_id' => $requestId, 'is_submitted' => 0]
        );
        $request->getFromDB($requestId);
        $request->raise(self::SUBMITTED_EVENT);

        return '';
    }

    // ---------------------------------------------------------------------------------------------
    // Revue, relances, import
    // ---------------------------------------------------------------------------------------------

    /**
     * Revue d'une demande soumise : acceptée, ou renvoyée « à compléter » avec un motif (la
     * personne est prévenue). Exige le droit de modifier l'audit.
     *
     * @return string message d'erreur, chaîne vide si la décision est enregistrée
     */
    public static function review(int $requestId, int $reviewerId, string $decision, string $reason): string
    {
        $request = new self();
        if (!$request->getFromDB($requestId) || !$request->canUpdateItem()) {
            return __('You are not allowed to review this request.', 'grcmanager');
        }
        $status = Rules::statusAfterReview((string) $request->fields['status'], $decision, $reason);
        if ($status === null) {
            return Rules::canReview((string) $request->fields['status'])
                ? __('Give the reason why the evidence must be completed.', 'grcmanager')
                : __('Only a submitted request can be reviewed.', 'grcmanager');
        }
        $now = date('Y-m-d H:i:s');
        $input = [
            'id'                => $requestId,
            'status'            => $status,
            'review_comment'    => trim($reason),
            'users_id_reviewer' => $reviewerId,
            'date_review'       => $now,
            '_workflow'         => true,
        ];
        if ($status === Rules::STATUS_TO_COMPLETE) {
            // La notification « à compléter » tient lieu de relance du jour.
            $input['date_reminder'] = $now;
        }
        $request->update($input);
        if ($status === Rules::STATUS_TO_COMPLETE) {
            $request->getFromDB($requestId);
            $request->raise(self::TO_COMPLETE_EVENT);
        }

        return '';
    }

    /**
     * Relances par notification, sans rien changer d'autre que la date de relance. Par la tâche
     * planifiée ($auditId = 0) : les demandes dont la relance est due (EvidenceRequestRules) ; à la
     * demande sur un audit : toutes ses demandes qui attendent encore leur personne désignée.
     *
     * @return int nombre de demandes relancées
     */
    public static function sendReminders(string $today, int $auditId = 0): int
    {
        global $DB;

        $where = [
            'r.status'   => [Rules::STATUS_REQUESTED, Rules::STATUS_TO_COMPLETE],
            'r.users_id' => ['>', 0],
            'a.status'   => ['planned', 'in_progress'],
        ];
        if ($auditId > 0) {
            $where['r.plugin_grcmanager_audits_id'] = $auditId;
        }
        $count = 0;
        foreach (
            $DB->request([
                'SELECT'     => ['r.id', 'r.status', 'r.due_date', 'r.date_reminder'],
                'FROM'       => self::getTable() . ' AS r',
                'INNER JOIN' => [
                    Audit::getTable() . ' AS a' => ['FKEY' => ['r' => 'plugin_grcmanager_audits_id', 'a' => 'id']],
                ],
                'WHERE'      => $where,
            ]) as $row
        ) {
            $due = $auditId > 0 || Rules::isReminderDue(
                (string) $row['status'],
                $row['due_date'] ?: null,
                $row['date_reminder'] ?: null,
                $today
            );
            $request = new self();
            if (!$due || !$request->getFromDB((int) $row['id'])) {
                continue;
            }
            $request->raise(self::REMINDER_EVENT);
            $DB->update(self::getTable(), ['date_reminder' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public static function cronInfo(string $name): array
    {
        return [
            'description' => __(
                'Reminds the people who must provide audit evidence, one week before the deadline then weekly',
                'grcmanager'
            ),
        ];
    }

    public static function cronAuditevidencereminder(CronTask $task): int
    {
        $count = self::sendReminders(date('Y-m-d'));
        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /**
     * Import de la liste de l'auditeur (CSV) dans un audit. Les lignes valides sont créées ; les
     * autres sont signalées avec leur numéro. Mesure inconnue ou compte introuvable : ligne refusée.
     *
     * @param array{users_id?: int, due_date?: ?string} $defaults personne et échéance par défaut
     * @return array{created: int, errors: list<string>}
     */
    public static function importCsv(Audit $audit, string $content, array $defaults = []): array
    {
        global $DB;

        $parsed = EvidenceRequestCsv::parse($content);
        $errors = [];
        foreach ($parsed['errors'] as $error) {
            $errors[] = self::csvErrorMessage($error['line'], $error['error']);
        }
        $defaultUser = (int) ($defaults['users_id'] ?? 0);
        $defaultUser = $defaultUser > 0 && self::isActiveUser($defaultUser) ? $defaultUser : 0;
        $defaultDue = Rules::parseDate($defaults['due_date'] ?? null);
        $created = 0;
        foreach ($parsed['rows'] as $row) {
            $controlId = 0;
            if ($row['control'] !== '') {
                $control = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => Control::getTable(),
                    'WHERE'  => ['code' => $row['control']],
                    'LIMIT'  => 1,
                ])->current();
                if ($control === null) {
                    $errors[] = sprintf(
                        __('Line %1$d: unknown control "%2$s".', 'grcmanager'),
                        $row['line'],
                        $row['control']
                    );
                    continue;
                }
                $controlId = (int) $control['id'];
            }
            $usersId = $defaultUser;
            if ($row['assignee'] !== '') {
                $user = new User();
                if (
                    !$user->getFromDBbyName($row['assignee']) || (int) $user->fields['is_active'] !== 1
                    || (int) $user->fields['is_deleted'] !== 0
                ) {
                    $errors[] = sprintf(
                        __('Line %1$d: no active account with the login "%2$s".', 'grcmanager'),
                        $row['line'],
                        $row['assignee']
                    );
                    continue;
                }
                $usersId = (int) $user->getID();
            }
            $id = (new self())->add([
                'plugin_grcmanager_audits_id'   => $audit->getID(),
                'plugin_grcmanager_controls_id' => $controlId,
                'reference'                     => $row['reference'],
                'description'                   => $row['description'],
                'users_id'                      => $usersId,
                'due_date'                      => $row['due_date'] ?? $defaultDue,
            ]);
            if ($id) {
                $created++;
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    private static function csvErrorMessage(int $line, string $error): string
    {
        return match ($error) {
            EvidenceRequestCsv::ERROR_NO_HEADER => __(
                'The file has no recognised header line: name the columns "Reference", "Control", "Description", '
                    . '"Due date" and "Assignee".',
                'grcmanager'
            ),
            EvidenceRequestCsv::ERROR_TOO_MANY => sprintf(
                __('Line %1$d: at most %2$d requests per import, the following lines were ignored.', 'grcmanager'),
                $line,
                EvidenceRequestCsv::MAX_ROWS
            ),
            EvidenceRequestCsv::ERROR_BAD_DATE => sprintf(
                __('Line %d: the due date is not a valid date (YYYY-MM-DD or DD/MM/YYYY).', 'grcmanager'),
                $line
            ),
            default => sprintf(__('Line %d: no reference and no description.', 'grcmanager'), $line),
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Téléchargement
    // ---------------------------------------------------------------------------------------------

    /**
     * La personne peut-elle télécharger cette pièce ? La personne désignée, l'équipe de l'audit et
     * le groupe RSSI ; l'auditeur dont l'accès est en vigueur, pour une pièce soumise seulement.
     *
     * @param array<string, mixed> $piece ligne de la table des pièces
     */
    public static function canDownloadPiece(array $piece, int $usersId): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        $request = self::getRow((int) $piece['plugin_grcmanager_auditevidencerequests_id']);
        if ($request === null) {
            return false;
        }
        $auditId = (int) $request['plugin_grcmanager_audits_id'];
        if ((int) $request['users_id'] === $usersId || AuditTeam::canAccessDocuments($auditId, $usersId)) {
            return true;
        }

        return AuditorAccess::isActive($auditId, $usersId)
            && Rules::isVisibleToAuditor((string) $request['status'], $request['date_submitted'] ?: null)
            && Rules::isPieceVisibleToAuditor((int) $piece['is_submitted'] === 1);
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet de l'audit
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !Audit::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            count(self::getForAudit($item->getID())),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Audit || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForAudit($item);

        return true;
    }

    /**
     * Lignes d'affichage d'une demande (onglet de l'audit, page de la personne, espace auditeur).
     *
     * @param array<string, mixed> $row
     * @param bool                 $auditorView pièces soumises seulement
     * @return array<string, mixed>
     */
    public static function present(array $row, int $usersId, bool $auditorView = false): array
    {
        $today = date('Y-m-d');
        $status = (string) $row['status'];
        $statuses = self::getStatuses();
        $pieces = [];
        foreach (self::getPieces((int) $row['id']) as $piece) {
            if ($auditorView && !Rules::isPieceVisibleToAuditor($piece['is_submitted'])) {
                continue;
            }
            $canDownload = self::canDownloadPiece(
                ['plugin_grcmanager_auditevidencerequests_id' => (int) $row['id'],
                    'is_submitted' => $piece['is_submitted'] ? 1 : 0],
                $usersId
            );
            $pieces[] = $piece + [
                'url'            => $canDownload ? self::getDownloadURL($piece['id']) : '',
                'submitted_text' => $piece['date_submitted'] !== null
                    ? Html::convDateTime($piece['date_submitted'])
                    : '',
            ];
        }

        return [
            'id'                 => (int) $row['id'],
            'label'              => self::label($row),
            'reference'          => (string) $row['reference'],
            'description'        => (string) ($row['description'] ?? ''),
            'control_id'         => (int) $row['plugin_grcmanager_controls_id'],
            'control'            => self::controlLabel((int) $row['plugin_grcmanager_controls_id']),
            'users_id'           => (int) $row['users_id'],
            'assignee'           => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
            'due_date'           => $row['due_date'] ?: null,
            'due_text'           => $row['due_date'] ? (string) Html::convDate($row['due_date']) : '',
            'overdue'            => Rules::isOverdue($status, $row['due_date'] ?: null, $today),
            'days_late'          => Rules::daysLate($status, $row['due_date'] ?: null, $today),
            'status'             => $status,
            'status_label'       => $statuses[$status][0] ?? $status,
            'status_class'       => $statuses[$status][1] ?? 'bg-secondary-lt',
            'status_icon'        => $statuses[$status][2] ?? 'ti-help',
            'waiting'            => Rules::isWaitingForAssignee($status),
            'can_review'         => Rules::canReview($status),
            'submission_comment' => (string) ($row['submission_comment'] ?? ''),
            'review_comment'     => (string) ($row['review_comment'] ?? ''),
            'submitted_text'     => $row['date_last_submission']
                ? (string) Html::convDateTime($row['date_last_submission'])
                : '',
            'review_text'        => $row['date_review'] ? (string) Html::convDateTime($row['date_review']) : '',
            'reminder_text'      => $row['date_reminder'] ? (string) Html::convDateTime($row['date_reminder']) : '',
            'reviewer'           => (int) ($row['users_id_reviewer'] ?? 0) > 0
                ? (string) getUserName((int) $row['users_id_reviewer'])
                : '',
            'pieces'             => $pieces,
        ];
    }

    private static function showForAudit(Audit $audit): void
    {
        $auditId = $audit->getID();
        $usersId = (int) Session::getLoginUserID();
        $rows = [];
        foreach (self::getForAudit($auditId) as $row) {
            $rows[] = self::present($row, $usersId);
        }
        $controls = [];
        $linked = Audit::getLinkedControls($auditId);
        foreach ($linked as $id => $label) {
            $controls[$id] = $label;
        }

        TemplateRenderer::getInstance()->display('@grcmanager/audit_evidence_requests_tab.html.twig', [
            'audit_id'        => $auditId,
            'audit_status'    => (string) ($audit->fields['status'] ?? ''),
            'rows'            => $rows,
            'progress'        => self::progressForAudit($auditId),
            'can_update'      => $audit->can($auditId, UPDATE),
            'action'          => self::getFormURL(),
            'linked_controls' => $controls,
            'all_controls'    => self::controlChoices(),
            'mine_url'        => self::getMineURL(),
            'csv_max_rows'    => EvidenceRequestCsv::MAX_ROWS,
            'reminder_days'   => Rules::REMINDER_DAYS_BEFORE,
            'accesses'        => AuditorAccess::getForAudit($auditId),
            'access_default_end' => Rules::defaultAccessEnd(date('Y-m-d')),
            'access_max_days' => Rules::MAX_ACCESS_DAYS,
            'space_url'       => AuditorAccess::getSpaceURL($auditId),
        ]);
    }

    /**
     * Mesures proposées dans le formulaire : toutes, par code (celles du périmètre de l'audit sont
     * proposées en tête par le gabarit).
     *
     * @return array<int, string>
     */
    public static function controlChoices(): array
    {
        global $DB;

        $choices = [];
        foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => Control::getTable()]) as $row) {
            $choices[(int) $row['id']] = $row['code'] . ' - ' . Control::getControlTitle((string) $row['code']);
        }
        asort($choices, SORT_NATURAL);

        return $choices;
    }

    // ---------------------------------------------------------------------------------------------
    // Page « Mes demandes de preuves »
    // ---------------------------------------------------------------------------------------------

    public static function showMine(int $usersId): void
    {
        $waiting = [];
        $done = [];
        foreach (self::getAssignedTo($usersId) as $row) {
            $item = self::present($row, $usersId) + [
                'audit_title' => (string) $row['audit_title'],
                'selectable'  => [],
            ];
            if ($item['waiting']) {
                $item['selectable'] = self::getSelectableDocuments($row);
                $waiting[] = $item;
            } else {
                $done[] = $item;
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/my_evidence_requests.html.twig', [
            'waiting'        => $waiting,
            'done'           => $done,
            'action'         => self::getMineURL(),
            'validity'       => [
                EvidenceValidity::STATUS_VALID    => ['bg-green-lt', __('Valid', 'grcmanager')],
                EvidenceValidity::STATUS_EXPIRING => ['bg-orange-lt', __('To renew', 'grcmanager')],
                EvidenceValidity::STATUS_EXPIRED  => ['bg-red-lt', _x('feminine', 'Expired', 'grcmanager')],
            ],
        ]);
    }

    /** Nombre de demandes qui attendent la personne (menu de l'interface simplifiée). */
    public static function countWaiting(int $usersId): int
    {
        return count(self::getAssignedTo($usersId, true));
    }
}
