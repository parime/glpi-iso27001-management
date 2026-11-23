<?php

/**
 * Issue #1083 : plan de continuité piégé (contenu, test, version soumise et réponse d'approbation),
 * affiché dans la liste, la fiche, ses onglets et la page d'accueil de la rubrique. Fragment inclus
 * par tests/security/setup.php xss.
 */

$out['continuityplan'] = $insert('continuityplans', [
    'entities_id' => (int) $ids['a'], 'name' => $canary, 'plan_type' => 'pca', 'version' => '1.0',
    'status' => 'draft', 'scope' => $canary, 'activation_criteria' => $canary, 'activation_authority' => $canary,
    'objectives' => $canary, 'roles' => $canary, 'procedures' => $canary, 'fallback' => $canary,
    'dependencies' => $canary, 'communication' => $canary, 'comment' => $canary, 'users_id' => 2,
    'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s'),
]);
$insert('continuityplans_items', [
    'plugin_grcmanager_continuityplans_id' => $out['continuityplan'],
    'itemtype' => 'GlpiPlugin\Grcmanager\BusinessProcess', 'items_id' => (int) $out['process'],
]);
$insert('continuityplantests', [
    'plugin_grcmanager_continuityplans_id' => $out['continuityplan'], 'test_date' => date('Y-m-d'),
    'test_type' => $canary, 'result' => 'failed', 'summary' => $canary, 'gaps' => $canary, 'users_id' => 2,
]);
$xssVersion = $insert('continuityplanversions', [
    'plugin_grcmanager_continuityplans_id' => $out['continuityplan'], 'version' => '0.9', 'status' => 'rejected',
    'snapshot' => (string) json_encode([
        'name' => $canary, 'type_label' => $canary, 'version' => '0.9', 'scope' => $canary,
    ]),
    'fingerprint' => str_repeat('a', 64), 'users_id' => 2, 'comment' => $canary,
    'date_creation' => date('Y-m-d H:i:s'), 'date_answered' => date('Y-m-d H:i:s'),
]);
$insert('continuityplanversions_users', [
    'plugin_grcmanager_continuityplanversions_id' => $xssVersion, 'users_id' => 2, 'status' => 'rejected',
    'comment' => $canary, 'date_answered' => date('Y-m-d H:i:s'),
]);
