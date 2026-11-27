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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;

/**
 * Issue #864 : questions d'un modèle de questionnaire fournisseur (onglet « Questions » du modèle),
 * modifiées ensemble depuis une seule grille, avec le droit de modifier le modèle.
 *
 * Chaque question a un code stable dans le modèle (Q01, Q02... ou la clé d'une question reprise) :
 * c'est par lui qu'un fichier retourné par le fournisseur (issue #865) retrouvera ses questions.
 */
final class SupplierQuestionnaireQuestion
{
    /** Lignes vides proposées pour ajouter des questions. */
    public const NEW_ROWS = 3;

    /**
     * @return list<array<string, mixed>>
     */
    public static function getQuestions(int $templateId): array
    {
        global $DB;

        if ($templateId <= 0) {
            return [];
        }

        return array_values(iterator_to_array($DB->request([
            'FROM'  => Tables::QUESTIONS,
            'WHERE' => [Tables::TEMPLATE_FK => $templateId],
            'ORDER' => ['position', 'id'],
        ]), false));
    }

    /**
     * Enregistre la grille : questions modifiées ou supprimées, nouvelles questions (texte non
     * vide). La version du modèle augmente si quelque chose a changé.
     *
     * @param array<mixed> $rows    id => champs, pour les questions existantes
     * @param array<mixed> $newRows lignes ajoutées
     * @return array{changed: int, added: int, deleted: int}
     */
    public static function saveQuestions(SupplierQuestionnaireTemplate $template, array $rows, array $newRows): array
    {
        global $DB;

        $result = ['changed' => 0, 'added' => 0, 'deleted' => 0];
        $existing = [];
        foreach (self::getQuestions($template->getID()) as $question) {
            $existing[(int) $question['id']] = $question;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $id => $row) {
            $id = (int) $id;
            if (!isset($existing[$id]) || !is_array($row)) {
                continue;
            }
            if (!empty($row['delete'])) {
                $DB->delete(Tables::QUESTIONS, ['id' => $id]);
                unset($existing[$id]);
                $result['deleted']++;
                continue;
            }
            $fields = self::fields($row, $existing[$id]);
            if ($fields['question'] === '') {
                // Texte effacé : la question est gardée telle quelle plutôt que vidée.
                continue;
            }
            $changes = array_diff_assoc(
                array_map('strval', $fields),
                array_map('strval', array_intersect_key($existing[$id], $fields))
            );
            if ($changes !== []) {
                $DB->update(Tables::QUESTIONS, $fields + ['date_mod' => $now], ['id' => $id]);
                $result['changed']++;
            }
        }
        $codes = array_map(static fn (array $q): string => (string) $q['code'], array_values($existing));
        $position = max([0, ...array_map(static fn (array $q): int => (int) $q['position'], array_values($existing))]);
        foreach ($newRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fields = self::fields($row, []);
            if ($fields['question'] === '') {
                continue;
            }
            $code = Rules::nextCode($codes);
            $codes[] = $code;
            if ((int) ($row['position'] ?? 0) <= 0) {
                $position += 10;
                $fields['position'] = $position;
            }
            $DB->insert(Tables::QUESTIONS, $fields + [
                Tables::TEMPLATE_FK => $template->getID(),
                'code'              => $code,
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);
            $result['added']++;
        }
        if (array_sum($result) > 0) {
            $template->bumpVersion();
        }

        return $result;
    }

    /**
     * @param array<mixed>         $row
     * @param array<string, mixed> $current
     * @return array{section: string, question: string, guidance: string, answer_type: string,
     *     expected_answer: string, weight: int, requires_evidence: int, position: int}
     */
    private static function fields(array $row, array $current): array
    {
        // Issue #1159 : type absent du formulaire (ancien envoi) = type actuel, « Oui / Non » sinon ;
        // l'échelle de conformité attend toujours « Conforme » (code yes).
        $type = Rules::normalizeAnswerType($row['answer_type'] ?? ($current['answer_type'] ?? ''));

        return [
            'section'           => Rules::cleanText($row['section'] ?? '', Rules::MAX_NAME_LENGTH),
            'question'          => Rules::cleanText($row['question'] ?? ''),
            'guidance'          => Rules::cleanText($row['guidance'] ?? ''),
            'answer_type'       => $type,
            'expected_answer'   => $type === Rules::TYPE_COMPLIANCE
                ? Rules::ANSWER_YES
                : Rules::normalizeExpected($row['expected_answer'] ?? ''),
            'weight'            => Rules::normalizeWeight($row['weight'] ?? Rules::DEFAULT_WEIGHT),
            'requires_evidence' => empty($row['requires_evidence']) ? 0 : 1,
            'position'          => max(0, (int) ($row['position'] ?? ($current['position'] ?? 0))),
        ];
    }

    /**
     * Libellés des réponses attendues.
     *
     * @return array<string, string>
     */
    public static function expectedLabels(): array
    {
        return [
            Rules::ANSWER_YES => __('Yes', 'grcmanager'),
            Rules::ANSWER_NO  => __('No', 'grcmanager'),
        ];
    }

    /**
     * Issue #1159 : libellés des types de réponse.
     *
     * @return array<string, string>
     */
    public static function answerTypeLabels(): array
    {
        return [
            Rules::TYPE_YES_NO     => __('Yes / No', 'grcmanager'),
            Rules::TYPE_COMPLIANCE => __('Compliance scale', 'grcmanager'),
        ];
    }

    /**
     * Issue #1159 : réponses de l'échelle de conformité (mêmes codes que Oui / Partiel / Non / Sans
     * objet, voir WeightedQuestionnaire).
     *
     * @return array<string, string>
     */
    public static function complianceLabels(): array
    {
        return [
            Rules::ANSWER_YES     => __('Compliant', 'grcmanager'),
            Rules::ANSWER_PARTIAL => __('Partially compliant', 'grcmanager'),
            Rules::ANSWER_NO      => __('Non-compliant', 'grcmanager'),
            Rules::ANSWER_NA      => __('Not applicable', 'grcmanager'),
        ];
    }

    /**
     * Réponses proposées pour une question selon son type.
     *
     * @return array<string, string>
     */
    public static function choicesFor(mixed $type): array
    {
        return Rules::normalizeAnswerType($type) === Rules::TYPE_COMPLIANCE
            ? self::complianceLabels()
            : SupplierAssessment::getAnswerLabels();
    }

    public static function showForTemplate(SupplierQuestionnaireTemplate $template): void
    {
        global $CFG_GLPI;

        $questions = self::getQuestions($template->getID());
        $sections = [];
        foreach ($questions as $question) {
            $sections[(string) $question['section']] = true;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_questionnaire_questions.html.twig', [
            'template_id' => $template->getID(),
            'questions'   => $questions,
            'sections'    => array_keys($sections),
            'expected'    => self::expectedLabels(),
            'types'       => self::answerTypeLabels(),
            'scale_type'  => Rules::TYPE_COMPLIANCE,
            'new_rows'    => self::NEW_ROWS,
            'max_weight'  => Rules::MAX_WEIGHT,
            'can_edit'    => $template->can($template->getID(), UPDATE),
            'version'     => (int) $template->fields['version'],
            'total'       => array_sum(array_map(static fn (array $q): int => (int) $q['weight'], $questions)),
            'action'      => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierquestionnairetemplate.form.php',
        ]);
    }
}
