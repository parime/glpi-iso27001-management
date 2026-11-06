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

// Issue #371 : onglet « Intégrité et preuves » de l'incident : vérification des preuves.
$incident = new PluginGrcmanagerSecurityIncident();
$incident->check((int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0), UPDATE);

if (isset($_POST['verify'])) {
    $results = \GlpiPlugin\Grcmanager\SecurityIncidentEvidence::verifyIncident((int) $incident->getID());
    $problems = count(array_filter($results, static fn (string $status): bool => $status !== 'ok'));
    Session::addMessageAfterRedirect(
        $problems === 0
            ? sprintf(__('%d evidence(s) checked: all intact.', 'grcmanager'), count($results))
            : sprintf(
                __('%d evidence(s) modified or missing: see the Integrity and evidence tab.', 'grcmanager'),
                $problems
            ),
        false,
        $problems === 0 ? INFO : ERROR
    );
}

Html::back();
