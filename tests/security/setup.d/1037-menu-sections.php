<?php

/**
 * Issue #1037 : pages des rubriques de menu déclarées dans src/Navigation/Section/. Fragment inclus par
 * tests/security/setup.php (jeu de données), lu par access-matrix.d/1037-menu-sections.sh.
 *
 * Pour chaque rubrique affichée et chacune de ses sous-pages vues par le super-admin : l'adresse de la
 * page, celle de sa rubrique (attendues dans le fil d'Ariane) et si le menu la montre à un profil sans
 * aucun droit du plugin (la page doit alors s'ouvrir ; sinon elle doit être refusée proprement).
 * Rien n'est créé : le fragment se relance sans effet.
 */

use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Sections;

$menuPages = [];
$profile = $_SESSION['glpiactiveprofile'];
$withoutPluginRights = $profile;
foreach (array_keys($withoutPluginRights) as $right) {
    if (str_starts_with((string) $right, 'plugin_grcmanager')) {
        $withoutPluginRights[$right] = 0;
    }
}
$visibleWithoutRights = static function (string $key, bool $isParent) use ($withoutPluginRights, $profile): bool {
    $_SESSION['glpiactiveprofile'] = $withoutPluginRights;
    try {
        if ($isParent) {
            $menu = $key::getMenuContent();

            return is_array($menu) && $menu !== [] && empty($menu['parent_hidden']);
        }

        return Menu::pageMenu($key) !== null;
    } finally {
        $_SESSION['glpiactiveprofile'] = $profile;
    }
};
foreach (Sections::all() as $section) {
    $parent = $section::parent();
    if (!$section::enabled()) {
        continue;
    }
    $parentMenu = $parent::getMenuContent();
    if (!is_array($parentMenu) || !isset($parentMenu['page']) || !empty($parentMenu['parent_hidden'])) {
        continue;
    }
    $menuPages[] = [
        'key'    => $parent,
        'url'    => (string) $parentMenu['page'],
        'parent' => (string) $parentMenu['page'],
        'sub'    => false,
        'none'   => $visibleWithoutRights($parent, true),
    ];
    foreach (array_keys(Sections::pages($parent)) as $key) {
        $menu = Menu::pageMenu($key);
        if ($menu === null || !isset($menu['page'])) {
            continue;
        }
        $menuPages[] = [
            'key'    => $key,
            'url'    => (string) $menu['page'],
            'parent' => (string) $parentMenu['page'],
            'sub'    => true,
            'none'   => $visibleWithoutRights($key, false),
        ];
    }
}
$ids['menu_pages'] = $menuPages;
