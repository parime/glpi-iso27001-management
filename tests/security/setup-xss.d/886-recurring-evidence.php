<?php

/**
 * Issue #886 : preuve piegee (nom du document) sur un rythme mensuel jamais renouvele : citee par
 * l'onglet des validites, les controles de coherence et la page du dossier de preuves.
 * Fragment inclus par tests/security/setup.php xss.
 */

$DB->updateOrInsert('glpi_plugin_grcmanager_evidences', ['valid_until' => null], [
    'itemtype' => 'GlpiPlugin\Grcmanager\Control', 'items_id' => (int) $ids['control'],
    'documents_id' => (int) $ids['evidence_upload'],
]);
$recurringEvidence = $DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_evidences',
    'WHERE'  => ['itemtype' => 'GlpiPlugin\Grcmanager\Control', 'items_id' => (int) $ids['control'],
        'documents_id' => (int) $ids['evidence_upload']],
])->current();
if ($recurringEvidence !== null) {
    $DB->updateOrInsert('glpi_plugin_grcmanager_evidencerecurrences', [
        'period_unit' => 'month_end', 'period_count' => 1, 'last_collected' => '2020-01-10',
    ], ['plugin_grcmanager_evidences_id' => (int) $recurringEvidence['id']]);
}
