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
use Config;
use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Config\Block\SupplierPortalBlock;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\SupplierPortalTables as Portal;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierPortalRules as Rules;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire;
use Log;
use Session;
use Toolbox;

/**
 * Issue #865, lot 2 : réponse du fournisseur par lien sécurisé, sans compte GLPI.
 *
 * Fonction désactivée par défaut (configuration du plugin). Sur une évaluation, onglet « Lien
 * sécurisé » : création d'un lien (jeton de 32 octets dont seule l'empreinte est gardée, une seule
 * évaluation, expiration, nombre d'envois limité), révocation, et relecture des réponses reçues,
 * qui restent en brouillon jusqu'à leur enregistrement par un utilisateur GLPI (même aperçu que le
 * fichier du lot 1). La page publique est front/supplierportal.php. Création, accès, envoi,
 * révocation et relecture sont inscrits dans le journal d'audit sécurité (AuditLog).
 */
class SupplierEvaluationLink extends CommonGLPI
{
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const ENABLED_KEY = 'supplier_portal_enabled';

    public const DRAFT_PENDING = 'pending';
    public const DRAFT_APPLIED = 'applied';
    public const DRAFT_DISCARDED = 'discarded';

    /** Limites de la page publique : requêtes par adresse IP, par jeton, et jetons faux par IP. */
    public const LIMIT_IP = 120;
    public const LIMIT_TOKEN = 40;
    public const LIMIT_BAD_TOKEN = 20;
    public const WINDOW_SECONDS = 600;

    private const SESSION_KEY = 'grcmanager_supplier_link_once';

    public static function getTypeName($nb = 0)
    {
        return __('Secure link', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-link';
    }

    public static function canView(): bool
    {
        return SupplierEvaluation::canView();
    }

    public static function getActionURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierevaluationlink.php';
    }

    public static function isEnabled(): bool
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::ENABLED_KEY]);

        return !empty($values[self::ENABLED_KEY]);
    }

    public static function setEnabled(bool $enabled): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::ENABLED_KEY => $enabled ? 1 : 0]);
        AuditLog::record('config_change', self::class, 0, 0, 'success', [self::ENABLED_KEY => $enabled ? 1 : 0]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function configData(): array
    {
        return [
            'enabled' => self::isEnabled(),
            // Issue #1040 : enregistré par le bloc de la configuration (SupplierPortalBlock::handle()).
            'action'  => dirname(self::getActionURL()) . '/config.php',
        ];
    }

    /**
     * Dossier des pièces en attente de relecture (hors de la racine web, noms aléatoires).
     */
    public static function storageDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/grcmanager/supplierportal';
    }

    public static function publicURL(string $token): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/supplierportal.php?t=' . $token;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        // Portail fournisseur désactivé : pas d'onglet (charte, section 9 : rien d'inactif affiché).
        if (!self::isEnabled()) {
            return '';
        }
        if (!$item instanceof SupplierEvaluation || $item->isNewItem() || !SupplierEvaluation::canView()) {
            return '';
        }
        $count = !empty($_SESSION['glpishow_count_on_tabs']) ? count(self::drafts($item->getID())) : 0;

        return self::createTabEntry(self::getTypeName(1), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof SupplierEvaluation || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $links = [];
        foreach (self::links($item->getID()) as $row) {
            $links[] = [
                'id'               => (int) $row['id'],
                'status'           => Rules::status($row, $now, $item->isCompleted()),
                'date_creation'    => (string) $row['date_creation'],
                'date_expiration'  => (string) $row['date_expiration'],
                'date_last_access' => (string) ($row['date_last_access'] ?? ''),
                'access_count'     => (int) $row['access_count'],
                'uses'             => (int) $row['uses'],
                'max_uses'         => (int) $row['max_uses'],
                'author'           => getUserName((int) $row['users_id']),
            ];
        }
        $token = self::pullForDisplay($item->getID());
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_evaluation_link.html.twig', [
            'evaluation_id' => $item->getID(),
            'enabled'       => self::isEnabled(),
            'can_manage'    => self::canManage($item),
            'completed'     => $item->isCompleted(),
            'links'         => $links,
            'drafts'        => self::drafts($item->getID()),
            'new_url'       => $token !== null ? self::publicURL($token) : null,
            'action'        => self::getActionURL(),
            'config_url'    => Session::haveRight(Installer::RIGHT_NAME, UPDATE)
                ? \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl(SupplierPortalBlock::ANCHOR) : '',
            'default_days'  => Rules::DEFAULT_DAYS,
            'max_days'      => Rules::MAX_DAYS,
            'max_uses'      => Rules::MAX_USES,
        ]);

        return true;
    }

    public static function canManage(SupplierEvaluation $evaluation): bool
    {
        return !$evaluation->isCompleted() && (bool) $evaluation->can($evaluation->getID(), UPDATE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function links(int $evaluationId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => Portal::LINKS,
            'WHERE' => [Tables::EVALUATION_FK => $evaluationId],
            'ORDER' => 'id DESC',
        ]), false));
    }

    /**
     * Crée un lien et renvoie le jeton en clair, seule fois où il existe.
     */
    public static function create(SupplierEvaluation $evaluation, mixed $days, mixed $uses): string
    {
        global $DB;

        $token = Rules::generateToken();
        $days = Rules::normalizeDays($days);
        $DB->insert(Portal::LINKS, [
            Tables::EVALUATION_FK => $evaluation->getID(),
            'token_hash'          => Rules::hashToken($token),
            'fingerprint'         => WeightedQuestionnaire::fingerprint(
                SupplierEvaluationAnswer::getAnswers($evaluation->getID())
            ),
            'max_uses'            => Rules::normalizeUses($uses),
            'date_expiration'     => date('Y-m-d H:i:s', strtotime('+' . $days . ' days')),
            'users_id'            => (int) Session::getLoginUserID(),
            'date_creation'       => date('Y-m-d H:i:s'),
        ]);
        $linkId = (int) $DB->insertId();
        AuditLog::record('create', self::class, $linkId, 0, 'success', [
            'evaluation' => $evaluation->getID(), 'days' => $days, 'max_uses' => Rules::normalizeUses($uses),
        ]);
        self::history($evaluation, sprintf(
            __('Secure link %1$d created for the supplier, valid %2$d day(s)', 'grcmanager'),
            $linkId,
            $days
        ));
        $_SESSION[self::SESSION_KEY] = ['evaluation' => $evaluation->getID(), 'token' => $token];

        return $token;
    }

    public static function revoke(SupplierEvaluation $evaluation, int $linkId): bool
    {
        global $DB;

        $DB->update(Portal::LINKS, [
            'is_revoked'      => 1,
            'date_revoked'    => date('Y-m-d H:i:s'),
            'users_id_revoke' => (int) Session::getLoginUserID(),
        ], ['id' => $linkId, Tables::EVALUATION_FK => $evaluation->getID(), 'is_revoked' => 0]);
        if ($DB->affectedRows() < 1) {
            return false;
        }
        AuditLog::record('update', self::class, $linkId, 0, 'success', [
            'event' => 'revoked', 'evaluation' => $evaluation->getID(),
        ]);
        self::history($evaluation, sprintf(__('Secure link %d revoked', 'grcmanager'), $linkId));

        return true;
    }

    private static function pullForDisplay(int $evaluationId): ?string
    {
        $once = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($once) || (int) ($once['evaluation'] ?? 0) !== $evaluationId) {
            return null;
        }
        unset($_SESSION[self::SESSION_KEY]);

        return Rules::isWellFormed($once['token'] ?? null) ? (string) $once['token'] : null;
    }

    /**
     * Lien d'un jeton bien formé, ou null (empreinte cherchée par l'index unique : le jeton en clair
     * n'est jamais comparé ni gardé).
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(mixed $token): ?array
    {
        global $DB;

        if (!Rules::isWellFormed($token)) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => Portal::LINKS,
            'WHERE' => ['token_hash' => Rules::hashToken((string) $token)],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) && hash_equals((string) $row['token_hash'], Rules::hashToken((string) $token))
            ? $row : null;
    }

    /**
     * @param array<string, mixed> $link
     */
    public static function recordAccess(array $link): void
    {
        global $DB;

        $DB->update(Portal::LINKS, [
            'access_count'     => (int) $link['access_count'] + 1,
            'date_last_access' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $link['id']]);
        AuditLog::record('view', self::class, (int) $link['id'], 0, 'success', [
            'evaluation' => (int) $link[Tables::EVALUATION_FK], 'public' => 1,
        ]);
    }

    /**
     * Compte une requête dans le compartiment (empreinte d'une adresse IP ou d'un jeton) ; false
     * au-delà de la limite de la fenêtre.
     */
    public static function hit(string $key, int $limit): bool
    {
        global $DB;

        $bucket = hash('sha256', 'grcmanager-portal|' . $key);
        $now = date('Y-m-d H:i:s');
        $DB->delete(Portal::THROTTLES, ['window_start' => ['<', date('Y-m-d H:i:s', strtotime('-1 day'))]]);
        $row = $DB->request(['FROM' => Portal::THROTTLES, 'WHERE' => ['bucket' => $bucket], 'LIMIT' => 1])->current();
        $next = Rules::throttle(is_array($row) ? $row : null, $now, $limit, self::WINDOW_SECONDS);
        $fields = ['hits' => $next['hits'], 'window_start' => $next['window_start']];
        if (is_array($row)) {
            $DB->update(Portal::THROTTLES, $fields, ['id' => (int) $row['id']]);
        } else {
            $DB->insert(Portal::THROTTLES, $fields + ['bucket' => $bucket]);
        }

        return $next['allowed'];
    }

    /**
     * Contrôles d'une pièce reçue par le lien, les mêmes que pour toute preuve (nom, taille, type
     * accepté par GLPI, contenu réel) ; message d'erreur ou null.
     */
    public static function evidenceError(int $phpError, string $path, mixed $clientName): ?string
    {
        global $CFG_GLPI, $DB;

        $name = EvidenceUploadRules::safeName($clientName);
        if ($phpError === UPLOAD_ERR_OK && ($name === null || !is_uploaded_file($path))) {
            return __('The file name is not valid.', 'grcmanager');
        }
        $maxBytes = min(Rules::MAX_FILE_BYTES, (int) $CFG_GLPI['document_max_size'] * 1024 * 1024);
        $size = $phpError === UPLOAD_ERR_OK ? (int) filesize($path) : 0;
        $error = EvidenceUploadRules::uploadError($phpError, $size, $maxBytes);
        if ($error !== null) {
            return $error === EvidenceUploadRules::ERROR_TOO_LARGE
                ? sprintf(__('The file is too large (at most %d MB).', 'grcmanager'), intdiv($maxBytes, 1048576))
                : ControlTest::uploadErrorMessage($error);
        }
        if ((string) Document::isValidDoc((string) $name) === '') {
            return sprintf(
                __('Files of type .%s are not accepted.', 'grcmanager'),
                EvidenceUploadRules::extension((string) $name)
            );
        }
        $extension = EvidenceUploadRules::extension((string) $name);
        $type = $DB->request([
            'SELECT' => ['mime'],
            'FROM'   => 'glpi_documenttypes',
            'WHERE'  => ['is_uploadable' => 1, 'ext' => ['LIKE', $extension]],
            'LIMIT'  => 1,
        ])->current();
        $detected = (string) Toolbox::getMime($path);
        if (!EvidenceUploadRules::realTypeMatches($extension, $detected, $type['mime'] ?? null)) {
            return sprintf(
                __('The content of the file (%1$s) does not match its extension (.%2$s).', 'grcmanager'),
                $detected,
                $extension
            );
        }

        return null;
    }

    /**
     * Enregistre l'envoi du fournisseur en brouillon et compte un envoi du lien. Renvoie l'id du
     * brouillon, 0 si le lien a été épuisé entre-temps (deux envois simultanés).
     *
     * @param array<string, mixed>                                $link
     * @param array<int, array{answer: string, comment: string}>  $answers
     * @param array<int, array{tmp: string, name: string}>        $files   pièces déjà contrôlées
     */
    public static function submit(array $link, SupplierEvaluation $evaluation, array $answers, array $files): int
    {
        global $DB;

        $DB->update(Portal::LINKS, ['uses' => (int) $link['uses'] + 1], [
            'id' => (int) $link['id'], 'uses' => (int) $link['uses'], 'is_revoked' => 0,
        ]);
        if ($DB->affectedRows() < 1) {
            return 0;
        }
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $codes = array_column(SupplierEvaluationAnswer::getAnswers($evaluation->getID()), 'code', 'id');
        $stored = [];
        foreach ($files as $answerId => $file) {
            $name = EvidenceUploadRules::safeName($file['name']);
            $storedName = bin2hex(random_bytes(16)) . '.bin';
            if (
                $name === null || !isset($codes[$answerId])
                || !move_uploaded_file($file['tmp'], $dir . '/' . $storedName)
            ) {
                continue;
            }
            $stored[$answerId] = [
                'stored' => $storedName,
                'name'   => Rules::evidenceName((string) $codes[$answerId], $name),
                'sha256' => (string) hash_file('sha256', $dir . '/' . $storedName),
            ];
        }
        $DB->insert(Portal::DRAFTS, [
            Portal::LINK_FK       => (int) $link['id'],
            Tables::EVALUATION_FK => $evaluation->getID(),
            'fingerprint'         => (string) $link['fingerprint'],
            'answers'             => (string) json_encode($answers, JSON_UNESCAPED_UNICODE),
            'files'               => (string) json_encode($stored, JSON_UNESCAPED_UNICODE),
            'status'              => self::DRAFT_PENDING,
            'date_creation'       => date('Y-m-d H:i:s'),
        ]);
        $draftId = (int) $DB->insertId();
        AuditLog::record('import', self::class, (int) $link['id'], 0, 'success', [
            'evaluation' => $evaluation->getID(), 'draft' => $draftId, 'answers' => count($answers),
            'files' => array_values(array_column($stored, 'sha256', 'name')), 'public' => 1,
        ]);
        self::history($evaluation, sprintf(
            __(
                'Supplier answers received by secure link %1$d: %2$d answer(s), %3$d document(s), to be reviewed',
                'grcmanager'
            ),
            (int) $link['id'],
            count($answers),
            count($stored)
        ));

        return $draftId;
    }

    /**
     * Brouillons en attente de relecture.
     *
     * @return list<array<string, mixed>>
     */
    public static function drafts(int $evaluationId): array
    {
        global $DB;

        $drafts = [];
        foreach (
            $DB->request([
                'FROM'  => Portal::DRAFTS,
                'WHERE' => [Tables::EVALUATION_FK => $evaluationId, 'status' => self::DRAFT_PENDING],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $drafts[] = [
                'id'            => (int) $row['id'],
                'link'          => (int) $row[Portal::LINK_FK],
                'date_creation' => (string) $row['date_creation'],
                'answers'       => count(self::decode($row['answers'])),
                'files'         => count(self::decode($row['files'])),
            ];
        }

        return $drafts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function draft(SupplierEvaluation $evaluation, int $draftId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => Portal::DRAFTS,
            'WHERE' => [
                'id' => $draftId, Tables::EVALUATION_FK => $evaluation->getID(), 'status' => self::DRAFT_PENDING,
            ],
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Aperçu d'un brouillon au format de l'aperçu du fichier (lot 1).
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function parsed(SupplierEvaluation $evaluation, array $draft): array
    {
        $rows = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
        $parsed = Rules::changes($rows, self::decode($draft['answers']), self::decode($draft['files']));
        if (!hash_equals((string) $draft['fingerprint'], WeightedQuestionnaire::fingerprint($rows))) {
            $parsed['error'] = Services\Risk\SupplierAnswerFile::ERROR_OTHER_QUESTIONS;
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function previewData(SupplierEvaluation $evaluation, array $draft): array
    {
        $parsed = self::parsed($evaluation, $draft);
        $data = SupplierEvaluationFile::previewData($evaluation, $parsed, sprintf(
            __('Answers received by secure link on %s', 'grcmanager'),
            \Html::convDateTime((string) $draft['date_creation'])
        ));

        return array_merge($data, [
            'action'         => self::getActionURL(),
            'draft_id'       => (int) $draft['id'],
            'can_upload'     => false,
            'evidence_names' => [],
            'received_files' => array_values(array_column(self::decode($draft['files']), 'name')),
        ]);
    }

    /**
     * Enregistre un brouillon relu dans l'évaluation (mêmes contrôles que l'import du fichier).
     *
     * @param array<string, mixed> $draft
     * @return array{answers: int, documents: int, unmatched: list<string>}|null
     */
    public static function apply(SupplierEvaluation $evaluation, array $draft): ?array
    {
        $parsed = self::parsed($evaluation, $draft);
        if ($parsed['error'] !== null || $evaluation->isCompleted()) {
            return null;
        }
        $files = [];
        foreach (self::decode($draft['files']) as $file) {
            $path = self::storedPath($file['stored'] ?? '');
            if ($path !== '' && is_array($file)) {
                $files[] = ['path' => $path, 'name' => (string) $file['name']];
            }
        }
        $result = SupplierEvaluationFile::apply($evaluation, $parsed, '', '', $files, Rules::SOURCE_LINK);
        self::close($evaluation, $draft, self::DRAFT_APPLIED);
        self::history($evaluation, sprintf(
            __(
                'Supplier answers received by secure link reviewed and saved: %1$d answer(s), %2$d document(s)',
                'grcmanager'
            ),
            $result['answers'],
            $result['documents']
        ));

        return $result;
    }

    /**
     * @param array<string, mixed> $draft
     */
    public static function close(SupplierEvaluation $evaluation, array $draft, string $status): void
    {
        global $DB;

        foreach (self::decode($draft['files']) as $file) {
            $path = is_array($file) ? self::storedPath($file['stored'] ?? '') : '';
            if ($path !== '') {
                // Nom aléatoire créé par le serveur, vérifié dans le dossier du plugin (storedPath()).
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($path);
            }
        }
        $DB->update(Portal::DRAFTS, [
            'status'          => $status,
            'users_id_review' => (int) Session::getLoginUserID(),
            'date_review'     => date('Y-m-d H:i:s'),
            'files'           => '[]',
        ], ['id' => (int) $draft['id'], Tables::EVALUATION_FK => $evaluation->getID()]);
        AuditLog::record('import', self::class, (int) $draft[Portal::LINK_FK], 0, 'success', [
            'event' => 'review_' . $status, 'evaluation' => $evaluation->getID(), 'draft' => (int) $draft['id'],
        ]);
        if ($status === self::DRAFT_DISCARDED) {
            self::history($evaluation, sprintf(
                __('Supplier answers received by secure link discarded (draft %d)', 'grcmanager'),
                (int) $draft['id']
            ));
        }
    }

    /**
     * Chemin d'une pièce en attente : nom aléatoire au format attendu, dans le dossier du plugin.
     */
    public static function storedPath(mixed $stored): string
    {
        if (!is_string($stored) || preg_match('/^[0-9a-f]{32}\.bin$/D', $stored) !== 1) {
            return '';
        }
        $path = self::storageDir() . '/' . $stored;

        return is_file($path) ? $path : '';
    }

    /**
     * Données de la page publique : seulement les questions de cette évaluation (ni entité, ni
     * utilisateur, ni nom du fournisseur ou de l'évaluation).
     *
     * @param array<string, mixed>               $link
     * @param array<int, array<string, string>>  $values
     * @param list<string>                       $errors
     * @return array<string, mixed>
     */
    public static function portalData(
        array $link,
        SupplierEvaluation $evaluation,
        string $token,
        array $values,
        array $errors
    ): array {
        $questions = [];
        foreach (SupplierEvaluationAnswer::getAnswers($evaluation->getID()) as $row) {
            $id = (int) $row['id'];
            $questions[] = [
                'id'                => $id,
                'code'              => (string) $row['code'],
                'section'           => (string) $row['section'],
                'question'          => (string) $row['question'],
                'guidance'          => (string) ($row['guidance'] ?? ''),
                'requires_evidence' => !empty($row['requires_evidence']),
                // Issue #1159 : Oui / Non ou échelle de conformité, selon la question.
                'labels'            => SupplierQuestionnaireQuestion::choicesFor($row['answer_type'] ?? ''),
                'answer'            => (string) ($values[$id]['answer'] ?? ''),
                'comment'           => (string) ($values[$id]['comment'] ?? ''),
            ];
        }

        return [
            'state'           => 'form',
            'token'           => $token,
            'questions'       => $questions,
            'labels'          => SupplierAssessment::getAnswerLabels(),
            'errors'          => $errors,
            'date_expiration' => \Html::convDateTime((string) $link['date_expiration']),
            'remaining'       => max(0, (int) $link['max_uses'] - (int) $link['uses']),
            'max_mb'          => intdiv(min(
                Rules::MAX_FILE_BYTES,
                (int) ($GLOBALS['CFG_GLPI']['document_max_size'] ?? 2) * 1048576
            ), 1048576),
        ];
    }

    private static function history(SupplierEvaluation $evaluation, string $message): void
    {
        Log::history(
            $evaluation->getID(),
            SupplierEvaluation::class,
            [0, '', $message],
            '',
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }
}
