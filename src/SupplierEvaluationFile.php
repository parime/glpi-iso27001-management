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
use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierAnswerFile as AnswerFile;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use Log;
use Session;

/**
 * Issue #865 : réponse du fournisseur hors ligne. Onglet « Fichier fournisseur » d'une évaluation :
 * export du questionnaire à remplir (CSV), import du fichier retourné avec aperçu, puis
 * enregistrement sur confirmation (front/supplierevaluationfile.php).
 *
 * Droits : ceux de l'évaluation (lire pour exporter, modifier pour importer). Les réponses importées
 * portent l'origine « file » ; le fichier reçu est gardé comme document du risque fournisseur et
 * l'export comme l'import sont tracés dans l'historique de l'évaluation (qui, quand, quel fichier).
 */
class SupplierEvaluationFile extends CommonGLPI
{
    public const SESSION_KEY = 'grcmanager_supplier_answer_file';

    public static function getTypeName($nb = 0)
    {
        return __('Supplier file', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-spreadsheet';
    }

    public static function canView(): bool
    {
        return SupplierEvaluation::canView();
    }

    public static function getActionURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierevaluationfile.php';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof SupplierEvaluation || $item->isNewItem() || !SupplierEvaluation::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof SupplierEvaluation || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_evaluation_file.html.twig', [
            'evaluation_id' => $item->getID(),
            'can_import'    => self::canImport($item),
            'completed'     => $item->isCompleted(),
            'action'        => self::getActionURL(),
            'max_mb'        => AnswerFile::MAX_BYTES / 1048576,
            'max_rows'      => AnswerFile::MAX_ROWS,
        ]);

        return true;
    }

    public static function canImport(SupplierEvaluation $evaluation): bool
    {
        return !$evaluation->isCompleted() && (bool) $evaluation->can($evaluation->getID(), UPDATE);
    }

    /**
     * @return array<string, string>
     */
    public static function answerLabels(): array
    {
        return SupplierAssessment::getAnswerLabels();
    }

    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return [
            __('Code', 'grcmanager'),
            __('Section', 'grcmanager'),
            __('Question', 'grcmanager'),
            __('Guidance', 'grcmanager'),
            __('Expected answer', 'grcmanager'),
            __('Evidence required', 'grcmanager'),
            __('Allowed answers', 'grcmanager'),
            __('Supplier answer', 'grcmanager'),
            __('Comment', 'grcmanager'),
            __('Evidence file name', 'grcmanager'),
        ];
    }

    public static function fileName(SupplierEvaluation $evaluation): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $evaluation->getName());

        return sprintf('questionnaire-%d-%s.csv', $evaluation->getID(), trim(substr($base, 0, 60), '-'));
    }

    /**
     * Fichier à envoyer au fournisseur ; l'export est tracé dans l'historique de l'évaluation.
     */
    public static function export(SupplierEvaluation $evaluation): string
    {
        $answers = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
        $content = AnswerFile::build($evaluation->getID(), $answers, self::header(), self::answerLabels());
        Log::history($evaluation->getID(), SupplierEvaluation::class, [
            0,
            '',
            sprintf(
                __('Questionnaire file exported for the supplier: %1$s (fingerprint %2$s)', 'grcmanager'),
                self::fileName($evaluation),
                Rules::fingerprint($answers)
            ),
        ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyse(SupplierEvaluation $evaluation, string $content): array
    {
        return AnswerFile::parse(
            $content,
            $evaluation->getID(),
            SupplierEvaluationAnswer::getAnswers($evaluation->getID()),
            self::answerLabels()
        );
    }

    /**
     * Enregistre les réponses confirmées, rattache les pièces reçues par leur nom et garde le fichier
     * reçu comme document du risque fournisseur. Rien sur une évaluation terminée.
     *
     * @param array<string, mixed>                          $parsed résultat de analyse()
     * @param list<array{path: string, name: string}>       $files  pièces déposées par le RSSI
     * @return array{answers: int, documents: int, unmatched: list<string>}
     */
    public static function apply(
        SupplierEvaluation $evaluation,
        array $parsed,
        string $receivedPath,
        string $receivedName,
        array $files = []
    ): array {
        global $DB;

        $result = ['answers' => 0, 'documents' => 0, 'unmatched' => []];
        if ($evaluation->isCompleted() || ($parsed['error'] ?? null) !== null) {
            return $result;
        }
        $valid = [];
        foreach (SupplierEvaluationAnswer::getAnswers($evaluation->getID()) as $row) {
            $valid[(int) $row['id']] = true;
        }
        $now = date('Y-m-d H:i:s');
        $userId = (int) Session::getLoginUserID();
        foreach ($parsed['changes'] as $change) {
            $id = (int) $change['id'];
            $fields = [];
            if ($change['answer'] !== null) {
                $fields['answer'] = Rules::normalizeAnswer($change['answer']);
            }
            if ($change['comment'] !== null) {
                $fields['answer_comment'] = Rules::cleanText($change['comment']);
            }
            if ($fields === [] || !isset($valid[$id])) {
                continue;
            }
            $DB->update(Tables::ANSWERS, $fields + [
                'answer_source' => AnswerFile::SOURCE_FILE,
                'users_id'      => $userId,
                'date_mod'      => $now,
            ], ['id' => $id, Tables::EVALUATION_FK => $evaluation->getID()]);
            $result['answers']++;
        }

        // Pièces jointes rattachées aux questions par le nom indiqué dans la colonne prévue.
        $expected = is_array($parsed['evidence'] ?? null) ? $parsed['evidence'] : [];
        foreach ($files as $file) {
            $key = AnswerFile::evidenceName($file['name']);
            $answerIds = array_values(array_filter(
                $expected[$key] ?? [],
                static fn ($id): bool => isset($valid[(int) $id])
            ));
            if ($answerIds === []) {
                $result['unmatched'][] = $file['name'];
                continue;
            }
            $documentsId = SupplierEvaluationAnswer::attachEvidence(
                $evaluation,
                (int) $answerIds[0],
                $file['path'],
                $file['name']
            );
            if ($documentsId <= 0) {
                continue;
            }
            $result['documents']++;
            foreach (array_slice($answerIds, 1) as $answerId) {
                $DB->update(Tables::ANSWERS, [
                    'documents_id' => $documentsId,
                    'users_id'     => $userId,
                    'date_mod'     => $now,
                ], ['id' => (int) $answerId, Tables::EVALUATION_FK => $evaluation->getID()]);
            }
        }

        $risk = $evaluation->getRisk();
        $receivedId = 0;
        // Fichier reçu gardé comme document si GLPI accepte le type .csv (sinon, l'historique suffit).
        if (
            $risk !== null && $risk->canAddItem('Document') && Document::canCreate()
            && (string) Document::isValidDoc($receivedName) !== ''
        ) {
            $receivedId = ControlTest::createEvidenceDocument($receivedPath, $receivedName, [
                'itemtype'    => SupplierRisk::class,
                'items_id'    => $risk->getID(),
                'entities_id' => (int) $risk->fields['entities_id'],
                'comment'     => sprintf(
                    __('Questionnaire file returned by the supplier for the evaluation "%s"', 'grcmanager'),
                    $evaluation->getName()
                ),
            ], true);
            if ($receivedId > 0) {
                RestrictedDocument::register($receivedId, $risk);
            }
        }
        Log::history($evaluation->getID(), SupplierEvaluation::class, [
            0,
            '',
            sprintf(
                __('Supplier file imported: %1$s (SHA-256 %2$s), %3$d answer(s), %4$d document(s)', 'grcmanager'),
                EvidenceUploadRules::safeName($receivedName) ?? 'file.csv',
                substr(hash_file('sha256', $receivedPath) ?: '', 0, 16),
                $result['answers'],
                $result['documents']
            ),
        ], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        $evaluation->refreshScore();

        return $result;
    }

    public static function errorMessage(string $error): string
    {
        return match ($error) {
            AnswerFile::ERROR_TOO_LARGE      => sprintf(
                __('The file is too large (at most %d MB).', 'grcmanager'),
                AnswerFile::MAX_BYTES / 1048576
            ),
            AnswerFile::ERROR_BINARY         => __(
                'The file is not a CSV text file: save the spreadsheet as CSV before importing it.',
                'grcmanager'
            ),
            AnswerFile::ERROR_NOT_QUESTIONNAIRE => __(
                'This file is not a questionnaire exported from this plugin: its first line was changed or removed.',
                'grcmanager'
            ),
            AnswerFile::ERROR_OTHER_EVALUATION => __(
                'This file was exported for another evaluation: import it in the evaluation it comes from.',
                'grcmanager'
            ),
            AnswerFile::ERROR_OTHER_QUESTIONS => __(
                'The questions of this file do not match those of the evaluation: export the questionnaire again.',
                'grcmanager'
            ),
            AnswerFile::ERROR_NO_HEADER      => __(
                'The header line or some columns are missing: keep the columns of the exported file.',
                'grcmanager'
            ),
            default                          => __('The file is empty.', 'grcmanager'),
        };
    }

    /**
     * @param array{line: int, warning: string, value: string} $warning
     */
    public static function warningMessage(array $warning): string
    {
        return match ($warning['warning']) {
            AnswerFile::WARNING_UNKNOWN_CODE     => sprintf(
                __('Line %1$d: unknown question code "%2$s", line ignored.', 'grcmanager'),
                $warning['line'],
                $warning['value']
            ),
            AnswerFile::WARNING_DUPLICATE_CODE   => sprintf(
                __('Line %1$d: question %2$s appears twice, only the first line is kept.', 'grcmanager'),
                $warning['line'],
                $warning['value']
            ),
            AnswerFile::WARNING_BAD_ANSWER       => sprintf(
                __('Line %1$d: answer "%2$s" is not in the list, line ignored.', 'grcmanager'),
                $warning['line'],
                $warning['value']
            ),
            AnswerFile::WARNING_TOO_MANY         => sprintf(
                __('Line %1$d: at most %2$d questions per file, the following lines were ignored.', 'grcmanager'),
                $warning['line'],
                AnswerFile::MAX_ROWS
            ),
            default                              => sprintf(
                __('Question %s is missing from the file: its answer does not change.', 'grcmanager'),
                $warning['value']
            ),
        };
    }

    /**
     * Données de l'aperçu (étape avant confirmation).
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public static function previewData(SupplierEvaluation $evaluation, array $parsed, string $fileName): array
    {
        $risk = $evaluation->getRisk();

        return [
            'evaluation_id'   => $evaluation->getID(),
            'evaluation_name' => $evaluation->getName(),
            'evaluation_url'  => SupplierEvaluation::getFormURLWithID($evaluation->getID()),
            'file_name'       => $fileName,
            'error'           => $parsed['error'] !== null ? self::errorMessage((string) $parsed['error']) : null,
            'changes'         => $parsed['changes'],
            'warnings'        => array_map([self::class, 'warningMessage'], $parsed['warnings']),
            'evidence_names'  => array_keys($parsed['evidence']),
            'labels'          => self::answerLabels(),
            'can_upload'      => $risk !== null && $risk->canAddItem('Document') && Document::canCreate(),
            'action'          => self::getActionURL(),
        ];
    }
}
