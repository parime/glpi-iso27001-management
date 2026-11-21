<?php

/**
 * Issue #835 : audit de sécurité de l'AD. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Issue #835 : rapport PingCastle fictif par entité (règle privilégiée), audit de sécurité de l'AD.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $domain = 'adsecurite-' . strtolower($suffix) . '.test';
    $adCriteria = ['entities_id' => $entity, 'domain' => $domain];
    if (countElementsInTable('glpi_plugin_grcmanager_adaudits', $adCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_adaudits', $adCriteria + [
            'tool' => 'pingcastle', 'global_score' => 55, 'privileged_score' => 55, 'rule_count' => 1,
            'report_date' => date('Y-m-d H:i:s'), 'date_import' => date('Y-m-d H:i:s'),
            'file_sha256' => hash('sha256', $domain),
        ]);
        $DB->insert('glpi_plugin_grcmanager_adauditrules', [
            'plugin_grcmanager_adaudits_id' => (int) $DB->insertId(), 'rule_id' => 'P-Securite-' . $suffix,
            'category' => 'privileged', 'model' => 'AdminControl', 'points' => 50,
            'rationale' => 'Constat AD securite ' . $suffix,
        ]);
    }
    $adAudit = $DB->request([
        'FROM' => 'glpi_plugin_grcmanager_adaudits', 'WHERE' => $adCriteria, 'LIMIT' => 1,
    ])->current();
    $ids['adaudit_' . $suffix] = (int) ($adAudit['id'] ?? 0);
    $adRule = $DB->request([
        'FROM'  => 'glpi_plugin_grcmanager_adauditrules',
        'WHERE' => ['rule_id' => 'P-Securite-' . $suffix],
        'LIMIT' => 1,
    ])->current();
    $ids['adrule_' . $suffix] = (int) ($adRule['id'] ?? 0);
}
