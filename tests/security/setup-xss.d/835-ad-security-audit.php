<?php

/**
 * Issue #835 : audit de sécurité de l'AD. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Issue #835 : domaine, règle et constat piégés (page de l'audit de l'AD, preuve A.8.2).
$DB->update('glpi_plugin_grcmanager_adaudits', ['domain' => mb_substr($canary, 0, 255)], [
    'domain' => 'adsecurite-a.test',
]);
$DB->update('glpi_plugin_grcmanager_adauditrules', [
    'rule_id' => mb_substr($canary, 0, 128), 'model' => mb_substr($canary, 0, 128), 'rationale' => $canary,
], ['rule_id' => 'P-Securite-A']);
// Issue #835 : mesure A.8.2 (preuve de l'audit de sécurité de l'AD).
$out['control_a82'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.2'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
