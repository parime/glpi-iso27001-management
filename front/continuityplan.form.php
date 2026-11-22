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

use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\ContinuityPlanTesting;
use GlpiPlugin\Grcmanager\ContinuityPlanVersion;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Nonconformity;

// Issue #1083 : plan de continuité ou de reprise. Chaque action vérifie le droit de la continuité et
// l'entité du plan (check() du cœur) ; la réponse d'un approbateur exige en plus d'être un approbateur
// de la version en attente. Le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new ContinuityPlan();
$planId = (int) ($_POST['id'] ?? 0);

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(ContinuityPlan::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check($planId, DELETE);
    $item->delete($_POST);
    Html::redirect(ContinuityPlan::getSearchURL());
} elseif (isset($_POST['restore'])) {
    $item->check($planId, DELETE);
    $item->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($planId, PURGE);
    $item->delete($_POST, true);
    Html::redirect(ContinuityPlan::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check($planId, UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['submit_approval'])) {
    $item->check($planId, UPDATE);
    if (ContinuityPlanVersion::submit($item, (string) ($_POST['comment'] ?? '')) > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('The plan has been frozen and sent for approval.', 'grcmanager')));
    }
    Html::back();
} elseif (isset($_POST['withdraw_approval'])) {
    $item->check($planId, UPDATE);
    ContinuityPlanVersion::withdraw($item);
    Html::back();
} elseif (isset($_POST['new_version_submit'])) {
    $item->check($planId, UPDATE);
    ContinuityPlanVersion::prepareNewVersion($item, (string) ($_POST['new_version'] ?? ''));
    Html::back();
} elseif (isset($_POST['answer_approval'])) {
    $item->check($planId, READ);
    $usersId = (int) Session::getLoginUserID();
    if (!ContinuityPlanVersion::awaitsUser($planId, $usersId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $pending = ContinuityPlanVersion::pending($planId);
    $approve = ($_POST['decision'] ?? '') === 'approve';
    if (
        $pending === null
        || !ContinuityPlanVersion::answer((int) $pending['id'], $usersId, $approve, (string) ($_POST['comment'] ?? ''))
    ) {
        Session::addMessageAfterRedirect(
            htmlescape(__('Your answer was not recorded: a rejection needs a comment.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::back();
} elseif (isset($_POST['add_test'])) {
    $item->check($planId, UPDATE);
    if (ContinuityPlanTesting::add($item, $_POST) > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Test recorded.', 'grcmanager')));
    }
    Html::back();
} elseif (isset($_POST['delete_test'])) {
    $item->check($planId, UPDATE);
    ContinuityPlanTesting::remove($item, (int) ($_POST['tests_id'] ?? 0));
    Html::back();
} elseif (isset($_POST['create_nonconformity'])) {
    $item->check($planId, UPDATE);
    if (!Nonconformity::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $ncId = ContinuityPlanTesting::createNonconformity($item, (int) ($_POST['tests_id'] ?? 0));
    if ($ncId > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Nonconformity created from the gaps of the test.', 'grcmanager')));
    }
    Html::back();
} else {
    Session::checkRight(ContinuityPlan::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Menu::header(ContinuityPlan::class, ContinuityPlan::getTypeName(1), ContinuityPlan::getSearchURL());

    $item->display(['id' => $id]);

    Html::footer();
}
