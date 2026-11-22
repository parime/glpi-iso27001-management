<?php

/**
 * Issue #618 : délégation des décisions pendant une absence. Fragment inclus par
 * tests/security/setup.php (jeu de donnees).
 *
 * Seule la revue des accès est délégable (configuration). iso-sans-droit, sans aucun droit sur le
 * plugin, est le suppléant déclaré dans GLPI de deux responsables de revue fictifs :
 * - iso-titulaire-absent, absent d'hier à dans 30 jours (délégation en cours) ;
 * - iso-titulaire-revenu, absent jusqu'à hier (délégation terminée).
 * Campagne ouverte de A « Revue deleguee securite » : un compte confié à chacun des deux, le compte
 * du suppléant lui-même confié au titulaire absent, et un compte confié à un autre responsable
 * (iso-autre-responsable), dont personne n'est le suppléant.
 */

\GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig::save([
    \GlpiPlugin\Grcmanager\Services\Delegation\DelegationRules::TYPE_ACCESS_REVIEW,
]);

$noRightProfile = new Profile();
$noRightProfile->getFromDBByCrit(['name' => 'Securite ISO - sans droit']);
$delegationUser = static function (string $login) use ($userId, $a, $noRightProfile): int {
    return $userId($login, $a, (int) $noRightProfile->getID());
};
$substitute = new User();
$substitute->getFromDBbyName('iso-sans-droit');
$substituteId = (int) $substitute->getID();

$absentHolder = $delegationUser('iso-titulaire-absent');
$backHolder = $delegationUser('iso-titulaire-revenu');
$otherReviewer = $delegationUser('iso-autre-responsable');
// Dates relatives à aujourd'hui, remises à chaque passage (aucune ligne ajoutée).
$DB->update('glpi_users', [
    'substitution_start_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
    'substitution_end_date'   => date('Y-m-d H:i:s', strtotime('+30 days')),
], ['id' => $absentHolder]);
$DB->update('glpi_users', [
    'substitution_start_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
    'substitution_end_date'   => date('Y-m-d H:i:s', strtotime('-1 day')),
], ['id' => $backHolder]);
foreach ([$absentHolder, $backHolder] as $holder) {
    $insertOnce('glpi_validatorsubstitutes', ['users_id' => $holder, 'users_id_substitute' => $substituteId]);
}

$delegationCampaign = $add(new \GlpiPlugin\Grcmanager\AccessReview(), [
    'name'                          => 'Revue deleguee securite',
    'entities_id'                   => $a,
    'plugin_grcmanager_controls_id' => (int) $ids['control'],
    'date_start'                    => date('Y-m-d'),
    'due_date'                      => date('Y-m-d', strtotime('+30 days')),
]);
$DB->update('glpi_plugin_grcmanager_accessreviews', ['status' => 'open'], ['id' => $delegationCampaign]);
$delegationLines = [
    'delegation_line_ok'      => [$delegationUser('iso-compte-delegue'), $absentHolder],
    'delegation_line_expired' => [$delegationUser('iso-compte-revenu'), $backHolder],
    'delegation_line_own'     => [$substituteId, $absentHolder],
    'delegation_line_other'   => [$delegationUser('iso-compte-autre'), $otherReviewer],
];
foreach ($delegationLines as $key => [$account, $reviewer]) {
    $ids[$key] = $insertOnce('glpi_plugin_grcmanager_accessreviews_users', [
        'plugin_grcmanager_accessreviews_id' => $delegationCampaign,
        'users_id'                           => $account,
        'reviewers_id'                       => $reviewer,
    ]);
    // Toujours à décider au début de la matrice.
    $DB->update('glpi_plugin_grcmanager_accessreviews_users', [
        'decision' => 'pending', 'decided_by' => 0, 'is_delegated' => 0, 'date_decision' => null,
    ], ['id' => $ids[$key]]);
}
$ids['delegation_campaign'] = $delegationCampaign;
$ids['delegation_holder'] = $absentHolder;
$ids['delegation_holder_back'] = $backHolder;
