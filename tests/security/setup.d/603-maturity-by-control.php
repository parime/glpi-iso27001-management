<?php

/**
 * Issue #603 : maturité par mesure. Fragment inclus par tests/security/setup.php (jeu de données).
 */

// Issue #603 : une campagne de maturité par entité, avec un écart sur A.5.1 (niveau actuel 1,
// cible 3) pour afficher l'onglet des écarts et le bouton d'action. Script relancé : la campagne
// existante est reprise.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $campaign = new \GlpiPlugin\Grcmanager\MaturityCampaign();
    $criteria = ['name' => 'Campagne maturite securite ' . $suffix, 'entities_id' => $entity];
    $ids['maturity_' . $suffix] = $campaign->getFromDBByCrit($criteria)
        ? $campaign->getID()
        : $add(new \GlpiPlugin\Grcmanager\MaturityCampaign(), $criteria + ['campaign_date' => '2026-01-20']);
    $ids['maturity_line_' . $suffix] = (int) ($DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_grcmanager_maturityassessments',
        'WHERE'  => ['plugin_grcmanager_maturitycampaigns_id' => $ids['maturity_' . $suffix], 'code' => 'A.5.1'],
    ])->current()['id'] ?? 0);
    $DB->update('glpi_plugin_grcmanager_maturityassessments', [
        'current_level' => 1, 'target_level' => 3, 'justification' => 'Ecart maturite securite ' . $suffix,
    ], ['id' => $ids['maturity_line_' . $suffix]]);
}
