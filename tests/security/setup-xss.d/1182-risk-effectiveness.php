<?php

/**
 * Issue #1182 : risque piege dans A avec un risque cible, un plan termine, une revue dont le constat est
 * piege et une acceptation signee (fiche du risque, onglet « Revues », configuration des profils).
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$out['effectiveness_risk'] = $insert('risks', [
    'title' => $canary, 'description' => $canary, 'justification' => $canary, 'entities_id' => (int) $ids['a'],
    'probability' => 'probable', 'impact' => 'high', 'risk_level' => 'high', 'computed_score' => 9,
    'residual_probability' => 'probable', 'residual_impact' => 'high', 'residual_level' => 'high',
    'target_level' => 'low', 'treatment' => 'mitigate', 'status' => 'in_treatment',
    'date_creation' => date('Y-m-d H:i:s'),
]);
$insert('risktreatmentactions', [
    'plugin_grcmanager_risks_id' => $out['effectiveness_risk'], 'entities_id' => (int) $ids['a'],
    'description' => $canary, 'status' => 'done', 'completion_date' => date('Y-m-d'),
]);
$insert('riskreviews', [
    'plugin_grcmanager_risks_id' => $out['effectiveness_risk'], 'entities_id' => (int) $ids['a'],
    'users_id' => 2, 'date_review' => date('Y-m-d H:i:s'), 'probability' => 'probable', 'impact' => 'high',
    'risk_level' => 'high', 'computed_score' => 9, 'residual_level' => 'high', 'residual_score' => 9,
    'target_level' => 'low', 'treatment' => 'mitigate', 'status' => 'in_treatment', 'actions_total' => 1,
    'actions_done' => 1, 'effectiveness' => 'not_reached', 'comment' => $canary,
    'date_creation' => date('Y-m-d H:i:s'),
]);
// Acceptation signee (date d'expiration proche) d'un risque piege.
$out['accepted_risk'] = $insert('risks', [
    'title' => $canary, 'description' => $canary, 'justification' => $canary, 'entities_id' => (int) $ids['a'],
    'treatment' => 'accept', 'status' => 'accepted', 'users_id_acceptance' => 2,
    'date_acceptance' => date('Y-m-d H:i:s'), 'date_acceptance_expiry' => date('Y-m-d', strtotime('+10 days')),
    'date_creation' => date('Y-m-d H:i:s'),
]);
