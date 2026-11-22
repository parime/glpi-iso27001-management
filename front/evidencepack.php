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

use GlpiPlugin\Grcmanager\EvidencePack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// Issue #868 : dossier de preuves exportable pour l'auditeur. Consultation avec le droit de lire le
// plugin ; génération et limite de taille avec le droit de le modifier (jeton CSRF vérifié par GLPI).
Session::checkRight(EvidencePack::RIGHTNAME, READ);

// Issue #1045 : la limite de taille est dans la configuration du plugin (onglet « Mesures, audits
// et preuves ») ; l'ancien envoi reste accepté pendant une version et mène à son bloc.
if (isset($_POST['save_settings'])) {
    Session::checkRight(EvidencePack::RIGHTNAME, UPDATE);
    EvidencePack::saveMaxMb($_POST['max_mb'] ?? null);
    \GlpiPlugin\Grcmanager\Config\SettingsLink::redirectMoved(
        \GlpiPlugin\Grcmanager\Config\Block\EvidencePackBlock::ANCHOR
    );
}

if (isset($_POST['generate'])) {
    Session::checkRight(EvidencePack::RIGHTNAME, UPDATE);
    $entityId = (int) ($_POST['entities_id'] ?? -1);
    if (!Session::haveAccessToEntity($entityId) || !EvidencePack::isPerimeterAllowed($entityId)) {
        throw new AccessDeniedHttpException();
    }
    $result = EvidencePack::generate($entityId);
    if ($result['id'] > 0) {
        Session::addMessageAfterRedirect(
            __('Evidence pack generated and archived. Download it from the list below.', 'grcmanager')
        );
    } else {
        Session::addMessageAfterRedirect(htmlescape($result['error']), false, ERROR);
    }
    Html::redirect(EvidencePack::getSearchURL() . '?entities_id=' . $entityId);
}

\GlpiPlugin\Grcmanager\Menu::header(
    EvidencePack::class,
    EvidencePack::getTypeName(1),
    EvidencePack::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(EvidencePack::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('evidencepack');
// Entité demandée : affichée seulement si elle est accessible, sinon l'entité active.
$entityId = (int) ($_GET['entities_id'] ?? Session::getActiveEntity());
if (!Session::haveAccessToEntity($entityId)) {
    $entityId = (int) Session::getActiveEntity();
}
EvidencePack::showPage($entityId);
Html::footer();
