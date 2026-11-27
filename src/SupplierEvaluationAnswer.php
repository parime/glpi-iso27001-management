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

use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use Log;
use Session;

/**
 * Issue #864 : réponses d'une évaluation fournisseur, une ligne par question copiée du modèle :
 * réponse (oui, partiel, non, sans objet ; ou, pour l'échelle de conformité de l'issue #1159,
 * conforme, partiellement conforme, non conforme, non applicable), commentaire et pièce justificative.
 *
 * La pièce est un Document GLPI joint au risque fournisseur (onglet Documents) et réservé à ses
 * lecteurs (RestrictedDocument) : contrôles habituels des preuves (types autorisés par GLPI, taille
 * maximale, type réel du contenu, voir ControlTest::createEvidenceDocument()).
 *
 * Issue #865 : une réponse venue d'un lien ou d'un fichier du fournisseur portera son origine
 * (answer_source) ; la même ligne et les mêmes contrôles servent.
 */
final class SupplierEvaluationAnswer
{
    /**
     * Copie des questions du modèle dans l'évaluation (une seule fois par code).
     */
    public static function createFromTemplate(SupplierEvaluation $evaluation): int
    {
        global $DB;

        $created = 0;
        $templateId = (int) $evaluation->fields[Tables::TEMPLATE_FK];
        foreach (SupplierQuestionnaireQuestion::getQuestions($templateId) as $question) {
            $exists = countElementsInTable(Tables::ANSWERS, [
                Tables::EVALUATION_FK => $evaluation->getID(),
                'code'                => (string) $question['code'],
            ]) > 0;
            if ($exists) {
                continue;
            }
            $DB->insert(Tables::ANSWERS, [
                Tables::EVALUATION_FK => $evaluation->getID(),
                Tables::QUESTION_FK   => (int) $question['id'],
                'code'                => (string) $question['code'],
                'section'             => (string) $question['section'],
                'question'            => (string) $question['question'],
                'guidance'            => (string) ($question['guidance'] ?? ''),
                'expected_answer'     => (string) $question['expected_answer'],
                'answer_type'         => Rules::normalizeAnswerType($question['answer_type'] ?? ''),
                'weight'              => (int) $question['weight'],
                'requires_evidence'   => (int) $question['requires_evidence'],
                'position'            => (int) $question['position'],
                'answer'              => '',
                'answer_source'       => Rules::SOURCE_MANUAL,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getAnswers(int $evaluationId): array
    {
        global $DB;

        if ($evaluationId <= 0) {
            return [];
        }

        return array_values(iterator_to_array($DB->request([
            'FROM'  => Tables::ANSWERS,
            'WHERE' => [Tables::EVALUATION_FK => $evaluationId],
            'ORDER' => ['position', 'id'],
        ]), false));
    }

    /**
     * Enregistre les réponses et commentaires envoyés (lignes de cette évaluation seulement), retire
     * les pièces décochées, puis recalcule le score. Rien sur une évaluation terminée.
     *
     * @param array<mixed> $answers  id de la réponse => réponse
     * @param array<mixed> $comments id de la réponse => commentaire
     * @param array<mixed> $detach   id de la réponse => 1 pour retirer la pièce
     * @return int lignes modifiées, -1 si l'évaluation est terminée
     */
    public static function saveAnswers(
        SupplierEvaluation $evaluation,
        array $answers,
        array $comments,
        array $detach = []
    ): int {
        global $DB;

        if ($evaluation->isCompleted()) {
            return -1;
        }
        $changed = 0;
        $now = date('Y-m-d H:i:s');
        foreach (self::getAnswers($evaluation->getID()) as $row) {
            $id = (int) $row['id'];
            $fields = [];
            if (array_key_exists($id, $answers)) {
                $fields['answer'] = Rules::normalizeAnswer($answers[$id]);
            }
            if (array_key_exists($id, $comments)) {
                $fields['answer_comment'] = Rules::cleanText($comments[$id]);
            }
            if (!empty($detach[$id]) && (int) $row['documents_id'] > 0) {
                $fields['documents_id'] = 0;
            }
            $fields = array_diff_assoc(
                array_map('strval', $fields),
                array_map('strval', array_intersect_key($row, $fields))
            );
            if ($fields === []) {
                continue;
            }
            $DB->update(Tables::ANSWERS, $fields + [
                'answer_source' => Rules::SOURCE_MANUAL,
                'users_id'      => (int) Session::getLoginUserID(),
                'date_mod'      => $now,
            ], ['id' => $id]);
            $changed++;
        }
        if ($changed > 0) {
            Log::history($evaluation->getID(), SupplierEvaluation::class, [
                0,
                '',
                sprintf(_n('%d answer updated', '%d answers updated', $changed, 'grcmanager'), $changed),
            ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        }
        $evaluation->refreshScore();

        return $changed;
    }

    /**
     * Rattache une pièce justificative reçue à une réponse de l'évaluation : Document GLPI joint au
     * risque fournisseur, dans son entité, lisible avec ses droits. 0 en cas de refus (message en
     * session).
     */
    public static function attachEvidence(
        SupplierEvaluation $evaluation,
        int $answerId,
        string $path,
        string $clientName
    ): int {
        global $DB;

        $refuse = static function (string $message): int {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

            return 0;
        };
        $row = $DB->request([
            'FROM'  => Tables::ANSWERS,
            'WHERE' => ['id' => $answerId, Tables::EVALUATION_FK => $evaluation->getID()],
        ])->current();
        $risk = $evaluation->getRisk();
        if ($row === null || $risk === null || $evaluation->isCompleted()) {
            return $refuse(__('This question cannot receive a document.', 'grcmanager'));
        }
        if (!$risk->canAddItem('Document') || !Document::canCreate()) {
            return $refuse(__('You are not allowed to add a document to this supplier risk.', 'grcmanager'));
        }
        $documentsId = ControlTest::createEvidenceDocument($path, $clientName, [
            'itemtype'    => SupplierRisk::class,
            'items_id'    => $risk->getID(),
            'entities_id' => (int) $risk->fields['entities_id'],
            'comment'     => sprintf(
                __('Evidence for question %1$s of the evaluation "%2$s"', 'grcmanager'),
                (string) $row['code'],
                $evaluation->getName()
            ),
        ], true);
        if ($documentsId <= 0) {
            return 0;
        }
        RestrictedDocument::register($documentsId, $risk);
        $DB->update(Tables::ANSWERS, [
            'documents_id' => $documentsId,
            'users_id'     => (int) Session::getLoginUserID(),
            'date_mod'     => date('Y-m-d H:i:s'),
        ], ['id' => $answerId]);
        Log::history($evaluation->getID(), SupplierEvaluation::class, [
            0,
            '',
            sprintf(__('Evidence added to question %s', 'grcmanager'), (string) $row['code']),
        ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

        return $documentsId;
    }

    /**
     * Pièces lisibles par l'utilisateur, par réponse : [nom, lien].
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{name: string, url: string}>
     */
    private static function documents(array $rows): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $documentsId = (int) $row['documents_id'];
            if ($documentsId <= 0) {
                continue;
            }
            $document = new Document();
            if (!$document->getFromDB($documentsId) || !$document->can($documentsId, READ)) {
                continue;
            }
            $documents[(int) $row['id']] = [
                'name' => (string) ($document->fields['filename'] ?: $document->fields['name']),
                'url'  => Document::getFormURLWithID($documentsId),
            ];
        }

        return $documents;
    }

    public static function showForEvaluation(SupplierEvaluation $evaluation): void
    {
        global $CFG_GLPI;

        $rows = self::getAnswers($evaluation->getID());
        $sections = [];
        foreach ($rows as $row) {
            $row['missing_evidence'] = Rules::evidenceMissing($row);
            $row['points'] = Rules::points((string) $row['answer'], Rules::expectedFor($row));
            // Issue #1159 : réponses proposées et réponse attendue selon le type de la question.
            $row['choices'] = SupplierQuestionnaireQuestion::choicesFor($row['answer_type'] ?? '');
            $row['expected_label'] = $row['choices'][Rules::expectedFor($row)] ?? (string) $row['expected_answer'];
            $row['is_scale'] = Rules::normalizeAnswerType($row['answer_type'] ?? '') === Rules::TYPE_COMPLIANCE;
            $sections[(string) $row['section']][] = $row;
        }
        $risk = $evaluation->getRisk();
        $canUpdate = (bool) $evaluation->can($evaluation->getID(), UPDATE);
        $canEdit = !$evaluation->isCompleted() && $canUpdate;
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_evaluation_answers.html.twig', [
            'evaluation_id' => $evaluation->getID(),
            'sections'      => $sections,
            'summary'       => Rules::summary($rows),
            'levels'        => SupplierEvaluation::getLevels(),
            'documents'     => self::documents($rows),
            'can_edit'      => $canEdit,
            'can_upload'    => $canEdit && $risk !== null && $risk->canAddItem('Document') && Document::canCreate(),
            'completed'     => $evaluation->isCompleted(),
            'can_reopen'    => $evaluation->isCompleted() && $canUpdate,
            'max_size'      => (int) $CFG_GLPI['document_max_size'],
            'action'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierevaluation.form.php',
        ]);
    }
}
