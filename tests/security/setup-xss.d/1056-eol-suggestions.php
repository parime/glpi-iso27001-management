<?php

/**
 * Issue #1056 : correspondances proposées (endoflife.date). Fragment inclus par
 * tests/security/setup.php xss : un produit piégé du catalogue (libellé et identifiant source),
 * proposé pour le logiciel piégé du jeu XSS (« Logiciel licence securite A » renommé). Se relance
 * sans doublon (produit unique).
 */

use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeCatalog;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeCatalogStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;

$DB->delete('glpi_plugin_grcmanager_endoflifecatalogs', ['product' => 'xss-canary-product']);
$out['eol_catalog_xss'] = $insert('endoflifecatalogs', [
    'product'  => 'xss-canary-product',
    'label'    => $canary,
    'category' => 'app',
    'terms'    => (string) json_encode([[
        'term'   => EndOfLifeCatalog::key($canary),
        'level'  => EndOfLifeCatalog::LEVEL_PURL,
        'source' => $canary,
    ]]),
    'date_mod' => date('Y-m-d H:i:s'),
]);
Config::setConfigurationValues(EndOfLifeStore::CONFIG_CONTEXT, [
    EndOfLifeCatalogStore::KEY_SUCCESS => date('Y-m-d H:i:s'),
]);
