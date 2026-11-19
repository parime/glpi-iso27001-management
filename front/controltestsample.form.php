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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\ControlTestSample;

/**
 * Issue #607 : échantillon d'un test d'efficacité. Consultation avec le droit de lire la mesure ;
 * tirage, résultats par élément, enregistrement du test et suppression d'un échantillon ouvert
 * avec le droit de la modifier.
 */
Session::checkRight(Control::$rightname, READ);

if (isset($_POST['draw'])) {
    $controlsId = (int) ($_POST['plugin_grcmanager_controls_id'] ?? 0);
    if (!ControlTest::canEditControl($controlsId)) {
        throw new AccessDeniedHttpException();
    }
    $control = new Control();
    $control->getFromDB($controlsId);
    $sampleId = ControlTestSample::draw($control, $_POST);
    Html::redirect($sampleId > 0 ? ControlTestSample::getPageURL($sampleId) : ControlTest::getControlTabURL($controlsId));
}

$sampleId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$sample = ControlTestSample::getSample($sampleId);
if ($sample === null) {
    throw new NotFoundHttpException();
}
$control = ControlTestSample::loadControl($sample);
if ($control === null) {
    throw new AccessDeniedHttpException();
}
$controlsId = (int) $control->getID();

if (isset($_POST['save_results']) || isset($_POST['record']) || isset($_POST['purge'])) {
    if (!ControlTest::canEditControl($controlsId)) {
        throw new AccessDeniedHttpException();
    }
    if (isset($_POST['purge'])) {
        ControlTestSample::purge($sample);
        Html::redirect(ControlTest::getControlTabURL($controlsId));
    }
    if (!ControlTestSample::isOpen($sample)) {
        Session::addMessageAfterRedirect(
            htmlescape(__('This sample is already recorded as a test.', 'grcmanager')),
            false,
            ERROR
        );
        Html::redirect(ControlTestSample::getPageURL($sampleId));
    }
    ControlTestSample::saveResults($sample, $_POST['results'] ?? []);
    if (isset($_POST['record'])) {
        // Preuve : un document joint à cette mesure et lisible (revérifié par ControlTest).
        $testsId = ControlTestSample::record($sample, $_POST);
        if ($testsId > 0) {
            Html::redirect(ControlTest::getControlTabURL($controlsId));
        }
    }
    Html::redirect(ControlTestSample::getPageURL($sampleId));
}

Html::header(
    ControlTestSample::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    Control::class,
    ControlTest::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, ControlTest::class);
ControlTestSample::showPage($sample, $control);
Html::footer();
