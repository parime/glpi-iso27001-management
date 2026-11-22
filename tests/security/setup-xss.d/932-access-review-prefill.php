<?php

/**
 * Issue #932 : raison « écart de conformité du personnel » piégée (intitulés de formation) sur la
 * ligne de revue de la campagne de A, suggérée « à retirer » pour que le bouton « Reporter les
 * suggestions » et l'attribut data-reasons soient rendus.
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$DB->update('glpi_plugin_grcmanager_accessreviews_users', [
    'suggestion'         => 'remove',
    'suggestion_reasons' => json_encode([
        ['code' => 'expired', 'params' => ['date' => '2026-01-01']],
        [
            'code'   => 'compliance_gap',
            'params' => ['component' => 'training', 'detail' => 'renewal_overdue', 'titles' => [$canary]],
        ],
        ['code' => 'privileged', 'params' => ['profiles' => [$canary]]],
    ]),
], ['plugin_grcmanager_accessreviews_id' => (int) $ids['campaign_A'], 'decision' => 'pending']);
