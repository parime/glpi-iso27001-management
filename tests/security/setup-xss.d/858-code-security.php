<?php

/**
 * Issue #858 : dépôt et alertes piégés sur l'application de A (page « Sécurité du code », onglet de
 * l'application, preuve A.8.28). Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$appliance858 = (int) ($ids['appliance_A'] ?? 0);
$DB->update('glpi_plugin_grcmanager_coderepositories', [
    'repository' => mb_substr($canary, 0, 255),
], ['appliances_id' => $appliance858]);
$DB->update('glpi_plugin_grcmanager_codesecurityalerts', [
    'title' => mb_substr($canary, 0, 255), 'detail' => mb_substr($canary, 0, 255),
    'reference' => mb_substr($canary, 0, 64), 'url' => $canary,
], ['appliances_id' => $appliance858]);
$out['control_a828'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.28'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
