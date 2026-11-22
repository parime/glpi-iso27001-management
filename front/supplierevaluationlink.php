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
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\Config\Block\SupplierPortalBlock;
use GlpiPlugin\Grcmanager\SupplierEvaluation;
use GlpiPlugin\Grcmanager\SupplierEvaluationLink;
use GlpiPlugin\Grcmanager\SupplierRisk;

// Issue #865, lot 2 : lien sécurisé de réponse du fournisseur, côté GLPI : création et révocation
// d'un lien, relecture puis enregistrement ou rejet des réponses reçues (droit de modifier
// l'évaluation). Le jeton CSRF des formulaires est contrôlé par GLPI.
// Issue #1040 : le réglage (activer ou non) est dans Configuration > Plugins > GRC Manager, onglet
// Général ; l'ancien envoi de cette page y mène, enregistré, pendant une version.
SupplierPortalBlock::handleLegacyPost();

Session::checkRight(SupplierEvaluation::$rightname, READ);

$evaluation = new SupplierEvaluation();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0 || !$evaluation->getFromDB($id)) {
    Html::redirect(SupplierRisk::getSearchURL());
}
$evaluation->check($id, UPDATE);
if (!SupplierEvaluationLink::canManage($evaluation)) {
    throw new AccessDeniedHttpException();
}
$back = SupplierEvaluation::getFormURLWithID($id) . '&forcetab=' . rawurlencode(SupplierEvaluationLink::class . '$1');

if (isset($_POST['create_link'])) {
    if (!SupplierEvaluationLink::isEnabled()) {
        throw new AccessDeniedHttpException();
    }
    SupplierEvaluationLink::create($evaluation, $_POST['days'] ?? null, $_POST['max_uses'] ?? null);
    Session::addMessageAfterRedirect(htmlescape(
        __('Secure link created: copy it now, it will not be shown again.', 'grcmanager')
    ));
    Html::redirect($back);
}

if (isset($_POST['revoke_link'])) {
    if (SupplierEvaluationLink::revoke($evaluation, (int) $_POST['link_id'])) {
        Session::addMessageAfterRedirect(htmlescape(
            __('The link was revoked: it no longer opens the questionnaire.', 'grcmanager')
        ));
    }
    Html::redirect($back);
}

$draft = SupplierEvaluationLink::draft($evaluation, (int) ($_POST['draft_id'] ?? $_GET['draft'] ?? 0));
if ($draft === null) {
    if (isset($_POST['confirm']) || isset($_POST['cancel'])) {
        throw new BadRequestHttpException();
    }
    Html::redirect($back);
}

if (isset($_POST['cancel'])) {
    SupplierEvaluationLink::close($evaluation, $draft, SupplierEvaluationLink::DRAFT_DISCARDED);
    Session::addMessageAfterRedirect(htmlescape(__('The answers received were discarded.', 'grcmanager')));
    Html::redirect($back);
}

if (isset($_POST['confirm'])) {
    $result = SupplierEvaluationLink::apply($evaluation, $draft);
    if ($result === null) {
        throw new BadRequestHttpException();
    }
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('%1$d answer(s) saved from the secure link, %2$d document(s) attached.', 'grcmanager'),
        $result['answers'],
        $result['documents']
    )));
    Html::redirect(SupplierEvaluation::getFormURLWithID($id));
}

\GlpiPlugin\Grcmanager\Menu::header(SupplierRisk::class, SupplierEvaluationLink::getTypeName(1));
\GlpiPlugin\Grcmanager\PageHelp::show('supplierevaluationlink');
TemplateRenderer::getInstance()->display(
    '@grcmanager/supplier_evaluation_import.html.twig',
    SupplierEvaluationLink::previewData($evaluation, $draft)
);
Html::footer();
