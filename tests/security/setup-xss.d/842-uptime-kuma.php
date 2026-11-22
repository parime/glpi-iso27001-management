<?php

/**
 * Issue #842 : services exposés lus dans Uptime Kuma. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #842 : sonde Uptime Kuma piégée (page des services exposés, preuve A.8.24).
$DB->update(
    'glpi_plugin_grcmanager_uptimekumamonitors',
    ['name' => mb_substr($canary, 0, 255), 'type' => mb_substr($canary, 0, 32)],
    ['monitor_key' => 'id:9200001']
);
$out['control_a824'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.24'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
