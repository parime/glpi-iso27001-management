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
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules;

/**
 * Issue #773 : onglet « Programme et indépendance » d'un audit interne : processus audités,
 * justification d'un conflit d'indépendance, remise du rapport à la direction. Il faut pouvoir
 * modifier l'audit (droit et entité, check() du cœur).
 */
// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

Session::checkRight(Audit::$rightname, UPDATE);

$audit = new Audit();
$audit->check((int) ($_POST['plugin_grcmanager_audits_id'] ?? 0), UPDATE);
if (!AuditProgramme::isInternal($audit)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['save_processes'])) {
    AuditProgramme::setProcesses($audit, (array) ($_POST['processes'] ?? []));
    $open = array_filter(AuditProgramme::conflicts($audit), static fn (array $c): bool => $c['waiver'] === null);
    if ($open !== []) {
        Session::addMessageAfterRedirect(
            __(
                'An auditor performs or approves an audited process: justify each conflict below, or change the '
                    . 'auditor, before starting or closing the audit.',
                'grcmanager'
            ),
            false,
            WARNING
        );
    }
} elseif (isset($_POST['justify'])) {
    if (
        !AuditProgramme::justify(
            $audit,
            (int) ($_POST['users_id'] ?? 0),
            (int) ($_POST['process_id'] ?? 0),
            $_POST['justification'] ?? ''
        )
    ) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('Enter a justification of at least %d characters for this conflict.', 'grcmanager'),
                ProgrammeRules::MIN_JUSTIFICATION_LENGTH
            ),
            false,
            ERROR
        );
    }
} elseif (isset($_POST['save_delivery'])) {
    if (
        !AuditProgramme::saveDelivery(
            $audit,
            $_POST['delivery_date'] ?? '',
            (int) ($_POST['users_id_recipient'] ?? 0),
            $_POST['delivery_comment'] ?? ''
        )
    ) {
        Session::addMessageAfterRedirect(
            __('Enter the delivery date and a recipient who has a profile in the entity of the audit.', 'grcmanager'),
            false,
            ERROR
        );
    }
}

Html::back();
