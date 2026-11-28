<?php

/**
 * Issue #1182 : efficacite du traitement, revues datees, acceptation signee. Fragment inclus par
 * tests/security/setup.php (jeu de donnees).
 *
 * - un risque a reduire dans A et un dans B, chacun avec un risque cible, un plan termine et une revue
 *   (onglet « Revues ») : la revue de B n'est jamais montree aux profils de A ;
 * - un risque accepte dans A dont le gestionnaire limite a A est proprietaire : il ne peut pas signer
 *   son acceptation (separation des taches, controlee cote serveur).
 */

foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $riskKey = 'effectiveness_risk_' . $suffix;
    $ids[$riskKey] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
        'title'                => 'Risque efficacite securite ' . $suffix,
        'entities_id'          => $entity,
        'probability'          => 'probable',
        'impact'               => 'high',
        'residual_probability' => 'probable',
        'residual_impact'      => 'high',
        'target_level'         => 'low',
        'treatment'            => 'mitigate',
        'status'               => 'in_treatment',
    ]);
    $ids['effectiveness_action_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\RiskTreatmentAction(), [
        'plugin_grcmanager_risks_id' => $ids[$riskKey],
        'description'                => 'Action efficacite securite ' . $suffix,
        'users_id'                   => 2,
        'status'                     => 'done',
    ]);
    $ids['effectiveness_review_' . $suffix] = $insertOnce('glpi_plugin_grcmanager_riskreviews', [
        'plugin_grcmanager_risks_id' => (int) $ids[$riskKey],
        'entities_id'                => (int) $entity,
        'users_id'                   => 2,
        'date_review'                => '2026-10-01 10:00:00',
        'probability'                => 'probable',
        'impact'                     => 'high',
        'risk_level'                 => 'high',
        'computed_score'             => 9,
        'residual_probability'       => 'probable',
        'residual_impact'            => 'high',
        'residual_level'             => 'high',
        'residual_score'             => 9,
        'target_level'               => 'low',
        'treatment'                  => 'mitigate',
        'status'                     => 'in_treatment',
        'actions_total'              => 1,
        'actions_done'               => 1,
        'effectiveness'              => 'not_reached',
        'comment'                    => 'Revue securite ' . $suffix,
        'date_creation'              => '2026-10-01 10:00:00',
    ]);
}

$ids['acceptance_risk_owned'] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
    'title'       => 'Risque accepte securite du gestionnaire',
    'entities_id' => $a,
    'users_id'    => (int) $ids['manager'],
    'treatment'   => 'accept',
    'status'      => 'accepted',
]);
