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

include('../../../inc/includes.php');

// Issue #171 : lien cause → conséquence (onglet « Effet domino »).
$link = new PluginGrcmanagerCausalLink();
if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerCausalLink::$rightname, CREATE);
    $key = (string) ($_POST['item_key'] ?? '');
    $other = (string) ($_POST['other'] ?? '');
    $isEffect = ($_POST['direction'] ?? 'effect') === 'effect';
    if ($link->add(['cause' => $isEffect ? $key : $other, 'effect' => $isEffect ? $other : $key])) {
        Session::addMessageAfterRedirect(htmlescape(__('Lien ajouté.', 'grcmanager')));
    }
    Html::back();
}
if (isset($_POST['purge'])) {
    $link->check((int) ($_POST['id'] ?? 0), PURGE);
    $link->delete(['id' => (int) $_POST['id']], true);
    Html::back();
}
Html::redirect(PluginGrcmanagerCausalLink::getSearchURL());
