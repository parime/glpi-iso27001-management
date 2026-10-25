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

include('../../../inc/includes.php');

// Issue #145 : liste des preuves datées, de la plus proche de l'expiration à la plus lointaine.
// Search::show() applique la restriction d'entité de la table (entities_id/is_recursive, repris de
// l'élément porteur à la création).
Session::checkRight(PluginGrcmanagerEvidence::RIGHTNAME, READ);

Html::header(
    PluginGrcmanagerEvidence::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerEvidence::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('evidence');

echo '<div class="alert alert-info d-flex align-items-center"><i class="ti ti-info-circle me-2"></i>'
    . htmlescape(__(
        'Une date de validité se saisit depuis l\'onglet « Validités des preuves » d\'une mesure, d\'un '
            . 'risque fournisseur, d\'un audit ou d\'une politique, pour chaque document rattaché.',
        'grcmanager'
    )) . '</div>';

if (!isset($_GET['sort'])) {
    $_GET['sort'] = 4;
    $_GET['order'] = 'ASC';
}
Search::show(PluginGrcmanagerEvidence::class);

Html::footer();
