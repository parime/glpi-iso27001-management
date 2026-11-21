<?php

/**
 * Issue #835, lot 3 : chemins d'attaque vers Domain Admins (BloodHound CE). Fragment inclus par
 * tests/security/setup.php (jeu de données).
 */

// Issue #835, lot 3 : un export fictif par entité, avec un compte exposé.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $pathCriteria = ['entities_id' => $entity, 'domain' => 'chemins-' . strtolower($suffix) . '.test'];
    if (countElementsInTable('glpi_plugin_grcmanager_adattackpaths', $pathCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_adattackpaths', $pathCriteria + [
            'tool' => 'bloodhound', 'path_count' => 1, 'user_count' => 1, 'shortest_path' => 2,
            'node_count' => 3, 'edge_count' => 2, 'date_import' => date('Y-m-d H:i:s'),
            'file_sha256' => hash('sha256', 'chemins-' . $suffix),
        ]);
        $DB->insert('glpi_plugin_grcmanager_adattackpathaccounts', [
            'plugin_grcmanager_adattackpaths_id' => (int) $DB->insertId(),
            'name' => 'Compte expose ' . $suffix, 'kind' => 'user', 'hops' => 2, 'routes' => 1,
        ]);
    }
    $pathImport = $DB->request([
        'FROM' => 'glpi_plugin_grcmanager_adattackpaths', 'WHERE' => $pathCriteria, 'LIMIT' => 1,
    ])->current();
    $ids['attackpath_' . $suffix] = (int) ($pathImport['id'] ?? 0);
}
