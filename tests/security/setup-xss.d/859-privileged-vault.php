<?php

/**
 * Issue #859 : comptes à privilèges et coffre-fort. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Élément et membre du coffre piégés (page des comptes à privilèges, preuve A.8.2).
$DB->update('glpi_plugin_grcmanager_vaultentries', [
    'name' => mb_substr($canary, 0, 255), 'username' => mb_substr($canary, 0, 255),
    'uri' => mb_substr($canary, 0, 255), 'collection' => mb_substr($canary, 0, 255),
], ['name' => 'Compte coffre securite A']);
$DB->update('glpi_plugin_grcmanager_vaultmembers', [
    'name' => mb_substr($canary, 0, 255),
], ['email' => 'membre.coffre.securite.a@exemple.test']);
// Mesure A.8.2 (preuve des comptes à privilèges).
$out['control_a82'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.2'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
