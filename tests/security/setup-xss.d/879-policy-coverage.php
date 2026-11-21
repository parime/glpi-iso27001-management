<?php

/**
 * Issue #879 : section piegee d'un lien politique-mesure. Fragment inclus par tests/security/setup.php xss.
 */

$insert('policies_controls', [
    'plugin_grcmanager_policies_id' => (int) $out['policy'], 'plugin_grcmanager_controls_id' => (int) $ids['control'],
    'section' => $canary, 'users_id' => 2, 'date_creation' => date('Y-m-d H:i:s'),
]);
