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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;

include('../../../inc/includes.php');

// Issue #380 : registre des tests d'efficacité des mesures, avec en tête les tests en retard ou
// proches de leur échéance. Mêmes droits que la SoA (lecture des mesures).
Session::checkRight(ControlTest::RIGHTNAME, READ);

Html::header(
    ControlTest::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    Control::class,
    ControlTest::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, ControlTest::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('controltest');

$upcoming = [];
foreach (ControlTest::getSchedule() as $entry) {
    // Seulement les échéances dépassées ou proches : les autres sont dans le calendrier.
    $status = ControlTestRules::dueStatus($entry['next'], date('Y-m-d'));
    if (!in_array($status, [ControlTestRules::DUE_OVERDUE, ControlTestRules::DUE_SOON], true)) {
        continue;
    }
    [$class, $label] = ControlTest::dueInfo($entry['next']);
    $code = (string) $entry['control']['code'];
    $upcoming[] = [
        'code'        => $code,
        'title'       => Control::getControlTitle($code),
        'url'         => ControlTest::getControlTabURL((int) $entry['control']['id']),
        'next'        => $entry['next'],
        'due_class'   => $class,
        'due_label'   => $label,
        'tester_name' => (int) $entry['control']['users_id_tester'] > 0
            ? getUserName((int) $entry['control']['users_id_tester'])
            : '',
    ];
}
TemplateRenderer::getInstance()->display('@grcmanager/control_test_due.html.twig', ['upcoming' => $upcoming]);

if (!isset($_GET['sort'])) {
    $_GET['sort'] = 2;
    $_GET['order'] = 'DESC';
}
Search::show(ControlTest::class);

Html::footer();
