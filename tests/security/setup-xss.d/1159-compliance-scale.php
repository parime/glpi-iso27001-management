<?php

/**
 * Issue #1159 : échelle de conformité. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Les questions piégées du modèle de A (fragment #864) et la réponse piégée de son évaluation passent
// en échelle de conformité : l'onglet Questions du modèle, l'onglet Questionnaire de l'évaluation,
// la page publique du lien (fragment #865, créé ensuite avec l'empreinte à jour) et la relecture
// affichent les libellés de l'échelle à côté des textes piégés.
$DB->update('glpi_plugin_grcmanager_supplierquestionnairequestions', [
    'answer_type' => 'compliance', 'expected_answer' => 'yes',
], ['plugin_grcmanager_supplierquestionnairetemplates_id' => (int) $ids['questionnaire_A']]);
$update('supplierevaluationanswers', [
    'answer_type' => 'compliance', 'expected_answer' => 'yes',
], (int) $ids['evaluation_answer_A']);
