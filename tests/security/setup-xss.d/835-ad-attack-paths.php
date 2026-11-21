<?php

/**
 * Issue #835, lot 3 : chemins d'attaque. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #835, lot 3 : nom de compte exposé et nom de fichier piégés (détail de l'import, preuve A.8.2).
$DB->update('glpi_plugin_grcmanager_adattackpathaccounts', ['name' => mb_substr($canary, 0, 255)], [
    'name' => 'Compte expose A',
]);
$DB->update('glpi_plugin_grcmanager_adattackpaths', ['file_name' => mb_substr($canary, 0, 255)], [
    'id' => (int) ($ids['attackpath_A'] ?? 0),
]);
$out['attackpath_A'] = (int) ($ids['attackpath_A'] ?? 0);
