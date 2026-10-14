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

$item = new PluginGrcmanagerIsmsRequirement();

// Catalogue fixe (30 sous-articles seedés à l'installation) : seule la mise à jour est possible,
// pas d'ajout ni de suppression (voir PluginGrcmanagerIsmsRequirement::canCreate()/canDelete()).
if (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerIsmsRequirement::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // displayFullPageForItem() plutôt que showForm() : seul display() affiche la barre d'onglets,
    // nécessaire ici pour l'onglet natif Documents (preuves), même raison que front/policy.form.php.
    PluginGrcmanagerIsmsRequirement::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => ['grcmanager', PluginGrcmanagerIsmsRequirement::class], 'helpdesk' => []]
    );
}
