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
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\AuditChecklist;
use GlpiPlugin\Grcmanager\AuditChecklistTemplate;

/**
 * Issue #606 : actions de l'onglet « Grille d'audit » d'un audit. Mêmes règles d'accès que les
 * documents de l'audit : droit de modifier l'audit ET équipe de l'audit ou groupe RSSI ; la grille
 * ne change plus une fois l'audit terminé ou annulé (seul l'enregistrement comme modèle reste
 * possible). Supprimer un modèle : droit de modifier les audits dans l'entité du modèle.
 */
Session::checkLoginUser();

$audit = AuditChecklist::loadAudit((int) ($_POST['plugin_grcmanager_audits_id'] ?? 0));
if ($audit === null) {
    throw new BadRequestHttpException();
}
$audit->check($audit->getID(), READ);

$canEdit = AuditChecklist::canEditFor($audit);

if (isset($_POST['delete_template'])) {
    $templateId = (int) ($_POST['templates_id'] ?? 0);
    if (!AuditChecklistTemplate::canDelete($templateId)) {
        throw new AccessDeniedHttpException();
    }
    AuditChecklistTemplate::delete($templateId);
    Session::addMessageAfterRedirect(__('Checklist template deleted.', 'grcmanager'));
} elseif (isset($_POST['save_template'])) {
    if (!AuditChecklist::canManageFor($audit)) {
        throw new AccessDeniedHttpException();
    }
    if (AuditChecklistTemplate::saveFromAudit($audit, $_POST['template_name'] ?? '') > 0) {
        Session::addMessageAfterRedirect(__('Checklist template saved.', 'grcmanager'));
    }
} elseif (!$canEdit) {
    throw new AccessDeniedHttpException();
} elseif (isset($_POST['add_refs'])) {
    $count = AuditChecklist::addRequirements($audit, array_map('strval', (array) ($_POST['refs'] ?? [])));
    Session::addMessageAfterRedirect(
        AuditChecklist::addedMessage($count),
        false,
        $count > 0 ? INFO : WARNING
    );
} elseif (isset($_POST['add_free'])) {
    if (AuditChecklist::addFreeLine($audit, $_POST['code'] ?? '', $_POST['label'] ?? '', $_POST['question'] ?? '')) {
        Session::addMessageAfterRedirect(AuditChecklist::addedMessage(1));
    }
} elseif (isset($_POST['update'])) {
    if (!AuditChecklist::updateLine($audit, (int) ($_POST['id'] ?? 0), $_POST)) {
        throw new BadRequestHttpException();
    }
    Session::addMessageAfterRedirect(__('Checklist line saved.', 'grcmanager'));
} elseif (isset($_POST['purge'])) {
    if (!AuditChecklist::deleteLine($audit, (int) ($_POST['id'] ?? 0))) {
        throw new BadRequestHttpException();
    }
} elseif (isset($_POST['apply_template'])) {
    $result = AuditChecklistTemplate::applyToAudit((int) ($_POST['templates_id'] ?? 0), $audit);
    Session::addMessageAfterRedirect(
        AuditChecklist::addedMessage($result['added'])
        . ($result['skipped'] > 0
            ? ' ' . sprintf(
                _n(
                    '%d line of the template was already in the checklist or is no longer offered.',
                    '%d lines of the template were already in the checklist or are no longer offered.',
                    $result['skipped'],
                    'grcmanager'
                ),
                $result['skipped']
            )
            : ''),
        false,
        $result['added'] > 0 ? INFO : WARNING
    );
} else {
    throw new BadRequestHttpException();
}

// Retour sur la fiche de l'audit : GLPI y rouvre le dernier onglet affiché (« Grille d'audit »).
Html::back();
