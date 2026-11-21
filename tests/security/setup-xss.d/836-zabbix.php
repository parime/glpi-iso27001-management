<?php

/**
 * Issue #836 : supervision Zabbix. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Issue #836 : hôte et problème Zabbix piégés (page de la supervision Zabbix, preuve A.8.16).
$DB->update('glpi_plugin_grcmanager_zabbixhosts', ['name' => mb_substr($canary, 0, 255)], ['hostid' => '9100001']);
$DB->update(
    'glpi_plugin_grcmanager_zabbixproblems',
    ['name' => mb_substr($canary, 0, 255)],
    ['eventid' => '9100001']
);
$out['control_a816'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.16'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
