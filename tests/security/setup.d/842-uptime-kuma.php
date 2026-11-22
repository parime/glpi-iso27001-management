<?php

/**
 * Issue #842 : services exposés lus dans Uptime Kuma. Fragment inclus par tests/security/setup.php
 * (jeu de données).
 */

// Issue #842 : sonde Uptime Kuma lue (sans actif), en panne, avec un certificat proche de l'échéance, par entité.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $key = 'id:' . ('A' === $suffix ? '9200001' : '9200002');
    if (countElementsInTable('glpi_plugin_grcmanager_uptimekumamonitors', ['monitor_key' => $key]) === 0) {
        $DB->insert('glpi_plugin_grcmanager_uptimekumamonitors', [
            'entities_id' => $entity, 'is_recursive' => 0, 'monitor_key' => $key,
            'monitor_id' => substr($key, 3), 'name' => 'Sonde Kuma securite ' . $suffix, 'type' => 'http',
            'url' => 'https://kuma-securite-' . strtolower($suffix) . '.example.test', 'status' => 0,
            'cert_days' => 5, 'cert_valid' => 1, 'date_import' => date('Y-m-d H:i:s'),
        ]);
        $DB->insert('glpi_plugin_grcmanager_uptimekumasamples', [
            'entities_id' => $entity, 'monitor_key' => $key, 'status' => 0,
            'date_sample' => date('Y-m-d H:i:s', time() - 3600),
        ]);
    }
}
