<?php

/**
 * Issue #1095 : le fournisseur de A (setup.d/1040-supplier-security.php) est lie au risque de A et
 * au risque de B (table de liaison des risques, comme les actifs). Onglet Securite du fournisseur :
 * le risque de A est montre aux profils qui lisent les risques, celui de B au seul super-admin.
 * Fragment inclus par tests/security/setup.php (jeu de donnees).
 */

foreach (['risk', 'risk_B'] as $riskKey) {
    $insertOnce('glpi_plugin_grcmanager_risks_items', [
        'plugin_grcmanager_risks_id' => (int) $ids[$riskKey],
        'itemtype'                   => 'Supplier',
        'items_id'                   => (int) $ids['supplier_tab'],
    ]);
}
