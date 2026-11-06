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

// Issue #378 : fiche d'un indicateur de risque. Chaque action vérifie le droit des registres
// sensibles ET l'entité du KRI (CommonDBTM::check()).
$item = new \GlpiPlugin\Grcmanager\RiskIndicator();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    $item->redirectToList();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['measure'])) {
    // Relevé à la demande d'une source automatique.
    $item->check((int) $_POST['id'], UPDATE);
    if (!$item->measure()) {
        Session::addMessageAfterRedirect(
            htmlescape(__('No value could be measured for this indicator.', 'grcmanager')),
            false,
            WARNING
        );
    }
    Html::back();
} elseif (isset($_POST['create_ticket'])) {
    $item->check((int) $_POST['id'], UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    $ticketsId = $item->createTicket();
    if ($ticketsId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketsId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('The indicator is not in the red: no ticket was created.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
} else {
    $id = (int) ($_GET['id'] ?? 0);
    $item->check($id > 0 ? $id : -1, $id > 0 ? READ : CREATE);
    Html::header(
        \GlpiPlugin\Grcmanager\RiskIndicator::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Risk::class,
        \GlpiPlugin\Grcmanager\RiskIndicator::class
    );
    $item->display(['id' => $id]);
    Html::footer();
}
