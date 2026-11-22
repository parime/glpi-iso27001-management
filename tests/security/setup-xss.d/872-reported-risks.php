<?php

/**
 * Issue #872 : signalements pieges du super-admin dans A : un nouveau (file de tri, avec la liste des
 * risques auxquels le rattacher, et « Mes signalements ») et un rattache au risque de A avec une
 * reponse piegee (onglet du risque, decision montree a l'auteur).
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$out['reportedrisk_xss'] = $insert('reportedrisks', [
    'entities_id' => (int) $ids['a'], 'name' => $canary, 'description' => $canary, 'severity' => 'high',
    'users_id' => 2, 'status' => 'new', 'date_creation' => date('Y-m-d H:i:s'),
]);
$out['reportedrisk_xss_merged'] = $insert('reportedrisks', [
    'entities_id' => (int) $ids['a'], 'name' => $canary, 'description' => $canary, 'severity' => 'low',
    'users_id' => 2, 'status' => 'merged', 'plugin_grcmanager_risks_id' => (int) $ids['risk'],
    'decision_comment' => $canary, 'users_id_decision' => 2, 'date_decision' => date('Y-m-d H:i:s'),
    'date_creation' => date('Y-m-d H:i:s'),
]);
