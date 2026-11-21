<?php

/**
 * Issue #835, lot 2 : configuration Entra ID (Maester). Fragment inclus par tests/security/setup.php
 * (jeu de données).
 */

// Issue #835, lot 2 : résultats Maester fictifs par entité (locataire Entra ID, contrôle en échec).
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $tenant = 'locataire-' . strtolower($suffix) . '.test';
    $cloudCriteria = ['entities_id' => $entity, 'domain' => $tenant];
    if (countElementsInTable('glpi_plugin_grcmanager_adaudits', $cloudCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_adaudits', $cloudCriteria + [
            'tool' => 'maester', 'netbios' => 'Locataire securite ' . $suffix, 'global_score' => 50,
            'rule_count' => 1, 'checks_total' => 2, 'checks_passed' => 1, 'native_score' => 50,
            'report_date' => date('Y-m-d H:i:s'), 'date_import' => date('Y-m-d H:i:s'),
            'file_sha256' => hash('sha256', $tenant),
        ]);
        $DB->insert('glpi_plugin_grcmanager_adauditrules', [
            'plugin_grcmanager_adaudits_id' => (int) $DB->insertId(), 'rule_id' => 'MT.Securite-' . $suffix,
            'category' => 'identity', 'model' => 'Conditional Access · High', 'points' => 30,
            'rationale' => 'Controle Entra securite ' . $suffix,
        ]);
    }
    $cloudAudit = $DB->request([
        'FROM' => 'glpi_plugin_grcmanager_adaudits', 'WHERE' => $cloudCriteria, 'LIMIT' => 1,
    ])->current();
    $ids['cloudaudit_' . $suffix] = (int) ($cloudAudit['id'] ?? 0);
}
