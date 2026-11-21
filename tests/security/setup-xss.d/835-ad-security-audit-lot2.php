<?php

/**
 * Issue #835, lot 2 : configuration Entra ID. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #835, lot 2 : nom du locataire, contrôle et constat piégés (détail d'un import Maester).
$DB->update('glpi_plugin_grcmanager_adaudits', ['netbios' => mb_substr($canary, 0, 64)], [
    'domain' => 'locataire-a.test',
]);
$DB->update('glpi_plugin_grcmanager_adauditrules', [
    'rule_id' => mb_substr($canary, 0, 128), 'model' => mb_substr($canary, 0, 128), 'rationale' => $canary,
], ['rule_id' => 'MT.Securite-A']);
$out['cloudaudit_A'] = (int) ($ids['cloudaudit_A'] ?? 0);
