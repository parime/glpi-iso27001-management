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

use GlpiPlugin\Grcmanager\EbiosElement;
use GlpiPlugin\Grcmanager\EbiosFearedEvent;
use GlpiPlugin\Grcmanager\EbiosOperationalScenario;
use GlpiPlugin\Grcmanager\EbiosRiskSource;
use GlpiPlugin\Grcmanager\EbiosStakeholder;
use GlpiPlugin\Grcmanager\EbiosStrategicScenario;
use GlpiPlugin\Grcmanager\EbiosSupport;
use GlpiPlugin\Grcmanager\EbiosValue;

include('../../../inc/includes.php');

// Issue #376 : éléments des ateliers d'une étude EBIOS RM (ajout, modification, retrait), toujours
// avec retour sur l'onglet. Le type vient d'une liste fermée, les droits sont ceux de l'étude.
$classes = [
    'value'        => EbiosValue::class,
    'feared_event' => EbiosFearedEvent::class,
    'support'      => EbiosSupport::class,
    'risk_source'  => EbiosRiskSource::class,
    'stakeholder'  => EbiosStakeholder::class,
    'strategic'    => EbiosStrategicScenario::class,
    'operational'  => EbiosOperationalScenario::class,
];
$class = $classes[(string) ($_POST['element'] ?? '')] ?? null;
Session::checkRight(EbiosElement::RIGHTNAME, UPDATE);
if ($class === null) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$item = new $class(); // nosemgrep

if (isset($_POST['add'])) {
    if (!EbiosElement::canEditStudy((int) ($_POST[EbiosElement::STUDY_FK] ?? 0))) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $item->add($_POST);
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
} elseif (isset($_POST['send_to_register']) && $item instanceof EbiosOperationalScenario) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->sendToRegister();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();
