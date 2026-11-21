<?php

/**
 * Issue #865, lot 2 : lien sécurisé du fournisseur. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Fonction activée pour les tests (désactivée par défaut). Liens de test recréés à chaque passage :
// actif sur A (un envoi), expiré et révoqué sur A, actif sur B, et un lien réservé au test de
// limitation de débit. Les compteurs de débit sont remis à zéro (même adresse IP pour tous les
// profils testés).
\Config::setConfigurationValues('plugin:grcmanager', ['supplier_portal_enabled' => 1]);
$DB->delete('glpi_plugin_grcmanager_supplierportalthrottles', ['id' => ['>', 0]]);
foreach (['A', 'B'] as $suffix) {
    $evaluationId = (int) $ids['evaluation_' . $suffix];
    $DB->delete('glpi_plugin_grcmanager_supplierportallinks', ['plugin_grcmanager_supplierevaluations_id' => $evaluationId]);
    $DB->delete('glpi_plugin_grcmanager_supplierportaldrafts', ['plugin_grcmanager_supplierevaluations_id' => $evaluationId]);
}
$evaluationA = new \GlpiPlugin\Grcmanager\SupplierEvaluation();
$evaluationA->getFromDB((int) $ids['evaluation_A']);
$evaluationB = new \GlpiPlugin\Grcmanager\SupplierEvaluation();
$evaluationB->getFromDB((int) $ids['evaluation_B']);
$ids['portal_token_A'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($evaluationA, 7, 1);
$ids['portal_token_B'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($evaluationB, 7, 1);
$ids['portal_token_rate'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($evaluationA, 7, 1);
$ids['portal_token_expired'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($evaluationA, 7, 1);
$DB->update('glpi_plugin_grcmanager_supplierportallinks', ['date_expiration' => date('Y-m-d H:i:s', strtotime('-1 hour'))], [
    'token_hash' => hash('sha256', $ids['portal_token_expired']),
]);
$ids['portal_token_revoked'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($evaluationA, 7, 1);
$revoked = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::findByToken($ids['portal_token_revoked']);
\GlpiPlugin\Grcmanager\SupplierEvaluationLink::revoke($evaluationA, (int) $revoked['id']);
$ids['portal_link_A'] = (int) \GlpiPlugin\Grcmanager\SupplierEvaluationLink::findByToken($ids['portal_token_A'])['id'];
