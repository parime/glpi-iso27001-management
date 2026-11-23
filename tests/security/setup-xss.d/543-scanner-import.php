<?php

/**
 * Issue #543 : contenu piégé d'un rapport de scanner. Fragment inclus par tests/security/setup.php.
 */

// Issue #543 : constat et import piégés (nom, famille, hôte, port, CVE, solution, tâche, fichier),
// rattaché à l'ordinateur de A et un second non rapproché ; preuve de la mesure A.8.8.
$entityScanner = (int) ($DB->request([
    'SELECT' => 'entities_id', 'FROM' => 'glpi_computers', 'WHERE' => ['id' => (int) $ids['computer_A']],
])->current()['entities_id'] ?? 0);
$DB->insert('glpi_plugin_grcmanager_scannerimports', [
    'entities_id' => $entityScanner, 'scanner' => mb_substr($canary, 0, 32), 'report_uuid' => 'xss-' . uniqid(),
    'task_name' => mb_substr($canary, 0, 255), 'file_name' => mb_substr($canary, 0, 255),
    'scan_start' => date('Y-m-d H:i:s'), 'finding_count' => 2, 'date_import' => date('Y-m-d H:i:s'),
]);
$xssImport = (int) $DB->insertId();
foreach (['scanner_xss' => (int) $ids['computer_A'], 'scanner_xss_unmatched' => 0] as $key => $computerId) {
    $DB->insert('glpi_plugin_grcmanager_scannerfindings', [
        'entities_id' => $entityScanner, 'scanner' => 'openvas', 'finding_key' => hash('sha256', $key . uniqid()),
        'host' => mb_substr($canary, 0, 255), 'hostname' => mb_substr($canary, 0, 255),
        'port' => mb_substr($canary, 0, 64), 'test_id' => mb_substr($canary, 0, 128),
        'name' => mb_substr($canary, 0, 255), 'family' => mb_substr($canary, 0, 128), 'severity' => 9.8,
        'cves' => $canary, 'solution_type' => mb_substr($canary, 0, 32), 'solution' => $canary,
        'computers_id' => $computerId, 'first_seen' => date('Y-m-d H:i:s'), 'last_seen' => date('Y-m-d H:i:s'),
        'plugin_grcmanager_scannerimports_id' => $xssImport,
    ]);
    $out[$key] = (int) $DB->insertId();
}
$out['control_a88'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.8'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
