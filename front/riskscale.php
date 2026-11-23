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
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\RiskScaleChange;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;

// Issue #706 : changement de taille de la matrice des risques (4x4 ou 5x5). L'écran réécrit les
// registres des risques et des risques fournisseurs : droit de modifier la configuration du plugin
// ET droit de modifier les registres sensibles. Jeton CSRF des formulaires vérifié par GLPI.
Session::checkRight(Installer::RIGHT_NAME, UPDATE);
Session::checkRight(Installer::SENSITIVE_RIGHT, UPDATE);

// Issue #1046 : écran de réglage, rangé dans Configuration > Plugins (fil d'Ariane de la page de
// configuration) ; le retour mène à l'onglet Risques, sur le bloc de la matrice.
$configUrl = \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl('grc-risk-matrix-config');
$from = RiskMatrixConfig::size();
$to = (int) ($_POST['size'] ?? $_GET['size'] ?? 0);
// Issue #1114 : sans taille demandée (ouverture directe), pas d'écran : requête invalide, au lieu
// d'un renvoi vers la configuration.
if (!isset($_POST['size']) && !isset($_GET['size'])) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
if (!in_array($to, RiskScale::SIZES, true) || $to === $from) {
    Session::addMessageAfterRedirect(
        htmlescape(__('Choose a matrix size different from the current one.', 'grcmanager')),
        false,
        WARNING
    );
    Html::redirect($configUrl);
}

$posted = $_POST['mapping'] ?? [];
// Correspondance proposée d'après la grille actuelle (celle qui change le moins de niveaux), puis
// choix de l'administrateur, ramené à une énumération fermée.
$mapping = RiskScale::sanitizeMapping(is_array($posted) ? $posted : [], $from, $to, RiskMatrixConfig::load());

if (isset($_POST['apply'])) {
    if (empty($_POST['confirm'])) {
        Session::addMessageAfterRedirect(
            htmlescape(__('Tick the box to confirm that you have checked the correspondence.', 'grcmanager')),
            false,
            ERROR
        );
        Html::redirect(RiskScaleChange::getPageURL() . '?size=' . $to);
    }
    $changed = RiskScaleChange::apply($to, $mapping);
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('The risk matrix is now %1$s. Risks updated: %2$d (see the history of each risk).', 'grcmanager'),
        RiskScaleChange::sizeName($to),
        (int) $changed
    )));
    Html::redirect($configUrl);
}

$step = isset($_POST['preview']) ? 'confirm' : 'mapping';

$title = __('Change the risk matrix size', 'grcmanager');
Html::header($title, $_SERVER['PHP_SELF'], 'config', 'plugin');
TemplateRenderer::getInstance()->display('@grcmanager/risk_scale_change.html.twig', [
    'title'      => $title,
    'step'       => $step,
    'page_url'   => RiskScaleChange::getPageURL(),
    'config_url' => $configUrl,
    'from_name'  => RiskScaleChange::sizeName($from),
    'to_name'    => RiskScaleChange::sizeName($to),
    'preview'    => RiskScaleChange::preview($to, $mapping),
]);
Html::footer();
