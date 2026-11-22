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

use GlpiPlugin\Grcmanager\Config\Block\CustomFrameworkImportBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Config\Tab\FrameworksTab;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;

// Issue #537 : référentiels personnalisés. Issue #1047 : leur création et leur import sont des
// réglages, dans la configuration du plugin (onglet Référentiels, CustomFrameworkImportBlock).
// Cette adresse garde le modèle de fichier, accepte encore pendant une version l'ancien envoi du
// formulaire (même traitement, puis le bloc de la configuration) et mène au bloc. Droits d'origine.
Session::checkRight(CustomFramework::RIGHTNAME, READ);

// Modèle téléchargeable : en-tête et trois exigences fictives.
if (isset($_GET['template'])) {
    $content = CustomFrameworkCsv::template(CustomFramework::templateExamples());
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="grc-custom-framework-template.csv"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CustomFrameworkImportBlock::handleLegacy();
}

// Ancien lien (menu, favori) : le bloc de la configuration, avec le référentiel visé s'il y en a un.
MovedSettings::notice(FrameworksTab::label());
$target = (int) ($_GET['target'] ?? 0);
if ($target > 0) {
    Html::redirect(CustomFrameworkImportBlock::url($target));
}
MovedSettings::redirect(CustomFrameworkImportBlock::ANCHOR);
