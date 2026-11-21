<?php

/**
 * Issue #852 : couverture MFA. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Issue #852 : compte importé (fournisseur d'identité fictif) sans compte GLPI, par entité.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $mfaCriteria = ['entities_id' => $entity, 'provider' => 'okta'];
    if (countElementsInTable('glpi_plugin_grcmanager_mfaaccounts', $mfaCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_mfaaccounts', $mfaCriteria + [
            'account' => 'Compte MFA securite ' . $suffix, 'email' => '', 'users_id' => 0, 'methods' => 'sms',
            'date_import' => date('Y-m-d H:i:s'),
        ]);
    }
}
