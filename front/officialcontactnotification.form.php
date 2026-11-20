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
// Droit du contexte de l'organisation ; record() et remove() exigent en plus de pouvoir modifier
// l'incident ou la violation (droit natif et entité). Jeton CSRF contrôlé par GLPI sur tout POST.
Session::checkRight(ContextItem::$rightname, READ);

$error = null;
$done = '';
if (isset($_POST['add'])) {
    $item = OfficialContactNotification::parentOf((string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    if ($item === null || !$item->can($item->getID(), READ)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $result = OfficialContactNotification::record($item, $_POST);
    $error = is_string($result) ? $result : null;
    $done = __('Contact recorded.', 'grcmanager');
} elseif (isset($_POST['purge'])) {
    $error = OfficialContactNotification::remove((int) ($_POST['id'] ?? 0));
    $done = __('Recorded contact removed.', 'grcmanager');
} else {
    Html::back();
}

Session::addMessageAfterRedirect(htmlescape($error ?? $done), false, $error === null ? INFO : ERROR);
Html::back();
