<?php

/**
 * Issue #843, lot 2 : compte de contrôleur piégé dans la campagne de A (onglet de la campagne).
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$DB->update('glpi_plugin_grcmanager_accessreviews_bmcaccounts', [
    'controller_name' => mb_substr($canary, 0, 255), 'username' => mb_substr($canary, 0, 255),
    'role' => mb_substr($canary, 0, 64), 'reasons' => json_encode([$canary]),
], ['plugin_grcmanager_accessreviews_id' => (int) $ids['campaign_A']]);
