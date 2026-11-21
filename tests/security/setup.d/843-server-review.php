<?php

/**
 * Issue #843, lot 2 : compte du contrôleur de A figé dans la campagne de revue de A, celui de B dans
 * la campagne de B. Fragment inclus par tests/security/setup.php (après 843-server-management.php).
 */

foreach (['A', 'B'] as $suffix) {
    $line = [
        'plugin_grcmanager_accessreviews_id'  => (int) $ids['campaign_' . $suffix],
        'plugin_grcmanager_bmccontrollers_id' => (int) $ids['bmc_' . $suffix],
    ];
    $row = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_accessreviews_bmcaccounts', 'WHERE' => $line])
        ->current();
    if ($row === null) {
        $DB->insert('glpi_plugin_grcmanager_accessreviews_bmcaccounts', $line + [
            'controller_name' => 'Controleur BMC securite ' . $suffix, 'computers_id' => (int) $ids['computer_' . $suffix],
            'username' => 'Revue BMC securite ' . $suffix, 'role' => 'Administrator', 'is_enabled' => 1,
            'suggestion' => 'examine', 'reasons' => json_encode(['bmc_admin']), 'decision' => 'pending',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $ids['bmc_review_' . $suffix] = (int) $DB->insertId();
    } else {
        $ids['bmc_review_' . $suffix] = (int) $row['id'];
    }
}
