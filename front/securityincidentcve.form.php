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

// A tab-embedded child, not a standalone item with its own form page — add/purge only, always
// redirecting back to the parent SecurityIncident's own "CVE" tab.
$item = new PluginGrcmanagerSecurityIncidentCve();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);

    // `cve_ids` (plural, a textarea): one or more identifiers, one per line and/or comma-
    // separated — added one at a time through the same single-row `add()`/`prepareInputForAdd()`
    // this class already validates with, so a malformed line is skipped (with its usual error
    // message) rather than silently accepted or aborting the whole batch. `cve_id` (singular)
    // stays supported for a single identifier, unchanged from before this feature existed.
    if (isset($_POST['cve_ids'])) {
        $uniqueIds = PluginGrcmanagerSecurityIncidentCve::splitIdentifiers((string) $_POST['cve_ids']);

        $added = 0;
        foreach ($uniqueIds as $cveId) {
            $row = new PluginGrcmanagerSecurityIncidentCve();
            if ($row->add(array_merge($_POST, ['cve_id' => $cveId]))) {
                $added++;
            }
        }

        if ($added > 0) {
            Session::addMessageAfterRedirect(
                sprintf(_n('%d CVE reference added.', '%d CVE references added.', $added, 'grcmanager'), $added),
                false,
                INFO
            );
        }
    } else {
        $item->add($_POST);
    }
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, true);
}

Html::back();
