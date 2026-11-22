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

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditDuplication;

/**
 * Issue #881 : onglet « Cycle suivant » d'un audit. Il faut lire l'audit d'origine (droit et
 * entité, check() du cœur) et pouvoir créer un audit dans son entité.
 */
Session::checkRight(Audit::RIGHTNAME, CREATE);

$source = new Audit();
$source->check((int) ($_POST['plugin_grcmanager_audits_id'] ?? 0), READ);
if (!isset($_POST['duplicate']) || !AuditDuplication::canDuplicate($source)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$result = AuditDuplication::duplicate($source, [
    'title'        => $_POST['title'] ?? '',
    'planned_date' => $_POST['planned_date'] ?? '',
    'parts'        => (array) ($_POST['parts'] ?? []),
    'audit_type'   => (string) ($_POST['audit_type'] ?? ''),
    'suppliers_id' => (int) ($_POST['suppliers_id'] ?? 0),
    'lead_auditor' => $_POST['lead_auditor'] ?? '',
]);
if ($result['id'] <= 0) {
    Html::back();
}

Session::addMessageAfterRedirect(__('The audit of the next cycle has been prepared: check its dates and people.', 'grcmanager'));
if ($result['dropped'] > 0) {
    Session::addMessageAfterRedirect(
        sprintf(
            _n(
                '%d person was not copied (account disabled or without a profile in the entity of the audit): choose someone else.',
                '%d people were not copied (account disabled or without a profile in the entity of the audit): choose someone else.',
                $result['dropped'],
                'grcmanager'
            ),
            $result['dropped']
        ),
        false,
        WARNING
    );
}
Html::redirect(Audit::getFormURLWithID($result['id']));
