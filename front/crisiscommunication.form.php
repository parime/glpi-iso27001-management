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

// Issue #616 : trace d'une communication de crise faite depuis l'onglet d'un incident ou d'une
// violation. Rien n'est envoyé : la personne a envoyé le message elle-même. Il faut pouvoir
// modifier l'incident ou la violation (contrôlé dans CrisisCommunication::record()).
use GlpiPlugin\Grcmanager\CrisisCommunication;

if (!isset($_POST['record'])) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
$item = CrisisCommunication::parentOf((string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
if ($item === null) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
// Droit de modifier l'incident ou la violation, dans les entités de la personne (403 sinon).
$item->check($item->getID(), UPDATE);
$result = CrisisCommunication::record($item, $_POST);
if (is_string($result)) {
    Session::addMessageAfterRedirect(htmlescape($result), false, ERROR);
} else {
    Session::addMessageAfterRedirect(__('Communication recorded.', 'grcmanager'), false, INFO);
}

Html::back();
