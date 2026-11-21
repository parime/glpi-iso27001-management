<?php

/**
 * Issue #836 : supervision Zabbix. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Issue #836 : hôte Zabbix lu (non rapproché) et son problème de gravité haute, par entité.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $hostId = 'A' === $suffix ? '9100001' : '9100002';
    if (countElementsInTable('glpi_plugin_grcmanager_zabbixhosts', ['hostid' => $hostId]) === 0) {
        $DB->insert('glpi_plugin_grcmanager_zabbixhosts', [
            'entities_id' => $entity, 'is_recursive' => 0, 'hostid' => $hostId, 'host' => 'zbx-securite-' . $suffix,
            'name' => 'Hote Zabbix securite ' . $suffix, 'is_monitored' => 1, 'date_import' => date('Y-m-d H:i:s'),
        ]);
        $DB->insert('glpi_plugin_grcmanager_zabbixproblems', [
            'entities_id' => $entity, 'is_recursive' => 0, 'eventid' => $hostId, 'hostid' => $hostId,
            'name' => 'Probleme Zabbix securite ' . $suffix, 'severity' => 4, 'is_open' => 1,
            'date_problem' => date('Y-m-d H:i:s', time() - 3600), 'date_import' => date('Y-m-d H:i:s'),
        ]);
    }
}
