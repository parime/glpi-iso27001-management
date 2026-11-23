<?php

/**
 * Issue #871 : trajectoire projetée du risque. Fragment inclus par tests/security/setup.php (jeu de
 * donnees).
 *
 * Un risque a reduire dans A et un dans B, avec un risque residuel vise et un plan de traitement :
 * une action terminee et une en retard dans A (plan « en retard »), une action a venir dans B. Le
 * risque de B n'est jamais montre aux profils de A (onglet et carte du registre).
 */

foreach (['trajectory_risk_A' => ['A', $a], 'trajectory_risk_B' => ['B', $b]] as $key => [$suffix, $entity]) {
    $ids[$key] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
        'title'                => 'Trajectoire securite ' . $suffix,
        'entities_id'          => $entity,
        'probability'          => 'probable',
        'impact'               => 'high',
        'residual_probability' => 'rare',
        'residual_impact'      => 'low',
        'treatment'            => 'mitigate',
        'status'               => 'in_treatment',
    ]);
}
$trajectoryActions = [
    'trajectory_action_done' => [
        'trajectory_risk_A', 'Action trajectoire securite terminee', date('Y-m-d', strtotime('-30 days')), 'done',
    ],
    'trajectory_action_late' => [
        'trajectory_risk_A', 'Action trajectoire securite en retard', date('Y-m-d', strtotime('-10 days')), 'planned',
    ],
    'trajectory_action_B' => [
        'trajectory_risk_B', 'Action trajectoire securite B', date('Y-m-d', strtotime('+30 days')), 'planned',
    ],
];
foreach ($trajectoryActions as $key => [$riskKey, $description, $due, $status]) {
    $ids[$key] = $add(new \GlpiPlugin\Grcmanager\RiskTreatmentAction(), [
        'plugin_grcmanager_risks_id' => $ids[$riskKey],
        'description'                => $description,
        'users_id'                   => 2,
        'due_date'                   => $due,
        'status'                     => $status,
    ]);
}
