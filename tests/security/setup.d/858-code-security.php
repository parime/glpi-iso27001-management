<?php

/**
 * Issue #858 : dépôt GitHub lu, avec une alerte de dépendance et un secret exposé, pour
 * l'application de A et celle de B. Fragment inclus par tests/security/setup.php (jeu de données).
 */

foreach (['A', 'B'] as $suffix) {
    $appliance = (int) $ids['appliance_' . $suffix];
    if (countElementsInTable('glpi_plugin_grcmanager_coderepositories', ['appliances_id' => $appliance]) === 0) {
        $DB->insert('glpi_plugin_grcmanager_coderepositories', [
            'appliances_id' => $appliance, 'provider' => 'github', 'repository' => 'example/securite-' . $suffix,
            'status' => 'ok', 'dependency_scan' => 'ok', 'secret_scan' => 'ok', 'code_scan' => 'unavailable',
            'date_read' => date('Y-m-d H:i:s'), 'date_attempt' => date('Y-m-d H:i:s'),
        ]);
        foreach (['dependency' => 'high', 'secret' => 'critical'] as $category => $severity) {
            $DB->insert('glpi_plugin_grcmanager_codesecurityalerts', [
                'appliances_id' => $appliance, 'provider' => 'github', 'category' => $category,
                'alert_key' => '858', 'severity' => $severity,
                'title' => 'Alerte code securite ' . $suffix, 'detail' => 'npm exemple-' . $suffix,
                'validity' => $category === 'secret' ? 'active' : '',
                'url' => 'https://github.com/example/securite-' . $suffix . '/security',
                'date_detected' => date('Y-m-d H:i:s', time() - 40 * 86400), 'date_import' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
