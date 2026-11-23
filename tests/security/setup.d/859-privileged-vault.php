<?php

/**
 * Issue #859 : comptes à privilèges et coffre-fort. Fragment inclus par tests/security/setup.php
 * (jeu de données) : un élément et un membre du coffre fictifs par entité, jamais de secret.
 */

foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $vaultCriteria = ['entities_id' => $entity, 'source' => 'pam'];
    if (countElementsInTable('glpi_plugin_grcmanager_vaultentries', $vaultCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_vaultentries', $vaultCriteria + [
            'name' => 'Compte coffre securite ' . $suffix, 'collection' => 'c-securite', 'username' => 'root',
            'date_changed' => '2020-01-01 00:00:00', 'date_import' => date('Y-m-d H:i:s'),
        ]);
    }
    if (countElementsInTable('glpi_plugin_grcmanager_vaultmembers', $vaultCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_vaultmembers', $vaultCriteria + [
            'email' => 'membre.coffre.securite.' . strtolower($suffix) . '@exemple.test', 'role' => 'admin',
            'status' => 'active', 'collections' => 'c-securite', 'two_factor' => 0,
            'date_import' => date('Y-m-d H:i:s'),
        ]);
    }
}
