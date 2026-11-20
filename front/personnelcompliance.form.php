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
use GlpiPlugin\Grcmanager\PersonnelCompliance;

/**
 * Issue #546 : fiche de conformité sécurité d'un collaborateur.
 * - « save » : vérification préalable à l'embauche et engagement de confidentialité ;
 * - « notify » : prévenir maintenant le responsable hiérarchique des écarts.
 * Les deux exigent de modifier le plugin et de lire les utilisateurs, pour un compte habilité dans
 * les entités de la session ; personne ne modifie sa propre fiche.
 */
Session::checkLoginUser();
$usersId = (int) ($_POST['users_id'] ?? 0);
if ($usersId === (int) Session::getLoginUserID() || !PersonnelCompliance::canEditFor($usersId)) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['save'])) {
    if (PersonnelCompliance::saveRecord($usersId, $_POST)) {
        Session::addMessageAfterRedirect(__('Security compliance record saved.', 'grcmanager'));
    }
} elseif (isset($_POST['notify'])) {
    if (PersonnelCompliance::notifyManager($usersId)) {
        Session::addMessageAfterRedirect(__('The manager has been notified of the gaps.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(
            __('No notification sent: check that the person has a manager with an email address and that the notification is active.', 'grcmanager'),
            false,
            WARNING
        );
    }
}

Html::back();
