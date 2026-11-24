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

/**
 * Issue #140 : ajout et retrait d'un membre de l'équipe d'audit (onglet « Équipe »). Il faut
 * pouvoir modifier l'audit.
 */

// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

Session::checkRight(\GlpiPlugin\Grcmanager\Audit::RIGHTNAME, UPDATE);

$audit = new \GlpiPlugin\Grcmanager\Audit();
$auditsId = (int) ($_POST['plugin_grcmanager_audits_id'] ?? 0);
$audit->check($auditsId, UPDATE);

if (isset($_POST['add'])) {
    $usersId = (int) ($_POST['users_id'] ?? 0);
    $user = new User();
    $role = (string) ($_POST['role'] ?? '');
    if ($user->getFromDB($usersId) && !\GlpiPlugin\Grcmanager\EntityPerimeter::userReachesItem($usersId, $audit)) {
        // Issue #536 : membre sans profil dans l'entité de l'audit.
        Session::addMessageAfterRedirect(
            __('This account has no profile in the entity of the audit.', 'grcmanager'),
            false,
            ERROR
        );
    } elseif (!$user->getFromDB($usersId) || !\GlpiPlugin\Grcmanager\AuditTeam::addMember($auditsId, $usersId, $role)) {
        Session::addMessageAfterRedirect(__('Choose a member and a role.', 'grcmanager'), false, ERROR);
    }
} elseif (isset($_POST['remove'])) {
    \GlpiPlugin\Grcmanager\AuditTeam::removeMember($auditsId, (int) ($_POST['members_id'] ?? 0));
}

Html::back();
