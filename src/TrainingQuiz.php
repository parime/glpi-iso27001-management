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
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Training\TrainingScore;
use Html;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Training;
use Preference;
use Session;

/**
 * Issue #139 : copie de quiz corrigée de chaque participant d'une session de formation.
 *
 * Une copie est une donnée personnelle : elle ne se lit que par le participant lui-même, le
 * formateur de la session et le groupe RSSI (configuration du plugin). Le document est inscrit
 * au registre RestrictedDocument avec pour source la ligne de participation, et
 * n'est rattaché à aucun objet : il n'apparaît dans aucune liste de documents.
 *
 * Un participant n'a en général ni le droit Documents de GLPI ni le droit du plugin : il lit sa
 * copie depuis l'onglet « Mes formations » de ses préférences, et la télécharge par
 * front/trainingquiz.send.php, qui vérifie l'audience et historise l'accès.
 */
class TrainingQuiz extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_trainings_users';

    /**
     * Ligne de participation, avec le formateur de la session.
     *
     * @return array{id: int, trainings_id: int, users_id: int, documents_id: int, trainer: int}|null
     */
    public static function getParticipation(int $participationId): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT'     => [
                'links.id', 'links.plugin_grcmanager_trainings_id', 'links.users_id', 'links.documents_id',
                't.users_id_trainer',
            ],
            'FROM'       => self::TABLE . ' AS links',
            'INNER JOIN' => [
                Training::getTable() . ' AS t' => [
                    'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                ],
            ],
            'WHERE'      => ['links.id' => $participationId],
        ])->current();

        return $row === null ? null : [
            'id'           => (int) $row['id'],
            'trainings_id' => (int) $row['plugin_grcmanager_trainings_id'],
            'users_id'     => (int) $row['users_id'],
            'documents_id' => (int) $row['documents_id'],
            'trainer'      => (int) $row['users_id_trainer'],
        ];
    }

    public static function getParticipationId(int $trainingId, int $usersId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::TABLE,
            'WHERE'  => ['plugin_grcmanager_trainings_id' => $trainingId, 'users_id' => $usersId],
        ])->current();

        return (int) ($row['id'] ?? 0);
    }

    /**
     * Le participant, le formateur de la session ou un membre du groupe RSSI.
     */
    public static function canRead(int $participationId, ?int $usersId = null): bool
    {
        $usersId ??= (int) Session::getLoginUserID();
        $participation = self::getParticipation($participationId);
        if ($participation === null || $usersId <= 0) {
            return false;
        }

        return $usersId === $participation['users_id']
            || ($participation['trainer'] > 0 && $usersId === $participation['trainer'])
            || AuditTeam::isCiso($usersId);
    }

    /**
     * Joint la copie (fichier déjà dans GLPI_TMP_DIR) au participant ; remplace la précédente.
     *
     * @return int id du document, 0 si GLPI l'a refusé (type de fichier non autorisé…)
     */
    public static function attach(int $participationId, string $tmpName, string $prefix): int
    {
        global $DB;

        $participation = self::getParticipation($participationId);
        if ($participation === null) {
            return 0;
        }
        $documentsId = (int) (new Document())->add([
            'name'              => sprintf(
                __('Quiz copy - %1$s - %2$s', 'grcmanager'),
                getUserName($participation['users_id']),
                Dropdown::getDropdownName(Training::getTable(), $participation['trainings_id'])
            ),
            'entities_id'       => 0,
            'is_recursive'      => 0,
            '_filename'         => [$tmpName],
            '_prefix_filename'  => [$prefix],
        ]);
        if ($documentsId <= 0) {
            return 0;
        }
        RestrictedDocument::registerFor($documentsId, self::class, $participationId);
        self::purgeDocument($participation['documents_id']);
        $DB->update(self::TABLE, ['documents_id' => $documentsId], ['id' => $participationId]);

        return $documentsId;
    }

    public static function deleteCopy(int $participationId): void
    {
        global $DB;

        $participation = self::getParticipation($participationId);
        if ($participation !== null && $participation['documents_id'] > 0) {
            self::purgeDocument($participation['documents_id']);
            $DB->update(self::TABLE, ['documents_id' => 0], ['id' => $participationId]);
        }
    }

    /**
     * Le participant quitte la session, ou la session est supprimée : sa copie disparaît.
     */
    public static function deleteCopyOf(int $trainingId, int $usersId): void
    {
        $participationId = self::getParticipationId($trainingId, $usersId);
        if ($participationId > 0) {
            self::deleteCopy($participationId);
        }
    }

    private static function purgeDocument(int $documentsId): void
    {
        if ($documentsId > 0) {
            (new Document())->delete(['id' => $documentsId], true);
        }
    }

    public static function getDownloadURL(int $participationId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/trainingquiz.send.php?id=' . $participationId;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('My trainings', 'grcmanager'), 0, null, 'ti ti-school');
        }
        if (!$item instanceof Training || $item->isNewItem() || !Training::canView()) {
            return '';
        }

        return self::createTabEntry(__('Quiz copies', 'grcmanager'), 0, null, 'ti ti-file-certificate');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if ($item instanceof Preference) {
            self::showMyTrainings();

            return true;
        }
        if (!$item instanceof Training || !$item->can($item->getID(), READ)) {
            return false;
        }
        global $CFG_GLPI;

        $maxScore = (int) ($item->fields['max_score'] ?? 0);
        $rows = [];
        foreach (Training::getParticipants($item->getID()) as $participant) {
            $participationId = self::getParticipationId($item->getID(), $participant['users_id']);
            $participation = self::getParticipation($participationId);
            $canRead = self::canRead($participationId);
            $rows[] = [
                'id'        => $participationId,
                'name'      => $participant['name'],
                'score'     => $participant['score'] !== null && $maxScore > 0
                    ? TrainingScore::format($participant['score'], $maxScore) : '',
                'has_copy'  => ($participation['documents_id'] ?? 0) > 0,
                'can_read'  => $canRead,
                'url'       => $canRead ? self::getDownloadURL($participationId) : '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/training_quiz_tab.html.twig', [
            'rows'     => $rows,
            'can_edit' => $item->can($item->getID(), UPDATE),
            'action'   => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/trainingquiz.form.php',
        ]);

        return true;
    }

    /**
     * Onglet « Mes formations » des préférences : les participations de l'utilisateur connecté,
     * sans droit sur le plugin.
     */
    private static function showMyTrainings(): void
    {
        global $DB;

        $results = Training::getAssessmentResults();
        $statuses = Training::getCompletionStatuses();
        $rows = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'links.id', 'links.completion_status', 'links.completion_date', 'links.assessment_result',
                    'links.score', 'links.documents_id', 't.title', 't.date_delivered', 't.max_score',
                ],
                'FROM'       => self::TABLE . ' AS links',
                'INNER JOIN' => [
                    Training::getTable() . ' AS t' => [
                        'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                    ],
                ],
                'WHERE'      => ['links.users_id' => (int) Session::getLoginUserID()],
                'ORDER'      => ['t.date_delivered DESC', 't.id DESC'],
            ]) as $row
        ) {
            $maxScore = (int) $row['max_score'];
            $rows[] = [
                'title'  => (string) $row['title'],
                'date'   => $row['date_delivered'] ? Html::convDate($row['date_delivered']) : '',
                'status' => $statuses[$row['completion_status']] ?? (string) $row['completion_status'],
                'result' => $row['assessment_result'] !== 'not_applicable'
                    ? ($results[$row['assessment_result']] ?? '') : '',
                'score'  => $row['score'] !== null && $maxScore > 0
                    ? TrainingScore::format((float) $row['score'], $maxScore) : '',
                'url'    => (int) $row['documents_id'] > 0 ? self::getDownloadURL((int) $row['id']) : '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/my_trainings_tab.html.twig', ['rows' => $rows]);
    }
}
