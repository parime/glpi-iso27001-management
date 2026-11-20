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

// Issue #616 : liste de diffusion d'un modèle de message de crise (ajout et retrait), droit de
// modifier les modèles ; chaque destinataire ajouté doit être lisible par la personne.
use GlpiPlugin\Grcmanager\CrisisMessageRecipient;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;

Session::checkRight(CrisisMessageTemplate::$rightname, UPDATE);

if (isset($_POST['add'])) {
    $field = (string) ($_POST['items_field'] ?? '');
    $itemsId = in_array($field, ['users_id', 'groups_id', 'contextitems_id'], true) ? (int) ($_POST[$field] ?? 0) : 0;
    if (
        !CrisisMessageRecipient::addTo(
            (int) ($_POST[CrisisMessageRecipient::TEMPLATE_FK] ?? 0),
            (string) ($_POST['itemtype'] ?? ''),
            $itemsId
        )
    ) {
        Session::addMessageAfterRedirect(__('This recipient cannot be added.', 'grcmanager'), false, ERROR);
    }
} elseif (isset($_POST['purge'])) {
    $item = new CrisisMessageRecipient();
    // Retirer un destinataire modifie le modèle : même droit que l'ajout.
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();
