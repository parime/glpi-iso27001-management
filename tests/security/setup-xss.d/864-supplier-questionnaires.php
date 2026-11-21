<?php

/**
 * Issue #864 : questionnaires fournisseurs. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Issue #864 : modèle de questionnaire fournisseur, question, évaluation et réponse piégés (liste
// des modèles, fiche et onglet Questions du modèle, fiche et onglet Questionnaire de l'évaluation,
// onglet Questionnaires du risque fournisseur).
$update('supplierquestionnairetemplates', ['name' => $canary, 'comment' => $canary], (int) $ids['questionnaire_A']);
$DB->update('glpi_plugin_grcmanager_supplierquestionnairequestions', [
    'section' => $canary, 'question' => $canary, 'guidance' => $canary,
], ['plugin_grcmanager_supplierquestionnairetemplates_id' => (int) $ids['questionnaire_A']]);
$update('supplierevaluations', [
    'name' => $canary, 'template_name' => $canary, 'comment' => $canary,
], (int) $ids['evaluation_A']);
$update('supplierevaluationanswers', [
    'code' => $canary, 'section' => $canary, 'question' => $canary, 'guidance' => $canary,
    'answer_comment' => $canary, 'answer' => 'partial',
], (int) $ids['evaluation_answer_A']);
