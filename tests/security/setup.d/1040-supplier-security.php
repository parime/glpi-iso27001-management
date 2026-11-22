<?php

/**
 * Issue #1040 : onglet « Sécurité » de la fiche Fournisseur. Fragment inclus par
 * tests/security/setup.php (jeu de données), lu par access-matrix.d/1040-supplier-security.sh.
 *
 * Un fournisseur de A, avec un risque fournisseur dans A (montré aux profils de A qui lisent les
 * risques fournisseurs) et un dans B (montré au seul super-admin). Rien n'est recréé au second passage.
 *
 * La fiche Fournisseur de GLPI demande le droit natif « contact_enterprise » (Gestion > Contacts et
 * fournisseurs), que les profils de A n'avaient pas (leur droit « supplier » n'ouvre rien) : lecture
 * ajoutée au gestionnaire et au lecteur de A, comme pour un acheteur qui suit ses fournisseurs.
 */

foreach (['Securite ISO - gestionnaire A', 'Securite ISO - lecteur A'] as $profileName) {
    $profile = new Profile();
    if ($profile->getFromDBByCrit(['name' => $profileName])) {
        ProfileRight::updateProfileRights($profile->getID(), ['contact_enterprise' => READ]);
    }
}

$ids['supplier_tab'] = $add(new Supplier(), [
    'name' => 'Fournisseur onglet securite', 'entities_id' => $a, 'is_recursive' => 0,
]);
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['supplier_tab_risk_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\SupplierRisk(), [
        'title'        => 'Risque onglet fournisseur ' . $suffix,
        'suppliers_id' => $ids['supplier_tab'],
        'entities_id'  => $entity,
    ]);
}
