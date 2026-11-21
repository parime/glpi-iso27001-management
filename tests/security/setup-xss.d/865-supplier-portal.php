<?php

/**
 * Issue #865, lot 2 : lien sécurisé du fournisseur. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Questions piégées (fragment #864) : page publique d'un lien actif, et réponse reçue piégée en
// attente de relecture (onglet Lien sécurisé, page de relecture).
$xssEvaluation = new \GlpiPlugin\Grcmanager\SupplierEvaluation();
$xssEvaluation->getFromDB((int) $ids['evaluation_A']);
$out['portal_token'] = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::create($xssEvaluation, 7, 5);
$xssLink = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::findByToken($out['portal_token']);
$out['portal_draft'] = $insert('supplierportaldrafts', [
    'plugin_grcmanager_supplierportallinks_id'  => (int) $xssLink['id'],
    'plugin_grcmanager_supplierevaluations_id' => (int) $ids['evaluation_A'],
    'fingerprint'                              => (string) $xssLink['fingerprint'],
    'answers'                                  => json_encode([(int) $ids['evaluation_answer_A'] => [
        'answer' => 'yes', 'comment' => $canary,
    ]]),
    'files'                                    => '[]',
    'status'                                   => 'pending',
    'date_creation'                            => date('Y-m-d H:i:s'),
]);
