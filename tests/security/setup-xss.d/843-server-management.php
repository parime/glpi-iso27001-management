<?php

/**
 * Issue #843 : contrôleurs de gestion des serveurs. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #843 : contrôleur, firmware et compte local piégés (page, onglet de l'ordinateur, preuve).
$DB->update('glpi_plugin_grcmanager_bmccontrollers', [
    'name' => mb_substr($canary, 0, 255), 'manager_model' => mb_substr($canary, 0, 255),
    'firmware_version' => mb_substr($canary, 0, 255), 'bios_version' => mb_substr($canary, 0, 255),
    'serial' => mb_substr($canary, 0, 255), 'vendor' => mb_substr($canary, 0, 64),
], ['id' => (int) $ids['bmc_A']]);
$DB->update('glpi_plugin_grcmanager_bmcaccounts', [
    'username' => mb_substr($canary, 0, 255), 'role' => mb_substr($canary, 0, 64),
], ['plugin_grcmanager_bmccontrollers_id' => (int) $ids['bmc_A']]);
// Issue #843 : mesure A.8.2 (preuve des comptes des contrôleurs).
$out['control_a82'] ??= (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.2'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
