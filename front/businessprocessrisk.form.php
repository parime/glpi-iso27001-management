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

// Issue #377 : risques liés à un processus métier (onglet « Risques »). Il faut pouvoir modifier le
// processus (droit de la continuité et entité) et lire le registre des risques (droit des registres
// sensibles) ; proposer un risque pour un élément trop lent exige en plus de pouvoir créer un risque
// et de lire l'élément.
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, UPDATE);
Session::checkRight(\GlpiPlugin\Grcmanager\Risk::$rightname, READ);
$process = new \GlpiPlugin\Grcmanager\BusinessProcess();
$link = new \GlpiPlugin\Grcmanager\BusinessProcessRisk();

if (isset($_POST['add'])) {
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    $link->add([
        'plugin_grcmanager_businessprocesses_id' => (int) $process->getID(),
        'plugin_grcmanager_risks_id'             => (int) ($_POST['plugin_grcmanager_risks_id'] ?? 0),
        'comment'                                => (string) ($_POST['comment'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    if (!$link->getFromDB((int) ($_POST['id'] ?? 0))) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $process->check((int) $link->fields['plugin_grcmanager_businessprocesses_id'], UPDATE);
    $link->delete(['id' => (int) $link->getID()], true);
} elseif (isset($_POST['create_risk'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Risk::$rightname, CREATE);
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    // L'élément doit être une dépendance lisible du processus et ne pas tenir ses objectifs.
    $key = \GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph::key(
        (string) ($_POST['itemtype'] ?? ''),
        (int) ($_POST['items_id'] ?? 0)
    );
    $issues = [];
    foreach (\GlpiPlugin\Grcmanager\BusinessProcess::analyze($process)['dependencies'] as $dependency) {
        if ($dependency['key'] === $key) {
            $issues = $dependency['issues'];
        }
    }
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    if ($issues === [] || !in_array($itemtype, \GlpiPlugin\Grcmanager\Risk::getLinkableItemtypes(), true)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    // $itemtype est borné par la liste blanche des actifs liables juste avant l'instanciation.
    // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
    $item = new $itemtype();
    if (!$item->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$item->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $riskId = \GlpiPlugin\Grcmanager\BusinessProcessRisk::createRecoveryRisk($process, $item, $issues);
    if ($riskId > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Risk linked to the process, to be assessed in the register.',
            'grcmanager'
        )));
        Html::redirect(\GlpiPlugin\Grcmanager\Risk::getFormURLWithID($riskId));
    }
}

Html::back();
