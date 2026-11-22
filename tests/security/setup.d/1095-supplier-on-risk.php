<?php

/**
 * Issue #1095 : deux risques generaux dedies, l'un dans A, l'autre dans B, lies au fournisseur de A
 * (setup.d/1040-supplier-security.php) par la table de liaison des risques, comme les actifs. Onglet
 * Securite du fournisseur : le risque de A est montre aux profils qui lisent les risques dans A, celui
 * de B au seul super-admin. Risques dedies : la matrice enregistre le formulaire du risque commun
 * avec ses seuls ordinateurs, ce qui retire legitimement ses fournisseurs visibles.
 * Fragment inclus par tests/security/setup.php (jeu de donnees).
 */

foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['supplier_general_risk_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
        'title' => 'Risque general fournisseur ' . $suffix . ' securite', 'entities_id' => $entity,
    ]);
    $insertOnce('glpi_plugin_grcmanager_risks_items', [
        'plugin_grcmanager_risks_id' => (int) $ids['supplier_general_risk_' . $suffix],
        'itemtype'                   => 'Supplier',
        'items_id'                   => (int) $ids['supplier_tab'],
    ]);
}
