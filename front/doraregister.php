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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\DoraArrangement;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;

// Issue #540 : registre d'informations DORA. Lecture du plugin, et référentiel DORA activé : sinon
// la page est retirée du menu et refusée par son adresse.
Session::checkRight(DoraArrangement::RIGHTNAME, READ);
if (!DoraRegister::isAvailable()) {
    throw new AccessDeniedHttpException();
}

// Export, à la demande et jamais conservé : un CSV par modèle, ou tous les modèles dans une archive.
if (isset($_GET['export'])) {
    $export = (string) $_GET['export'];
    $date = date('Y-m-d');
    AuditLog::record('export', DoraArrangement::class, 0, 0, 'success', ['format' => $export]);
    if ($export === 'zip') {
        $path = DoraRegister::buildZip();
        if ($path === null) {
            throw new NotFoundHttpException();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="dora-register-' . $date . '.zip"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        // Archive temporaire créée par buildZip() (tempnam dans GLPI_TMP_DIR), jamais un chemin reçu.
        unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        exit;
    }
    if (!isset(RegisterTemplates::TEMPLATES[$export])) {
        throw new NotFoundHttpException();
    }
    $files = DoraRegister::exportFiles();
    $name = RegisterTemplates::fileName($export);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo $files[$name];
    exit;
}

Html::header(DoraRegister::getTypeName(1), $_SERVER['PHP_SELF'], 'grcmanager', Control::class, DoraRegister::class);
Menu::showSection(Control::class, DoraRegister::class);
\GlpiPlugin\Grcmanager\PageHelp::show('doraregister');
DoraRegister::showRegisterPage();
Html::footer();
