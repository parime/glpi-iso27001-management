<?php

/**
 * Issue #663 : palette d'affichage d'un compte, pour le test d'accessibilité en thème clair et sombre
 * (a11y-check.py). La palette est lue à la connexion : changer, puis se reconnecter.
 *
 * Usage (en www-data, depuis la racine GLPI) :
 *   php plugins/grcmanager/tests/security/a11y-palette.php <identifiant> <palette|default>
 * « default » rend au compte la palette par défaut de GLPI.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$login = (string) ($argv[1] ?? '');
$palette = (string) ($argv[2] ?? 'default');
if ($login === '' || preg_match('/^[a-z0-9_]+$/', $palette) !== 1) {
    fwrite(STDERR, "Usage : a11y-palette.php <identifiant> <palette|default>\n");
    exit(2);
}
$DB->update('glpi_users', ['palette' => $palette === 'default' ? null : $palette], ['name' => $login]);
echo json_encode(['user' => $login, 'palette' => $palette]) . "\n";
