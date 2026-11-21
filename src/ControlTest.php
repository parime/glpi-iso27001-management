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
use Config;
use CronTask;
use Document;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ControlTestBase;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Html;
use Session;
use Toolbox;
use User;

/**
 * Issue #380 : test d'efficacité d'une mesure de l'Annexe A. L'état de mise en œuvre dit qu'une
 * mesure est en place ; le test prouve qu'elle fonctionne : date, méthode (inspection,
 * échantillonnage, test technique, entretien), résultat, constat, preuve et échéance du test
 * suivant.
 *
 * - Plan de test : fréquence, méthode et responsable, portés par la mesure elle-même
 *   (glpi_plugin_grcmanager_controls), modifiables avec le droit de modifier la mesure.
 * - Droits : ceux de la mesure. Lire un test exige de pouvoir lire la mesure ; l'enregistrer ou le
 *   supprimer exige de pouvoir la modifier (plus le droit de suppression du plugin pour supprimer).
 * - Preuve : un document joint à la mesure (onglet Documents), donc soumis aux droits et aux
 *   entités du document, comme les validités de preuve (Evidence). Jamais un document quelconque
 *   désigné par son identifiant.
 * - Un test inefficace ouvre une non-conformité (si l'utilisateur a le droit d'en créer une) ;
 *   l'état « vérifié » de la SoA suit le dernier test (ControlTestRules::statusAfterTest()).
 *
 * Un test est un enregistrement : il ne se modifie pas, il se supprime et se ressaisit.
 */
class ControlTest extends ControlTestBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Issue #607 : relecture des tests par une seconde personne (réglage de la configuration). */
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const REVIEW_CONFIG_KEY = 'controltest_review';

    public static function getTypeName($nb = 0)
    {
        return _n('Effectiveness test', 'Effectiveness tests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-test-pipe';
    }

    /**
     * Sous-page « Tests d'efficacité » de la rubrique « Conformité » (Menu::sections()).
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && $menu !== []) {
            $menu['title'] = self::getTypeName(Session::getPluralNumber());
            unset($menu['links']['add']);
        }

        return $menu;
    }

    /**
     * Un test se saisit depuis l'onglet « Tests d'efficacité » de la mesure (droit vérifié sur
     * la mesure), jamais comme un objet autonome : pas de bouton « Ajouter » sur la liste.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function getMethods(): array
    {
        return [
            'inspection' => __('Documentary inspection', 'grcmanager'),
            'sampling'   => __('Sampling', 'grcmanager'),
            'technical'  => __('Technical test', 'grcmanager'),
            'interview'  => __('Interview', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getResults(): array
    {
        return [
            ControlTestRules::RESULT_EFFECTIVE   => [
                'label' => __('Effective', 'grcmanager'), 'class' => 'bg-green-lt', 'icon' => 'ti-circle-check',
            ],
            ControlTestRules::RESULT_PARTIAL     => [
                'label' => __('Partially effective', 'grcmanager'), 'class' => 'bg-yellow-lt',
                'icon'  => 'ti-circle-half-2',
            ],
            ControlTestRules::RESULT_INEFFECTIVE => [
                'label' => __('Ineffective', 'grcmanager'), 'class' => 'bg-red-lt', 'icon' => 'ti-circle-x',
            ],
        ];
    }

    /**
     * Issue #607 : la relecture des tests est-elle demandée ? Désactivée par défaut.
     */
    public static function isReviewEnabled(): bool
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::REVIEW_CONFIG_KEY]);

        return (string) ($values[self::REVIEW_CONFIG_KEY] ?? '0') === '1';
    }

    /**
     * @return bool vrai si le réglage a changé
     */
    public static function saveReviewEnabled(mixed $enabled): bool
    {
        $before = self::isReviewEnabled();
        $after = in_array($enabled, [1, '1', true, 'on'], true);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::REVIEW_CONFIG_KEY => $after ? '1' : '0']);

        return $before !== $after;
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getReviewStatuses(): array
    {
        return [
            ControlTestRules::REVIEW_PENDING  => [
                'label' => __('Awaiting review', 'grcmanager'),
                'class' => 'bg-orange-subtle text-orange-emphasis', 'icon' => 'ti-hourglass',
            ],
            ControlTestRules::REVIEW_APPROVED => [
                'label' => __('Review approved', 'grcmanager'),
                'class' => 'bg-green-subtle text-green-emphasis', 'icon' => 'ti-user-check',
            ],
            ControlTestRules::REVIEW_RETURNED => [
                'label' => __('Returned by the reviewer', 'grcmanager'),
                'class' => 'bg-red-subtle text-red-emphasis', 'icon' => 'ti-arrow-back-up',
            ],
        ];
    }

    public static function getPdfURL(int $testsId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/controltest.pdf.php?id=' . $testsId;
    }

    /**
     * @return array<int, string>
     */
    public static function getFrequencies(): array
    {
        $frequencies = [];
        foreach (ControlTestRules::FREQUENCIES as $months) {
            $frequencies[$months] = $months === 0
                ? __('No test plan', 'grcmanager')
                : PeriodicReview::frequencyLabel($months);
        }

        return $frequencies;
    }

    /**
     * @return array{0: string, 1: string} classe du badge, libellé
     */
    public static function dueInfo(?string $nextDate): array
    {
        return match (ControlTestRules::dueStatus($nextDate, date('Y-m-d'))) {
            ControlTestRules::DUE_OVERDUE => ['bg-red-lt', _x('deadline passed', 'Overdue', 'grcmanager')],
            ControlTestRules::DUE_SOON    => ['bg-orange-lt', __('Due soon', 'grcmanager')],
            ControlTestRules::DUE_PLANNED => ['bg-green-lt', _x('masculine', 'Planned', 'grcmanager')],
            default                       => ['bg-secondary-lt', __('No test scheduled', 'grcmanager')],
        };
    }

    public static function canEditControl(int $controlsId): bool
    {
        $control = new Control();

        return $controlsId > 0 && $control->can($controlsId, UPDATE);
    }

    public function canViewItem(): bool
    {
        $control = new Control();

        return $control->getFromDB((int) ($this->fields['plugin_grcmanager_controls_id'] ?? 0))
            && $control->canViewItem();
    }

    public function canCreateItem(): bool
    {
        return self::canEditControl((int) ($this->input['plugin_grcmanager_controls_id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return self::canEditControl((int) ($this->fields['plugin_grcmanager_controls_id'] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $controlsId = (int) ($input['plugin_grcmanager_controls_id'] ?? 0);
        $control = new Control();
        if (!self::canEditControl($controlsId) || !$control->getFromDB($controlsId)) {
            Session::addMessageAfterRedirect(__('Control not found.', 'grcmanager'), false, ERROR);

            return false;
        }

        $result = (string) ($input['result'] ?? '');
        if (!ControlTestRules::isResult($result)) {
            Session::addMessageAfterRedirect(__('Choose the result of the test.', 'grcmanager'), false, ERROR);

            return false;
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($result !== ControlTestRules::RESULT_EFFECTIVE && $comment === '') {
            Session::addMessageAfterRedirect(
                __('Describe what was found when the control is not fully effective.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        $testDate = ControlTestRules::normalizeDate($input['test_date'] ?? '') ?? date('Y-m-d');
        if ($testDate > date('Y-m-d')) {
            Session::addMessageAfterRedirect(__('A test cannot be dated in the future.', 'grcmanager'), false, ERROR);

            return false;
        }
        $explicitNext = ControlTestRules::normalizeDate($input['next_test_date'] ?? '');
        if ($explicitNext !== null && $explicitNext <= $testDate) {
            Session::addMessageAfterRedirect(
                __('The next test must be scheduled after the test date.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        // Preuve : un document joint à la mesure ET lisible par l'utilisateur (entité du document).
        $documentsId = max(0, (int) ($input['documents_id'] ?? 0));
        if (
            $documentsId > 0
            && (!Evidence::isDocumentLinked(Control::class, $controlsId, $documentsId)
                || !Evidence::canReadDocument($documentsId))
        ) {
            Session::addMessageAfterRedirect(
                __('The evidence must be a document attached to this control.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        return [
            'plugin_grcmanager_controls_id' => $controlsId,
            'test_date'                     => $testDate,
            'method'                        => ControlTestRules::normalizeMethod(
                $input['method'] ?? ($control->fields['test_method'] ?? '')
            ),
            'result'                        => $result,
            'users_id'                      => max(0, (int) ($input['users_id'] ?? 0))
                ?: (int) Session::getLoginUserID(),
            'comment'                       => $comment,
            'documents_id'                  => $documentsId,
            'next_test_date'                => ControlTestRules::nextTestDate(
                $testDate,
                $explicitNext,
                (int) ($control->fields['test_frequency_months'] ?? 0)
            ),
            // Rempli par post_addItem(), jamais par la saisie.
            'plugin_grcmanager_nonconformities_id' => 0,
            // Issue #607 : qui l'enregistre (jamais son relecteur) et relecture demandée ou non.
            'users_id_recorder'             => (int) Session::getLoginUserID(),
            'review_status'                 => self::isReviewEnabled()
                ? ControlTestRules::REVIEW_PENDING
                : ControlTestRules::REVIEW_NONE,
        ];
    }

    /**
     * Un test enregistré ne se modifie pas (valeur probante) : seule la suppression est possible.
     *
     * @param array<string, mixed> $input
     * @return false
     */
    public function prepareInputForUpdate($input)
    {
        return false;
    }

    public function post_addItem()
    {
        $control = new Control();
        if (!$control->getFromDB((int) $this->fields['plugin_grcmanager_controls_id'])) {
            return;
        }
        $result = (string) $this->fields['result'];
        $code = (string) $control->fields['code'];

        // L'état « vérifié » de la SoA s'appuie sur le dernier test ; issue #607 : un test
        // efficace en attente de relecture ne l'apporte qu'à sa validation.
        $reviewStatus = (string) ($this->fields['review_status'] ?? '');
        $status = ControlTestRules::statusAtRecording(
            (string) $control->fields['implementation_status'],
            $result,
            $reviewStatus
        );
        $this->applyControlStatus($control, $status);
        if ($reviewStatus === ControlTestRules::REVIEW_PENDING) {
            Session::addMessageAfterRedirect(htmlescape(
                __('The test awaits review by another person before it counts for the "Verified" status.', 'grcmanager')
            ));
        }

        if (ControlTestRules::opensNonconformity($result)) {
            $this->openNonconformity($control);
        }

        AuditLog::record('create', self::class, (int) $this->getID(), 0, 'success', [
            'control' => $code,
            'result'  => $result,
            'date'    => (string) $this->fields['test_date'],
        ]);
    }

    private function applyControlStatus(Control $control, ?string $status): void
    {
        if ($status === null) {
            return;
        }
        $updated = $control->update([
            'id'                    => $control->getID(),
            'implementation_status' => $status,
            '_grcmanager_from_test' => true,
        ]);
        if ($updated) {
            Session::addMessageAfterRedirect(sprintf(
                __('Implementation status of the control set to "%s".', 'grcmanager'),
                Control::getImplementationStatuses()[$status]
            ));
        }
    }

    /**
     * Issue #607 : la personne connectée peut-elle relire ce test ? Il lui faut pouvoir modifier
     * la mesure, et n'être ni le testeur ni la personne qui l'a enregistré.
     *
     * @param array<string, mixed> $test
     */
    public static function canReviewTest(array $test): bool
    {
        return ControlTestRules::canReview(
            ControlTestRules::normalizeReviewStatus($test['review_status'] ?? ''),
            (int) Session::getLoginUserID(),
            (int) ($test['users_id'] ?? 0),
            (int) ($test['users_id_recorder'] ?? 0)
        ) && self::canEditControl((int) ($test['plugin_grcmanager_controls_id'] ?? 0));
    }

    /**
     * Issue #607 : relecture d'un test en attente. Valider le fait compter pour l'état « Vérifié »
     * (s'il est toujours le dernier test de la mesure) ; le renvoyer exige un commentaire, le test
     * ne compte pas et son auteur le supprime pour le refaire (l'échantillon se rouvre alors).
     * Écriture directe : un test enregistré ne se modifie pas (prepareInputForUpdate()).
     *
     * @return bool vrai si la relecture est enregistrée (message en session sinon)
     */
    public static function review(int $testsId, bool $approve, string $comment): bool
    {
        global $DB;

        $test = new self();
        if (!$test->getFromDB($testsId) || !self::canReviewTest($test->fields)) {
            return false;
        }
        $comment = trim($comment);
        if (!$approve && $comment === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__('Explain why the test is returned.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }
        $status = $approve ? ControlTestRules::REVIEW_APPROVED : ControlTestRules::REVIEW_RETURNED;
        $DB->update(self::getTable(), [
            'review_status'     => $status,
            'users_id_reviewer' => (int) Session::getLoginUserID(),
            'review_date'       => date('Y-m-d H:i:s'),
            'review_comment'    => mb_substr($comment, 0, 5000),
        ], ['id' => $testsId]);
        AuditLog::record('update', self::class, $testsId, 0, 'success', [
            'review'  => $status,
            'control' => (int) $test->fields['plugin_grcmanager_controls_id'],
        ]);

        $control = new Control();
        $controlsId = (int) $test->fields['plugin_grcmanager_controls_id'];
        $latest = self::getForControl($controlsId)[0] ?? null;
        if ($approve && $control->getFromDB($controlsId) && (int) ($latest['id'] ?? 0) === $testsId) {
            $test->applyControlStatus($control, ControlTestRules::statusAfterTest(
                (string) $control->fields['implementation_status'],
                (string) $test->fields['result']
            ));
        }
        Session::addMessageAfterRedirect(htmlescape($approve
            ? __('Test approved: it now counts for the "Verified" status.', 'grcmanager')
            : __('Test returned to its author: it does not count for the "Verified" status.', 'grcmanager')));

        return true;
    }

    /**
     * Non-conformité ouverte par un test inefficace, avec le droit de créer une non-conformité :
     * sans lui, le test est enregistré et l'utilisateur est prévenu.
     */
    private function openNonconformity(Control $control): void
    {
        global $DB;

        if (!Nonconformity::canCreate()) {
            Session::addMessageAfterRedirect(
                __('No non-conformity was opened: you are not allowed to create one.', 'grcmanager'),
                false,
                WARNING
            );

            return;
        }
        $code = (string) $control->fields['code'];
        $methods = self::getMethods();
        $nonconformity = new Nonconformity();
        $ncId = (int) $nonconformity->add([
            'title'         => sprintf(
                __('Ineffective control %1$s: %2$s', 'grcmanager'),
                $code,
                Control::getControlTitle($code)
            ),
            'description'   => sprintf(
                __('Effectiveness test of %1$s (%2$s) on %3$s: %4$s', 'grcmanager'),
                $code,
                $methods[$this->fields['method']] ?? (string) $this->fields['method'],
                Html::convDate((string) $this->fields['test_date']),
                (string) $this->fields['comment']
            ),
            'finding_type'  => 'nonconformity',
            'severity'      => 'major',
            'status'        => 'open',
            'users_id'      => (int) ($control->fields['users_id_tester'] ?? 0) ?: (int) $this->fields['users_id'],
        ]);
        if ($ncId <= 0) {
            return;
        }
        $DB->update(self::getTable(), ['plugin_grcmanager_nonconformities_id' => $ncId], ['id' => $this->getID()]);
        $this->fields['plugin_grcmanager_nonconformities_id'] = $ncId;
        Session::addMessageAfterRedirect(sprintf(
            __('Non-conformity opened: %s', 'grcmanager'),
            (string) $nonconformity->fields['title']
        ));
    }

    public function post_purgeItem()
    {
        // Issue #607 : l'échantillon du test supprimé redevient ouvert, pour être corrigé.
        ControlTestSample::reopenForTest((int) $this->getID());
        AuditLog::record('purge', self::class, (int) $this->getID(), 0, 'success', [
            'control' => (int) $this->fields['plugin_grcmanager_controls_id'],
            'result'  => (string) $this->fields['result'],
            'date'    => (string) $this->fields['test_date'],
        ]);
    }

    /**
     * Envoi direct d'une preuve depuis le formulaire du test : mêmes droits que l'onglet Documents
     * de la mesure (la modifier ou la lire, et créer un document), même entité (entité active) ;
     * types et taille maximale de GLPI ; type réel du contenu vérifié (EvidenceUploadRules). Le
     * nom vient du navigateur mais n'est jamais un chemin : le fichier est copié sous un nom
     * aléatoire dans le dossier temporaire de GLPI, qui le range ensuite lui-même.
     *
     * Le document devient un document de la mesure, au registre des documents restreints : seul
     * qui lit la mesure le lit (RestrictedDocument), et il disparaît à la désinstallation.
     *
     * @param string $path       fichier reçu (déjà vérifié par is_uploaded_file() côté formulaire)
     * @param string $clientName nom annoncé par le navigateur
     * @return int document créé, 0 en cas de refus (message en session)
     */
    public static function storeEvidenceFile(Control $control, string $path, string $clientName): int
    {
        if ($control->isNewItem() || !$control->canAddItem('Document') || !Document::canCreate()) {
            Session::addMessageAfterRedirect(
                htmlescape(__('You are not allowed to add a document to this control.', 'grcmanager')),
                false,
                ERROR
            );

            return 0;
        }
        $documentsId = self::createEvidenceDocument(
            $path,
            $clientName,
            ['itemtype' => Control::class, 'items_id' => $control->getID()],
            true
        );
        if ($documentsId > 0) {
            RestrictedDocument::register($documentsId, $control);
        }

        return $documentsId;
    }

    /**
     * Contrôles communs d'un fichier de preuve reçu (nom, taille et types de GLPI, type réel du
     * contenu), puis création du document dans l'entité active. Les droits sur l'élément sont
     * vérifiés par l'appelant : la mesure (storeEvidenceFile(), qui exige aussi le droit de créer
     * un document) ou la demande de preuve d'audit confiée à l'utilisateur (issue #538,
     * AuditEvidenceRequest::addUploadedPiece() : c'est la demande qui l'autorise à déposer).
     *
     * @param string               $path            fichier reçu (déjà vérifié par is_uploaded_file())
     * @param string               $clientName      nom annoncé par le navigateur
     * @param array<string, mixed> $extra           champs ajoutés au document (itemtype, items_id)
     * @param bool                 $checkCreateRight exiger le droit GLPI de créer ce document
     * @return int document créé, 0 en cas de refus (message en session)
     */
    public static function createEvidenceDocument(
        string $path,
        string $clientName,
        array $extra = [],
        bool $checkCreateRight = true
    ): int {
        global $CFG_GLPI, $DB;

        $refuse = static function (string $message): int {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return 0;
        };
        $name = EvidenceUploadRules::safeName($clientName);
        if ($name === null || !is_file($path)) {
            return $refuse(__('The file name is not valid.', 'grcmanager'));
        }
        $maxBytes = (int) $CFG_GLPI['document_max_size'] * 1024 * 1024;
        $size = (int) filesize($path);
        $error = EvidenceUploadRules::uploadError(UPLOAD_ERR_OK, $size, $maxBytes);
        if ($error !== null) {
            return $refuse(self::uploadErrorMessage($error));
        }
        if ((string) Document::isValidDoc($name) === '') {
            return $refuse(sprintf(
                __('Files of type .%s are not allowed by GLPI (Setup > Dropdowns > Document types).', 'grcmanager'),
                EvidenceUploadRules::extension($name)
            ));
        }
        $extension = EvidenceUploadRules::extension($name);
        $type = $DB->request([
            'SELECT' => ['mime'],
            'FROM'   => 'glpi_documenttypes',
            'WHERE'  => ['is_uploadable' => 1, 'ext' => ['LIKE', $extension]],
            'LIMIT'  => 1,
        ])->current();
        $detected = (string) Toolbox::getMime($path);
        if (!EvidenceUploadRules::realTypeMatches($extension, $detected, $type['mime'] ?? null)) {
            return $refuse(sprintf(
                __('The content of the file (%1$s) does not match its extension (.%2$s).', 'grcmanager'),
                $detected,
                $extension
            ));
        }

        $prefix = bin2hex(random_bytes(8)) . '_';
        $temporary = GLPI_TMP_DIR . '/' . $prefix . $name;
        if (!copy($path, $temporary)) {
            return $refuse(__('The file could not be saved.', 'grcmanager'));
        }
        $input = [
            'name'                    => $name,
            'entities_id'             => (int) ($extra['entities_id'] ?? Session::getActiveEntity()),
            'is_recursive'            => 0,
            '_filename'               => [$prefix . $name],
            '_prefix_filename'        => [$prefix],
            '_only_if_upload_succeed' => 1,
        ] + $extra;
        $document = new Document();
        $allowed = !$checkCreateRight || $document->can(-1, CREATE, $input);
        $documentsId = $allowed ? (int) $document->add($input) : 0;
        if (is_file($temporary)) {
            // Chemin construit ici : GLPI_TMP_DIR, préfixe aléatoire et nom réduit par
            // EvidenceUploadRules::safeName() (sans séparateur de dossier), jamais un chemin envoyé.
            unlink($temporary); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
        if ($documentsId <= 0) {
            return $refuse(__('The file could not be saved.', 'grcmanager'));
        }

        return $documentsId;
    }

    public static function uploadErrorMessage(string $error): string
    {
        global $CFG_GLPI;

        return match ($error) {
            EvidenceUploadRules::ERROR_TOO_LARGE => sprintf(
                __('The file exceeds the maximum size allowed by GLPI (%d MiB).', 'grcmanager'),
                (int) $CFG_GLPI['document_max_size']
            ),
            EvidenceUploadRules::ERROR_EMPTY     => __('The file is empty.', 'grcmanager'),
            default                              => __('The file could not be received.', 'grcmanager'),
        };
    }

    /**
     * Issue #380 (#162) : enregistre comme test d'efficacité le résultat actuel d'un contrôle
     * technique automatique rattaché à la mesure. Tout est recalculé ici (rien ne vient du
     * formulaire hormis le nom du contrôle), sur toutes les entités : la mesure est globale, un
     * résultat calculé sur une partie du parc ne la prouverait pas.
     *
     * @return int test créé, 0 sinon (message en session)
     */
    public static function recordTechnicalCheck(Control $control, string $check): int
    {
        $refuse = static function (string $message): int {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return 0;
        };
        $code = (string) ($control->fields['code'] ?? '');
        if (!in_array($check, ControlTestRules::technicalChecksFor($code, ComplianceAuditScore::CHECKS), true)) {
            return $refuse(__('This automated check is not related to this control.', 'grcmanager'));
        }
        if (!Session::canViewAllEntities()) {
            return $refuse(__('Select all entities to record the result of an automated check.', 'grcmanager'));
        }
        $outcome = ComplianceAudit::runCheck($check);
        $gaps = count($outcome['gaps']);
        $result = $outcome['evaluated']
            ? ControlTestRules::resultFromTechnicalCheck($gaps, $outcome['total'])
            : null;
        if ($result === null) {
            return $refuse(__('The automated check has nothing to evaluate: no test was recorded.', 'grcmanager'));
        }

        return (int) (new self())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => date('Y-m-d'),
            'method'                        => 'technical',
            'result'                        => $result,
            'users_id'                      => (int) Session::getLoginUserID(),
            'comment'                       => self::technicalCheckComment($check, $gaps, $outcome['total']),
        ]);
    }

    private static function technicalCheckComment(string $check, int $gaps, int $total): string
    {
        $label = ComplianceAudit::getCheckLabels()[$check]['label'] ?? $check;

        return sprintf(
            _n(
                'Automated technical check "%1$s": %2$d gap out of %3$d, %4$d%% compliant.',
                'Automated technical check "%1$s": %2$d gaps out of %3$d, %4$d%% compliant.',
                $gaps,
                'grcmanager'
            ),
            $label,
            $gaps,
            $total,
            (int) ComplianceAuditScore::rate($gaps, $total)
        );
    }

    /**
     * Contrôles techniques rattachés à la mesure, avec leur résultat actuel (onglet des tests).
     *
     * @return list<array<string, mixed>>
     */
    private static function technicalChecksInfo(Control $control): array
    {
        $checks = ControlTestRules::technicalChecksFor((string) $control->fields['code'], ComplianceAuditScore::CHECKS);
        if ($checks === []) {
            return [];
        }
        $labels = ComplianceAudit::getCheckLabels();
        $results = self::getResults();
        $info = [];
        foreach ($checks as $check) {
            $outcome = ComplianceAudit::runCheck($check);
            $gaps = count($outcome['gaps']);
            $result = $outcome['evaluated']
                ? ControlTestRules::resultFromTechnicalCheck($gaps, $outcome['total'])
                : null;
            $info[] = [
                'key'       => $check,
                'label'     => $labels[$check]['label'] ?? $check,
                'evaluated' => $outcome['evaluated'],
                'gaps'      => $gaps,
                'total'     => $outcome['total'],
                'rate'      => ComplianceAuditScore::rate($gaps, $outcome['total']),
                'result'    => $result === null ? null : $results[$result],
            ];
        }

        return $info;
    }

    /**
     * Tests d'une mesure, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForControl(int $controlsId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_controls_id' => $controlsId],
            ['test_date DESC', 'id DESC']
        ));
    }

    /**
     * Échéance du prochain test d'une mesure : celle du dernier test, sinon la date du dernier test
     * plus la fréquence du plan. Sans aucun test, pas d'échéance (premier test à planifier).
     *
     * @param array<string, mixed>      $control ligne de la mesure
     * @param array<string, mixed>|null $latest  dernier test de la mesure
     */
    public static function nextDueDate(array $control, ?array $latest): ?string
    {
        if ($latest === null) {
            return null;
        }

        return ControlTestRules::nextTestDate(
            (string) $latest['test_date'],
            $latest['next_test_date'] ?? null,
            (int) ($control['test_frequency_months'] ?? 0)
        );
    }

    /**
     * Échéances des tests de toutes les mesures applicables qui en ont une, de la plus proche à la
     * plus lointaine (calendrier de conformité, liste des tests en retard).
     *
     * @return list<array{control: array<string, mixed>, latest: array<string, mixed>, next: string}>
     */
    public static function getSchedule(): array
    {
        global $DB;

        $latest = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => ['test_date DESC', 'id DESC']]) as $row) {
            $latest[(int) $row['plugin_grcmanager_controls_id']] ??= $row;
        }
        if ($latest === []) {
            return [];
        }
        $schedule = [];
        $controls = $DB->request([
            'FROM'  => Control::getTable(),
            // Issue #536 : mesures des SoA suivies par les entités actives.
            'WHERE' => [
                'id'            => array_keys($latest),
                'applicability' => ['yes', 'partial'],
                'entities_id'   => EntityPerimeter::activeSoaEntities(),
            ],
        ]);
        foreach ($controls as $row) {
            $next = self::nextDueDate($row, $latest[(int) $row['id']]);
            if ($next !== null) {
                $schedule[] = ['control' => $row, 'latest' => $latest[(int) $row['id']], 'next' => $next];
            }
        }
        usort($schedule, static fn (array $a, array $b): int => strcmp($a['next'], $b['next']));

        return $schedule;
    }

    /**
     * @param string $name
     * @return array<string, string>
     */
    public static function cronInfo($name)
    {
        return $name === ControlTestReminder::CRON_NAME
            ? ['description' => __(
                'Reminds overdue or upcoming effectiveness tests and withdraws the expired "Verified" status',
                'grcmanager'
            )]
            : [];
    }

    /**
     * Issue #380 : rappels des tests et retrait de l'état « Vérifié » (ControlTestReminder::run()).
     */
    public static function cronControltestreminder(CronTask $task): int
    {
        $result = ControlTestReminder::run(date('Y-m-d'));
        $task->addVolume($result['notified'] + $result['withdrawn']);
        $task->log(sprintf(
            __('%d reminder(s), %d "Verified" state(s) removed.', 'grcmanager'),
            $result['notified'],
            $result['withdrawn']
        ));

        return ($result['notified'] + $result['withdrawn']) > 0 ? 1 : 0;
    }

    /**
     * Lien vers l'onglet des tests de la fiche d'une mesure.
     */
    public static function getControlTabURL(int $controlsId): string
    {
        return Control::getFormURLWithID($controlsId) . '&forcetab=' . urlencode(self::class . '$1');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Control || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = countElementsInTable(self::getTable(), ['plugin_grcmanager_controls_id' => $item->getID()]);

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Control || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForControl($item);

        return true;
    }

    private static function showForControl(Control $control): void
    {
        global $CFG_GLPI;

        $controlsId = (int) $control->getID();
        $canEdit = $control->can($controlsId, UPDATE);
        $tests = self::getForControl($controlsId);
        $canViewNc = Nonconformity::canView();
        // Issue #607 : échantillons de la mesure (ouverts, ou figés avec leur test).
        $samples = ControlTestSample::getForControl($controlsId);
        $samplesByTest = [];
        $openSamples = [];
        foreach ($samples as $sample) {
            $sample['url'] = ControlTestSample::getPageURL((int) $sample['id']);
            if (ControlTestSample::isOpen($sample)) {
                $openSamples[] = $sample;
            } else {
                $samplesByTest[(int) $sample['plugin_grcmanager_controltests_id']] = $sample;
            }
        }
        $sampleForm = $canEdit ? ControlTestSample::drawFormData() : [];
        $rows = [];
        foreach ($tests as $test) {
            $documentsId = (int) $test['documents_id'];
            $ncId = (int) $test['plugin_grcmanager_nonconformities_id'];
            // Nom et lien de la preuve seulement si le document est lisible (entité du document).
            $readable = $documentsId > 0 && Evidence::canReadDocument($documentsId);
            $rows[] = $test + [
                'user_name'     => (int) $test['users_id'] > 0 ? getUserName((int) $test['users_id']) : '',
                'document_name' => $readable ? Dropdown::getDropdownName('glpi_documents', $documentsId) : '',
                'document_url'  => $readable ? Document::getFormURLWithID($documentsId) : '',
                'nc_url'        => $ncId > 0 && $canViewNc ? Nonconformity::getFormURLWithID($ncId) : '',
                'can_purge'     => $canEdit && self::canPurge(),
                // Issue #607 : échantillon, relecture et rapport du test pour l'auditeur.
                'sample'        => $samplesByTest[(int) $test['id']] ?? null,
                'review'        => self::getReviewStatuses()[(string) ($test['review_status'] ?? '')] ?? null,
                'reviewer_name' => (int) ($test['users_id_reviewer'] ?? 0) > 0
                    ? getUserName((int) $test['users_id_reviewer'])
                    : '',
                'can_review'    => self::canReviewTest($test),
                'pdf_url'       => self::getPdfURL((int) $test['id']),
            ];
        }
        $latest = $tests[0] ?? null;
        $next = self::nextDueDate($control->fields, $latest);
        [$dueClass, $dueLabel] = self::dueInfo($next);
        $testerId = (int) ($control->fields['users_id_tester'] ?? 0);

        TemplateRenderer::getInstance()->display('@grcmanager/control_test_tab.html.twig', [
            'control'         => $control,
            'can_edit'        => $canEdit,
            'tests'           => $rows,
            'latest'          => $latest,
            'next_date'       => $next,
            'due_class'       => $dueClass,
            'due_label'       => $dueLabel,
            'frequency'       => (int) ($control->fields['test_frequency_months'] ?? 0),
            'plan_method'     => (string) ($control->fields['test_method'] ?? ''),
            'tester_name'     => $testerId > 0 ? getUserName($testerId) : '',
            'frequencies'     => self::getFrequencies(),
            'methods'         => self::getMethods(),
            'results'         => self::getResults(),
            'documents'       => $canEdit ? Evidence::getDocumentsFor($control) : [],
            // Envoi direct d'une preuve : droits de l'onglet Documents (storeEvidenceFile()).
            'can_upload'      => $canEdit && $control->canAddItem('Document') && Document::canCreate(),
            'max_upload'      => Document::getMaxUploadSize(),
            // Issue #380 (#162) : contrôles techniques automatiques rattachés à la mesure.
            'technical_checks' => $canEdit ? self::technicalChecksInfo($control) : [],
            'all_entities'    => Session::canViewAllEntities(),
            'open_samples'    => $openSamples,
            'populations'     => ControlTestSample::getPopulations(),
            'sample_form'     => $sampleForm,
            'sample_category_dropdown' => $sampleForm['category_dropdown'] ?? '',
            'sample_url'      => ControlTestSample::getFormURL(),
            'review_enabled'  => self::isReviewEnabled(),
            'technical_threshold' => ControlTestRules::TECHNICAL_PARTIAL_THRESHOLD,
            'documents_url'   => Control::getFormURLWithID($controlsId) . '&forcetab=Document_Item$1',
            'today'           => date('Y-m-d'),
            'form_url'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/controltest.form.php',
            // Listes déroulantes natives de GLPI (utilisateurs actifs que la session peut voir).
            'tester_dropdown' => $canEdit ? User::dropdown([
                'name'    => 'users_id_tester',
                'value'   => $testerId,
                'right'   => 'all',
                'display' => false,
                'width'   => '100%',
            ]) : '',
            'user_dropdown'   => $canEdit ? User::dropdown([
                'name'    => 'users_id',
                'value'   => (int) Session::getLoginUserID(),
                'right'   => 'all',
                'display' => false,
                'width'   => '100%',
            ]) : '',
        ]);
    }

    /**
     * Issue #607 : fiche PDF d'un test pour l'auditeur (mesure, résultat, constat, preuve,
     * relecture et, s'il y en a un, échantillon tiré avec le résultat de chaque élément). Les droits
     * sont vérifiés par l'appelant (lecture de la mesure).
     */
    public static function renderRecord(self $test): string
    {
        $control = new Control();
        $control->getFromDB((int) $test->fields['plugin_grcmanager_controls_id']);
        $code = (string) ($control->fields['code'] ?? '');
        $fields = $test->fields;
        $documentsId = (int) $fields['documents_id'];
        $sample = ControlTestSample::getForTest((int) $test->getID());

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/control_test_record.html.twig', [
            'report_title'       => sprintf(__('Effectiveness test of %s', 'grcmanager'), $code),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'code'               => $code,
            'control_title'      => Control::getControlTitle($code),
            'test'               => $fields,
            'method'             => self::getMethods()[(string) $fields['method']] ?? (string) $fields['method'],
            'result'             => self::getResults()[(string) $fields['result']]['label']
                ?? (string) $fields['result'],
            'tester'             => (int) $fields['users_id'] > 0 ? getUserName((int) $fields['users_id']) : '',
            'recorder'           => (int) ($fields['users_id_recorder'] ?? 0) > 0
                ? getUserName((int) $fields['users_id_recorder'])
                : '',
            'document'           => $documentsId > 0 && Evidence::canReadDocument($documentsId)
                ? Dropdown::getDropdownName('glpi_documents', $documentsId)
                : '',
            'review'             => self::getReviewStatuses()[(string) ($fields['review_status'] ?? '')]['label'] ?? '',
            'reviewer'           => (int) ($fields['users_id_reviewer'] ?? 0) > 0
                ? getUserName((int) $fields['users_id_reviewer'])
                : '',
            'sample_view'        => $sample === null ? null : ControlTestSample::viewData($sample),
        ]);
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'            => 1,
            'table'         => Control::getTable(),
            'field'         => 'code',
            'name'          => __('Control', 'grcmanager'),
            'datatype'      => 'itemlink',
            'itemlink_type' => Control::class,
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'test_date',
            'name'     => __('Test date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'method',
            'name'     => __('Method', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'result',
            'name'     => __('Result', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => User::getTable(),
            'field'    => 'name',
            'name'     => __('Tester', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'next_test_date',
            'name'     => __('Next test', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Finding', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'            => 8,
            'table'         => Nonconformity::getTable(),
            'field'         => 'title',
            'name'          => Nonconformity::getTypeName(1),
            'datatype'      => 'itemlink',
            'itemlink_type' => Nonconformity::class,
            'massiveaction' => false,
        ];

        // Issue #607 : relecture du test.
        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'review_status',
            'name'     => __('Review', 'grcmanager'),
            'datatype' => 'specific',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'method':
                return htmlescape(self::getMethods()[$values[$field] ?? ''] ?? (string) ($values[$field] ?? ''));

            case 'result':
                $result = self::getResults()[$values[$field] ?? ''] ?? null;

                return $result === null ? '' : Badge::html($result['class'], $result['icon'], $result['label']);

            case 'review_status':
                $review = self::getReviewStatuses()[$values[$field] ?? ''] ?? null;

                return $review === null ? '' : Badge::html($review['class'], $review['icon'], $review['label']);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'method':
                return Dropdown::showFromArray($name, self::getMethods(), $options);

            case 'result':
                return Dropdown::showFromArray(
                    $name,
                    array_map(static fn (array $r): string => $r['label'], self::getResults()),
                    $options
                );

            case 'review_status':
                return Dropdown::showFromArray(
                    $name,
                    array_map(static fn (array $r): string => $r['label'], self::getReviewStatuses()),
                    $options
                );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
