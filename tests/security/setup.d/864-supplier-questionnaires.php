<?php

/**
 * Issue #864 : questionnaires fournisseurs. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Issue #864 : un modèle de questionnaire fournisseur et une évaluation dans chaque entité (A sur le
// risque fournisseur de A, B sur celui de B). Un profil limité à A n'ouvre ni le modèle ni
// l'évaluation de B ; le modèle de B n'est jamais proposé sur le risque de A.
foreach (['A' => [$a, 'supplierrisk'], 'B' => [$b, 'supplierrisk_B']] as $suffix => [$entity, $riskKey]) {
    $questionnaire = new \GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate();
    if (!$questionnaire->getFromDBByCrit(['name' => 'Modele questionnaire securite ' . $suffix])) {
        $add($questionnaire, [
            'name' => 'Modele questionnaire securite ' . $suffix, 'domain' => 'security', 'entities_id' => $entity,
            'is_recursive' => 0, 'is_active' => 1,
        ]);
        \GlpiPlugin\Grcmanager\SupplierQuestionnaireQuestion::saveQuestions($questionnaire, [], [[
            'section' => 'Section securite ' . $suffix, 'question' => 'Question securite ' . $suffix,
            'weight' => 2, 'requires_evidence' => 1,
        ]]);
    }
    $ids['questionnaire_' . $suffix] = (int) $questionnaire->getID();
    $evaluationRow = $DB->request([
        'SELECT' => ['id'], 'FROM' => 'glpi_plugin_grcmanager_supplierevaluations',
        'WHERE'  => [
            'plugin_grcmanager_supplierrisks_id' => $ids[$riskKey],
            'name'                               => 'Evaluation securite ' . $suffix,
        ],
    ])->current();
    $ids['evaluation_' . $suffix] = $evaluationRow !== null ? (int) $evaluationRow['id'] : $add(
        new \GlpiPlugin\Grcmanager\SupplierEvaluation(),
        [
            'plugin_grcmanager_supplierrisks_id'                  => $ids[$riskKey],
            'plugin_grcmanager_supplierquestionnairetemplates_id' => $ids['questionnaire_' . $suffix],
            'name'                                                => 'Evaluation securite ' . $suffix,
        ]
    );
    $ids['evaluation_answer_' . $suffix] = (int) ($DB->request([
        'SELECT' => ['id'], 'FROM' => 'glpi_plugin_grcmanager_supplierevaluationanswers',
        'WHERE'  => ['plugin_grcmanager_supplierevaluations_id' => $ids['evaluation_' . $suffix]],
        'LIMIT'  => 1,
    ])->current()['id'] ?? 0);
}
