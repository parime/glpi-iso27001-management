<?php

/**
 * Issue #545 : politique piegee creee depuis un modele, citee au pied de la carte du modele.
 * Fragment inclus par tests/security/setup.php xss.
 */

$insert('policytemplateimports', [
    'template_key' => 'backup', 'language' => 'en', 'plugin_grcmanager_policies_id' => (int) $out['policy'],
    'users_id' => 2, 'date_creation' => date('Y-m-d H:i:s'),
]);
