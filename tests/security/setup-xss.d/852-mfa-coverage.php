<?php

/**
 * Issue #852 : couverture MFA. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Issue #852 : compte et méthode importés piégés (page de la couverture MFA).
$DB->update('glpi_plugin_grcmanager_mfaaccounts', [
    'account' => mb_substr($canary, 0, 255), 'methods' => $canary,
], ['account' => 'Compte MFA securite A']);
// Issue #852 : mesure A.8.5 (preuve de la couverture MFA).
$out['control_a85'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.5'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
