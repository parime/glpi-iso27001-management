<?php

/**
 * Issue #871 : risque piege a reduire dans A, avec un plan de traitement dont l'action est piegee
 * (onglet « Trajectoire projetee » et carte du registre de la cartographie).
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$out['trajectory_risk'] = $insert('risks', [
    'title' => $canary, 'description' => $canary, 'entities_id' => (int) $ids['a'],
    'probability' => 'probable', 'impact' => 'high', 'risk_level' => 'high', 'computed_score' => 9,
    'residual_probability' => 'rare', 'residual_impact' => 'low', 'residual_level' => 'low',
    'treatment' => 'mitigate', 'status' => 'in_treatment', 'date_creation' => date('Y-m-d H:i:s'),
]);
$insert('risktreatmentactions', [
    'plugin_grcmanager_risks_id' => $out['trajectory_risk'], 'entities_id' => (int) $ids['a'],
    'description' => $canary, 'due_date' => date('Y-m-d', strtotime('+20 days')), 'status' => 'planned',
]);
