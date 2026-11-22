<?php

/**
 * Issue #1095 : le fournisseur piege (nom et commentaire) est lie au risque de A, dont le titre est
 * piege : formulaire du risque (selection des fournisseurs) et onglet Securite du fournisseur.
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$out['risk_supplier_xss'] = $insert('risks_items', [
    'plugin_grcmanager_risks_id' => (int) $ids['risk'],
    'itemtype'                   => 'Supplier',
    'items_id'                   => (int) $out['supplier'],
    'date_creation'              => date('Y-m-d H:i:s'),
]);
