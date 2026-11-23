<?php

/**
 * Issue #618 : decision deleguee piegee (libelle fige de l'element) dans le journal des
 * decisions deleguees. Fragment inclus par tests/security/setup.php xss.
 */

$insert('delegateddecisions', [
    'entities_id' => (int) $ids['a'], 'decision_type' => 'access_review',
    'itemtype' => 'GlpiPlugin\Grcmanager\AccessReview', 'items_id' => (int) $ids['campaign_A'],
    'item_label' => $canary, 'decision' => 'keep', 'users_id_holder' => 2, 'users_id_substitute' => 2,
    'date_start' => date('Y-m-d H:i:s', strtotime('-1 day')), 'date_end' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'date_creation' => date('Y-m-d H:i:s'),
]);
