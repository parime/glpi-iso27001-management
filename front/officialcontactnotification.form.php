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

use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\OfficialContactNotification;

// Issue #771, lot 2 : prise de contact avec un contact officiel, tracée depuis l'onglet « Contacts
// officiels » d'un incident de sécurité ou d'une violation de données (seul écran qui poste ici).
// Rien n'est envoyé : la personne trace ce qu'elle a fait. Il faut le droit du contexte de
// l'organisation et pouvoir modifier l'incident ou la violation (droit natif et entité, 403 sinon).
// Jeton CSRF contrôlé par GLPI sur tout POST.
if (!isset($_POST['add']) && !isset($_POST['purge'])) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
Session::checkRight(ContextItem::$rightname, READ);

if (isset($_POST['add'])) {
    $item = OfficialContactNotification::parentOf((string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
} else {
    $trace = new OfficialContactNotification();
    $item = $trace->getFromDB((int) ($_POST['id'] ?? 0))
        ? OfficialContactNotification::parentOf((string) $trace->fields['itemtype'], (int) $trace->fields['items_id'])
        : null;
}
if ($item === null) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$item->check($item->getID(), UPDATE);

if (isset($_POST['add'])) {
    $result = OfficialContactNotification::record($item, $_POST);
    $error = is_string($result) ? $result : null;
    $done = __('Contact recorded.', 'grcmanager');
} else {
    $error = OfficialContactNotification::remove((int) $_POST['id']);
    $done = __('Recorded contact removed.', 'grcmanager');
}

Session::addMessageAfterRedirect(htmlescape($error ?? $done), false, $error === null ? INFO : ERROR);
Html::back();
