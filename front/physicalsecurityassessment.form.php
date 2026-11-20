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
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\PhysicalSecurityAssessment;
use GlpiPlugin\Grcmanager\PhysicalSecurityCheck;
use GlpiPlugin\Grcmanager\PhysicalSecurityZone;

// Issue #778 : évaluation de la sécurité physique d'un site, ses lignes A.7.x et ses zones. Chaque
// action vérifie le droit du plugin et l'entité de l'évaluation (check() du cœur) ; les lignes et les
// zones exigent de pouvoir modifier l'évaluation. Le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new PhysicalSecurityAssessment();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(PhysicalSecurityAssessment::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete($_POST, true);
    Html::redirect(PhysicalSecurity::getPageURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (
    isset($_POST['save_check']) || isset($_POST['add_zone']) || isset($_POST['save_zone'])
    || isset($_POST['delete_zone'])
) {
    $assessmentId = (int) ($_POST[PhysicalSecurityAssessment::FK] ?? 0);
    if ($assessmentId <= 0 || !$item->getFromDB($assessmentId)) {
        throw new BadRequestHttpException();
    }
    $item->check($assessmentId, UPDATE);
    if (isset($_POST['save_check'])) {
        if (!PhysicalSecurityCheck::updateLine($item, (int) ($_POST['id'] ?? 0), $_POST)) {
            throw new BadRequestHttpException();
        }
        Session::addMessageAfterRedirect(htmlescape(__('Control saved.', 'grcmanager')));
    } elseif (isset($_POST['add_zone'])) {
        if (!PhysicalSecurityZone::addZone($item, $_POST)) {
            Session::addMessageAfterRedirect(htmlescape(__('Give the zone a name.', 'grcmanager')), false, ERROR);
        }
    } elseif (isset($_POST['save_zone'])) {
        if (!PhysicalSecurityZone::updateZone($item, (int) ($_POST['id'] ?? 0), $_POST)) {
            throw new BadRequestHttpException();
        }
    } elseif (!PhysicalSecurityZone::deleteZone($item, (int) ($_POST['id'] ?? 0))) {
        throw new BadRequestHttpException();
    }
    // Retour sur la fiche : GLPI y rouvre le dernier onglet affiché (grille ou zones).
    Html::back();
} else {
    Session::checkRight(PhysicalSecurityAssessment::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Évaluation d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    } elseif (!PhysicalSecurityAssessment::canCreate()) {
        throw new AccessDeniedHttpException();
    }

    Html::header(
        PhysicalSecurityAssessment::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Control::class,
        PhysicalSecurity::class
    );

    $item->display(['id' => $id, 'locations_id' => (int) ($_GET['locations_id'] ?? 0)]);

    Html::footer();
}
