<?php

/**
 * Issue #843 : contrôleurs de gestion des serveurs. Fragment inclus par tests/security/setup.php
 * (jeu de données).
 */

// Issue #843 : contrôleur lu (Redfish, sans mot de passe) et son compte local, par entité ; celui de
// A est rattaché à l'ordinateur de A (onglet « Contrôleur de gestion »).
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $bmcCriteria = ['entities_id' => $entity, 'name' => 'Controleur BMC securite ' . $suffix];
    $bmc = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_bmccontrollers', 'WHERE' => $bmcCriteria])
        ->current();
    if ($bmc === null) {
        $DB->insert('glpi_plugin_grcmanager_bmccontrollers', $bmcCriteria + [
            'is_recursive' => 0, 'source' => 'redfish',
            'url' => 'https://bmc-securite-' . strtolower($suffix) . '.example.test',
            'username' => 'audit', 'computers_id' => (int) $ids['computer_' . $suffix], 'manager_model' => 'iLO 5',
            'firmware_version' => 'Firmware BMC securite ' . $suffix, 'ipmi_lan' => 1,
            'date_read' => date('Y-m-d H:i:s'), 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $bmcId = (int) $DB->insertId();
        $DB->insert('glpi_plugin_grcmanager_bmcaccounts', [
            'plugin_grcmanager_bmccontrollers_id' => $bmcId, 'username' => 'Compte BMC securite ' . $suffix,
            'role' => 'Administrator', 'is_enabled' => 1, 'date_import' => date('Y-m-d H:i:s'),
        ]);
    } else {
        $bmcId = (int) $bmc['id'];
    }
    $ids['bmc_' . $suffix] = $bmcId;
}
