<?php

/**
 * Issue #1050 : données du parcours des pages (tests/security/crawl.py), lues dans GLPI.
 *
 * - « forms » : une fiche existante de chaque formulaire du plugin et des écrans de GLPI qu'il
 *   modifie (ticket, fiches d'actifs...), pour ne pas seulement afficher des listes vides
 *   (issue #980). Chemin depuis la racine GLPI => identifiant.
 * - « menu » : chaque page du plugin joignable par le menu de GLPI, avec le fil d'Ariane que GLPI
 *   doit y afficher (secteur, rubrique, puis sous-page), tiré du menu en session : c'est le menu
 *   que construisent les déclarations de rubriques. Chemin depuis front/ du plugin (avec sa requête)
 *   => libellés.
 *
 * Usage (en www-data, depuis la racine GLPI, après tests/security/setup.php) :
 *   php plugins/grcmanager/tests/security/crawl-ids.php [identifiant] [mot de passe]
 * Affiche une ligne JSON {"forms": {...}, "menu": {...}}.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
$login = $argv[1] ?? 'glpi';
if (!(new Auth())->login($login, $argv[2] ?? 'glpi', false)) {
    fwrite(STDERR, "Connexion $login impossible\n");
    exit(1);
}
Session::changeActiveEntities(0, true);

global $DB;
$dir = dirname(__DIR__, 2);
$classes = [];
foreach (glob("$dir/inc/*.class.php") ?: [] as $file) {
    $classes[] = 'PluginGrcmanager' . basename($file, '.class.php');
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/src")) as $file) {
    if (str_ends_with((string) $file, '.php')) {
        $classes[] = 'GlpiPlugin\\Grcmanager\\' . str_replace('/', '\\', substr((string) $file, strlen("$dir/src/"), -4));
    }
}
// Écrans de GLPI auxquels le plugin ajoute des onglets, des champs ou des crochets.
$core = ['Ticket', 'TicketTemplate', 'Change', 'Problem', 'Computer', 'NetworkEquipment', 'Software', 'Supplier',
    'Contract', 'Appliance', 'Entity', 'Group'];
$forms = [];
foreach (array_merge($classes, $core) as $class) {
    try {
        if (!class_exists($class) || !is_subclass_of($class, CommonDBTM::class)
            || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $path = ltrim((string) parse_url($class::getFormURL(false), PHP_URL_PATH), '/');
        $table = $class::getTable();
        if (isset($forms[$path]) || !str_ends_with($path, '.form.php') || !$DB->tableExists($table)) {
            continue;
        }
        $where = $DB->fieldExists($table, 'is_deleted') ? ['is_deleted' => 0] : [];
        $row = $DB->request(['SELECT' => ['MAX' => 'id AS id'], 'FROM' => $table, 'WHERE' => $where])->current();
        if ((int) ($row['id'] ?? 0) > 0) {
            $forms[$path] = (int) $row['id'];
        }
    } catch (Throwable $e) {
        continue;
    }
}

// Fil d'Ariane attendu : celui que breadcrumbs.html.twig de GLPI rend pour l'entrée de menu
// (secteur, rubrique, puis la sous-page quand elle a un titre et une adresse).
$menu = [];
$prefix = '/plugins/grcmanager/front/';
$relative = static function ($page) use ($prefix): ?string {
    $page = (string) $page;
    $at = strpos($page, $prefix);
    return $at === false ? null : substr($page, $at + strlen($prefix));
};
try {
    $session = Html::generateMenuSession(true);
} catch (Throwable $e) {
    $session = [];
}
foreach ($session as $sector) {
    foreach ((array) ($sector['content'] ?? []) as $item) {
        if (!is_array($item) || !isset($item['page'])) {
            continue;
        }
        $crumb = [(string) ($sector['title'] ?? ''), (string) ($item['title'] ?? '')];
        $page = $relative($item['page']);
        if ($page !== null && !isset($menu[$page])) {
            $menu[$page] = $crumb;
        }
        foreach ((array) ($item['options'] ?? []) as $option) {
            if (!is_array($option) || !isset($option['page'], $option['title'])) {
                continue;
            }
            // Une sous-page l'emporte sur l'entrée de rubrique qui pointe vers elle (rubrique dont
            // seule la sous-page est visible : son en-tête nomme la sous-page).
            $page = $relative($option['page']);
            if ($page !== null && count($menu[$page] ?? []) < 3) {
                $menu[$page] = array_merge($crumb, [(string) $option['title']]);
            }
        }
    }
}

echo json_encode(['forms' => $forms, 'menu' => $menu]), "\n";
