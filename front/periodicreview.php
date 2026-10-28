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

// Issue #141 : revues périodiques du SMSI (activation = ticket récurrent natif de GLPI).
Session::checkRight(PluginGrcmanagerPeriodicReview::$rightname, READ);

if (isset($_POST['activate']) || isset($_POST['deactivate'])) {
    if (!PluginGrcmanagerPeriodicReview::canManage()) {
        Html::displayRightError();
    }
    $review = new PluginGrcmanagerPeriodicReview();
    if (!$review->getFromDB((int) ($_POST['id'] ?? 0))) {
        Html::displayNotFoundError();
    }
    $label = PluginGrcmanagerPeriodicReview::getLabel($review->getKey());
    if (isset($_POST['deactivate'])) {
        $review->deactivate();
        Session::addMessageAfterRedirect(htmlescape(sprintf(__('Review disabled: %s', 'grcmanager'), $label)));
    } elseif (
        $review->activate(
            (int) ($_POST['frequency_months'] ?? 0),
            (int) ($_POST['users_id_assign'] ?? 0),
            (int) ($_POST['groups_id_assign'] ?? 0)
        )
    ) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Review saved: %s. The recurrent ticket is listed under Assistance > Recurrent tickets.', 'grcmanager'),
            $label
        )));
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(sprintf(__('The review could not be enabled: %s', 'grcmanager'), $label)),
            false,
            ERROR
        );
    }
    Html::redirect(PluginGrcmanagerPeriodicReview::getSearchURL());
}

Html::header(
    PluginGrcmanagerPeriodicReview::getTypeName(2),
    PluginGrcmanagerPeriodicReview::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    PluginGrcmanagerPeriodicReview::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\AccessReview::class, PluginGrcmanagerPeriodicReview::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('periodicreview');

PluginGrcmanagerPeriodicReview::showList();

Html::footer();
