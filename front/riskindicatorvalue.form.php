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

// Issue #378 : ajout ou suppression d'un relevé depuis la fiche du KRI, avec les droits et l'entité
// de ce KRI. Pas de fiche ni de liste propres.
$kri = new \GlpiPlugin\Grcmanager\RiskIndicator();
$kriId = (int) ($_POST['plugin_grcmanager_riskindicators_id'] ?? 0);

if (isset($_POST['add'])) {
    $kri->check($kriId, UPDATE);
    $value = \GlpiPlugin\Grcmanager\RiskIndicator::toNumber($_POST['value'] ?? null);
    $date = (string) ($_POST['value_date'] ?? '');
    if ($value === null) {
        Session::addMessageAfterRedirect(htmlescape(__('A numeric value is required.', 'grcmanager')), false, ERROR);
    } else {
        $kri->recordValue(
            $value,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d'),
            (string) ($_POST['comment'] ?? '')
        );
    }
} elseif (isset($_POST['purge'])) {
    $kri->check($kriId, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\RiskIndicator::$rightname, PURGE);
    $value = new \GlpiPlugin\Grcmanager\RiskIndicatorValue();
    $valueId = (int) ($_POST['id'] ?? 0);
    // Le relevé doit appartenir au KRI contrôlé ci-dessus.
    if ($value->getFromDB($valueId) && (int) $value->fields['plugin_grcmanager_riskindicators_id'] === $kriId) {
        $value->delete(['id' => $valueId], true);
        $kri->refresh();
    }
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\RiskIndicator::$rightname, UPDATE);
}

Html::redirect(\GlpiPlugin\Grcmanager\RiskIndicator::getFormURLWithID($kriId));
