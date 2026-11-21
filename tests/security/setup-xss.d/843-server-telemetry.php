<?php

/**
 * Issue #843, lot 3 : santé détaillée des serveurs. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #843, lot 3 : composant et événement du journal piégés (onglet de l'ordinateur).
$DB->update('glpi_plugin_grcmanager_bmchealthcomponents', [
    'name' => mb_substr($canary, 0, 255), 'reading' => mb_substr($canary, 0, 255),
], ['plugin_grcmanager_bmccontrollers_id' => (int) $ids['bmc_A']]);
$DB->update('glpi_plugin_grcmanager_bmcevents', [
    'message' => $canary, 'code' => mb_substr($canary, 0, 128),
], ['plugin_grcmanager_bmccontrollers_id' => (int) $ids['bmc_A']]);
