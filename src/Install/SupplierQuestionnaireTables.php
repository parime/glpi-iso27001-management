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

namespace GlpiPlugin\Grcmanager\Install;

use GlpiPlugin\Grcmanager\Services\Risk\SupplierQuestionnaire;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire;
use GlpiPlugin\Grcmanager\SupplierAssessment;
use Migration;

/**
 * Issue #864 : questionnaires fournisseurs configurables.
 *
 * - TEMPLATES : modèles de questionnaire (entité, domaine, version incrémentée à chaque changement
 *   des questions) ;
 * - QUESTIONS : questions d'un modèle (section, texte, aide, réponse attendue, pondération, pièce
 *   justificative exigée, code stable pour l'export et le réimport) ;
 * - EVALUATIONS : évaluations d'un risque fournisseur, plusieurs par fournisseur, chacune avec son
 *   modèle, son score et son historique ;
 * - ANSWERS : réponses d'une évaluation, avec une copie de la question au moment de l'évaluation
 *   (modifier un modèle ne change pas une évaluation déjà faite) et sa pièce justificative.
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation. migrateLegacy() reprend
 * les douze questions fixes de l'issue #127 sous un modèle « Questionnaire historique » et les
 * questionnaires déjà remplis en évaluations terminées, sans rien effacer (colonnes d'origine
 * conservées sur les risques fournisseurs).
 */
final class SupplierQuestionnaireTables implements FeatureTables
{
    public const TEMPLATES = 'glpi_plugin_grcmanager_supplierquestionnairetemplates';

    public const QUESTIONS = 'glpi_plugin_grcmanager_supplierquestionnairequestions';

    public const EVALUATIONS = 'glpi_plugin_grcmanager_supplierevaluations';

    public const ANSWERS = 'glpi_plugin_grcmanager_supplierevaluationanswers';

    public const TEMPLATE_FK = 'plugin_grcmanager_supplierquestionnairetemplates_id';

    public const QUESTION_FK = 'plugin_grcmanager_supplierquestionnairequestions_id';

    public const EVALUATION_FK = 'plugin_grcmanager_supplierevaluations_id';

    public const RISK_FK = 'plugin_grcmanager_supplierrisks_id';

    private const RISKS = 'glpi_plugin_grcmanager_supplierrisks';

    /**
     * Classes propres à ces tables (historique, préférences d'affichage : nettoyés à la désinstallation).
     *
     * @return list<class-string>
     */
    public static function pluginClasses(): array
    {
        return [
            \GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate::class,
            \GlpiPlugin\Grcmanager\SupplierEvaluation::class,
        ];
    }

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        $templateFk = self::TEMPLATE_FK;
        $questionFk = self::QUESTION_FK;
        $evaluationFk = self::EVALUATION_FK;
        $riskFk = self::RISK_FK;
        if (!$DB->tableExists(self::TEMPLATES)) {
            $DB->doQuery("CREATE TABLE `" . self::TEMPLATES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `domain` varchar(32) NOT NULL DEFAULT 'security'
                    COMMENT 'security, privacy, ai, continuity, other',
                `version` int NOT NULL DEFAULT 1 COMMENT 'Incrementee a chaque changement des questions',
                `is_active` tinyint NOT NULL DEFAULT 1,
                `is_legacy` tinyint NOT NULL DEFAULT 0 COMMENT '1 = les douze questions fixes de l issue 127',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `domain` (`domain`),
                KEY `is_active` (`is_active`),
                KEY `is_legacy` (`is_legacy`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::QUESTIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::QUESTIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `{$templateFk}` int {$keySign} NOT NULL DEFAULT 0,
                `code` varchar(64) NOT NULL DEFAULT '' COMMENT 'Identifiant stable dans le modele (export, reimport)',
                `section` varchar(255) NOT NULL DEFAULT '',
                `question` text,
                `guidance` text COMMENT 'Aide pour repondre, reponse attendue detaillee',
                `expected_answer` varchar(8) NOT NULL DEFAULT 'yes' COMMENT 'yes ou no : la reponse conforme',
                `weight` int NOT NULL DEFAULT 1 COMMENT '0 a 10, 0 = question informative non notee',
                `requires_evidence` tinyint NOT NULL DEFAULT 0,
                `position` int NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`{$templateFk}`, `code`),
                KEY `position` (`position`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::EVALUATIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::EVALUATIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite du risque fournisseur',
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `{$riskFk}` int {$keySign} NOT NULL DEFAULT 0,
                `suppliers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Fournisseur du risque, copie',
                `{$templateFk}` int {$keySign} NOT NULL DEFAULT 0 COMMENT '0 = modele supprime',
                `template_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom du modele a la creation',
                `template_version` int NOT NULL DEFAULT 1,
                `domain` varchar(32) NOT NULL DEFAULT 'security',
                `name` varchar(255) NOT NULL DEFAULT '',
                `status` varchar(16) NOT NULL DEFAULT 'in_progress' COMMENT 'in_progress, completed',
                `source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual, legacy (issue 865 : link, file)',
                `score` int DEFAULT NULL COMMENT 'Score pondere en pourcentage, NULL = aucune reponse notee',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Evaluateur',
                `evaluation_date` date DEFAULT NULL,
                `date_completed` timestamp NULL DEFAULT NULL,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `{$riskFk}` (`{$riskFk}`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `{$templateFk}` (`{$templateFk}`),
                KEY `domain` (`domain`),
                KEY `status` (`status`),
                KEY `source` (`source`),
                KEY `users_id` (`users_id`),
                KEY `evaluation_date` (`evaluation_date`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::ANSWERS)) {
            $DB->doQuery("CREATE TABLE `" . self::ANSWERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `{$evaluationFk}` int {$keySign} NOT NULL DEFAULT 0,
                `{$questionFk}` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Question d origine, 0 si supprimee',
                `code` varchar(64) NOT NULL DEFAULT '',
                `section` varchar(255) NOT NULL DEFAULT '',
                `question` text COMMENT 'Copie de la question a la creation de l evaluation',
                `guidance` text,
                `expected_answer` varchar(8) NOT NULL DEFAULT 'yes',
                `weight` int NOT NULL DEFAULT 1,
                `requires_evidence` tinyint NOT NULL DEFAULT 0,
                `position` int NOT NULL DEFAULT 0,
                `answer` varchar(16) NOT NULL DEFAULT '' COMMENT 'yes, partial, no, na ; vide = sans reponse',
                `answer_comment` text,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Piece justificative (Document GLPI)',
                `answer_source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual, legacy (issue 865 : link, file)',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la derniere reponse',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`{$evaluationFk}`, `code`),
                KEY `{$questionFk}` (`{$questionFk}`),
                KEY `documents_id` (`documents_id`),
                KEY `users_id` (`users_id`),
                KEY `position` (`position`)
            ) {$suffix}");
        }
    }

    /**
     * Mise à jour : reprise des questionnaires fixes de l'issue #127 (migrateLegacy()).
     */
    public static function migrate(Migration $migration): void
    {
        self::migrateLegacy();
    }

    /**
     * Reprise de l'issue #127 (rejouable) : modèle « Questionnaire historique » avec les douze
     * questions fixes, puis une évaluation terminée par risque fournisseur dont le questionnaire
     * avait été rempli, avec ses réponses, son score, sa date et son auteur d'origine. Un risque
     * déjà repris ne l'est pas une seconde fois ; les colonnes d'origine ne sont jamais effacées.
     *
     * @return int évaluations reprises lors de cet appel
     */
    public static function migrateLegacy(): int
    {
        global $DB;

        if (
            !$DB->tableExists(self::TEMPLATES) || !$DB->tableExists(self::RISKS)
            || !$DB->fieldExists(self::RISKS, 'questionnaire_answers')
        ) {
            return 0;
        }
        $templateId = self::legacyTemplateId();
        $template = $DB->request(['FROM' => self::TEMPLATES, 'WHERE' => ['id' => $templateId]])->current();
        $questions = iterator_to_array($DB->request([
            'FROM'  => self::QUESTIONS,
            'WHERE' => [self::TEMPLATE_FK => $templateId],
            'ORDER' => ['position', 'id'],
        ]), false);

        $done = [];
        foreach (
            $DB->request([
                'SELECT' => [self::RISK_FK],
                'FROM'   => self::EVALUATIONS,
                'WHERE'  => ['source' => WeightedQuestionnaire::SOURCE_LEGACY],
            ]) as $row
        ) {
            $done[(int) $row[self::RISK_FK]] = true;
        }
        $hasEntity = $DB->fieldExists(self::RISKS, 'entities_id');
        $migrated = 0;
        foreach (
            $DB->request([
                'FROM'  => self::RISKS,
                'WHERE' => ['OR' => [
                    ['NOT' => ['questionnaire_date' => null]],
                    ['NOT' => ['questionnaire_score' => null]],
                ]],
            ]) as $risk
        ) {
            $riskId = (int) $risk['id'];
            $answers = json_decode((string) ($risk['questionnaire_answers'] ?? ''), true);
            $answers = is_array($answers) ? SupplierQuestionnaire::sanitize($answers) : [];
            if (isset($done[$riskId]) || ($answers === [] && $risk['questionnaire_score'] === null)) {
                continue;
            }
            $date = $risk['questionnaire_date'] ?? null;
            $now = date('Y-m-d H:i:s');
            $DB->insert(self::EVALUATIONS, [
                'entities_id'      => $hasEntity ? (int) $risk['entities_id'] : 0,
                'is_recursive'     => 0,
                self::RISK_FK      => $riskId,
                'suppliers_id'     => (int) $risk['suppliers_id'],
                self::TEMPLATE_FK  => $templateId,
                'template_name'    => (string) $template['name'],
                'template_version' => (int) $template['version'],
                'domain'           => WeightedQuestionnaire::DOMAIN_SECURITY,
                'name'             => (string) $template['name'],
                'status'           => WeightedQuestionnaire::STATUS_COMPLETED,
                'source'           => WeightedQuestionnaire::SOURCE_LEGACY,
                'score'            => $risk['questionnaire_score'] === null ? null : (int) $risk['questionnaire_score'],
                'users_id'         => (int) ($risk['questionnaire_users_id'] ?? 0),
                'evaluation_date'  => $date !== null ? substr((string) $date, 0, 10) : null,
                'date_completed'   => $date,
                'date_creation'    => $date ?? $now,
                'date_mod'         => $date ?? $now,
            ]);
            $evaluationId = (int) $DB->insertId();
            foreach ($questions as $question) {
                $DB->insert(self::ANSWERS, [
                    self::EVALUATION_FK => $evaluationId,
                    self::QUESTION_FK   => (int) $question['id'],
                    'code'              => (string) $question['code'],
                    'section'           => (string) $question['section'],
                    'question'          => (string) $question['question'],
                    'guidance'          => (string) ($question['guidance'] ?? ''),
                    'expected_answer'   => (string) $question['expected_answer'],
                    'weight'            => (int) $question['weight'],
                    'requires_evidence' => (int) $question['requires_evidence'],
                    'position'              => (int) $question['position'],
                    'answer'            => $answers[(string) $question['code']] ?? '',
                    'answer_source'     => WeightedQuestionnaire::SOURCE_LEGACY,
                    'users_id'          => (int) ($risk['questionnaire_users_id'] ?? 0),
                    'date_mod'          => $date,
                ]);
            }
            $migrated++;
        }

        return $migrated;
    }

    /**
     * Modèle « Questionnaire historique » (créé une seule fois, dans la langue de l'installation),
     * avec les douze questions de l'issue #127, pondération 1, réponse attendue « oui ».
     */
    private static function legacyTemplateId(): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TEMPLATES,
            'WHERE'  => ['is_legacy' => 1],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ])->current();
        if ($row !== null) {
            return (int) $row['id'];
        }
        $now = date('Y-m-d H:i:s');
        $DB->insert(self::TEMPLATES, [
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'name'          => __('Historical questionnaire', 'grcmanager'),
            'domain'        => WeightedQuestionnaire::DOMAIN_SECURITY,
            'version'       => 1,
            'is_active'     => 1,
            'is_legacy'     => 1,
            'comment'       => __(
                'The twelve questions of the former security questionnaire, kept with the answers already given.',
                'grcmanager'
            ),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        $templateId = (int) $DB->insertId();
        $labels = SupplierAssessment::getQuestionLabels();
        foreach (SupplierQuestionnaire::QUESTIONS as $rank => $code) {
            $DB->insert(self::QUESTIONS, [
                self::TEMPLATE_FK   => $templateId,
                'code'              => $code,
                'section'           => '',
                'question'          => $labels[$code] ?? $code,
                'guidance'          => '',
                'expected_answer'   => WeightedQuestionnaire::ANSWER_YES,
                'weight'            => 1,
                'requires_evidence' => 0,
                'position'              => ($rank + 1) * 10,
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);
        }

        return $templateId;
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::ANSWERS);
        $migration->dropTable(self::EVALUATIONS);
        $migration->dropTable(self::QUESTIONS);
        $migration->dropTable(self::TEMPLATES);
    }
}
