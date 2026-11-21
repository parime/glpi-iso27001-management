<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Grcmanager\ProductSbom;
use GlpiPlugin\Grcmanager\SecureDevelopment;

// Issue #869 : lecture des nomenclatures (SBOM) importées, par produit et par version, avec un lien
// vers l'onglet « SBOM et VEX » de chaque application. Droit : lecture du plugin et des
// applications ; applications limitées aux entités de la session (ProductSbom::allSboms()).
Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, READ);
if (!ProductSbom::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(
    ProductSbom::getTypeName(Session::getPluralNumber()),
    ProductSbom::getPageURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    SecureDevelopment::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('productsbom');
ProductSbom::showPage();

Html::footer();
