<?php

/**
 * Issue #543 : rapports de scanners de vulnérabilités. Fragment inclus par tests/security/setup.php
 * (jeu de données).
 */

// Issue #543 : un import Greenbone et un constat rattaché à l'ordinateur de chaque entité.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $uuid = 'securite-scanner-' . $suffix;
    if (countElementsInTable('glpi_plugin_grcmanager_scannerimports', ['report_uuid' => $uuid]) === 0) {
        $DB->insert('glpi_plugin_grcmanager_scannerimports', [
            'entities_id' => $entity, 'scanner' => 'openvas', 'report_uuid' => $uuid,
            'task_name' => 'Tache scanner securite ' . $suffix, 'scan_start' => date('Y-m-d H:i:s'),
            'host_count' => 1, 'finding_count' => 1, 'high_count' => 1, 'file_name' => 'scanner-' . $suffix . '.xml',
            'date_import' => date('Y-m-d H:i:s'),
        ]);
        $import = (int) $DB->insertId();
        $DB->insert('glpi_plugin_grcmanager_scannerfindings', [
            'entities_id' => $entity, 'scanner' => 'openvas', 'finding_key' => hash('sha256', $uuid),
            'host' => '192.0.2.' . ($suffix === 'A' ? '41' : '42'), 'hostname' => 'pc-securite-' . strtolower($suffix),
            'port' => '443/tcp', 'test_id' => '1.3.6.1.4.1.25623.1.0.117274',
            'name' => 'Constat scanner securite ' . $suffix, 'severity' => 7.5,
            'computers_id' => (int) $ids['computer_' . $suffix], 'first_seen' => date('Y-m-d H:i:s'),
            'last_seen' => date('Y-m-d H:i:s'), 'plugin_grcmanager_scannerimports_id' => $import,
        ]);
    }
    $ids['scannerimport_' . $suffix] = (int) ($DB->request([
        'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_scannerimports', 'WHERE' => ['report_uuid' => $uuid],
    ])->current()['id'] ?? 0);
    $ids['scannerfinding_' . $suffix] = (int) ($DB->request([
        'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_scannerfindings',
        'WHERE' => ['finding_key' => hash('sha256', $uuid)],
    ])->current()['id'] ?? 0);
}
