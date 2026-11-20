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

use GlpiPlugin\Grcmanager\Services\License\LicenseInventory;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;

// Issue #779 : conformité des licences logicielles (A.5.32). Droit des registres sensibles du plugin
// et lecture native des logiciels et des licences ; constats des entités de la session seulement.
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);
Session::checkRight(SoftwareLicense::$rightname, READ);

// Éditeurs surveillés et logiciels ignorés : réglage du plugin (jeton CSRF vérifié par GLPI).
if (isset($_POST['save_license_watch']) || isset($_POST['reset_license_watch'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    $before = LicenseInventory::settings();
    if (isset($_POST['reset_license_watch'])) {
        LicenseInventory::reset();
    } else {
        LicenseInventory::save(
            (string) ($_POST['license_publishers'] ?? ''),
            (string) ($_POST['license_ignored'] ?? '')
        );
    }
    if (LicenseInventory::settings() !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record(
            'config_change',
            'Config',
            0,
            0,
            'success',
            ['changes' => ['license_watch' => LicenseInventory::settings()]]
        );
    }
    Session::addMessageAfterRedirect(htmlescape(__('Watched publishers saved.', 'grcmanager')));
    Html::redirect(SoftwareLicenseCompliance::getSearchURL() . '#grc-license-watch');
}

Html::header(
    SoftwareLicenseCompliance::getTypeName(),
    SoftwareLicenseCompliance::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    SoftwareLicenseCompliance::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    SoftwareLicenseCompliance::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('softwarelicensecompliance');

SoftwareLicenseCompliance::showPage();

Html::footer();
