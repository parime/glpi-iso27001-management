<?php

/**
 * Issue #843, lot 3 : santé détaillée des serveurs. Fragment inclus par tests/security/setup.php
 * (jeu de données), après 843-server-management.php (ordre alphabétique).
 */

// Issue #843, lot 3 : un composant et un événement du journal par contrôleur (A et B, voir
// 843-server-management.php), affichés dans l'onglet « Contrôleur de gestion » de l'ordinateur.
foreach (['A', 'B'] as $suffix) {
    $bmcId = (int) ($ids['bmc_' . $suffix] ?? 0);
    if ($bmcId <= 0) {
        continue;
    }
    $link = ['plugin_grcmanager_bmccontrollers_id' => $bmcId];
    if (countElementsInTable('glpi_plugin_grcmanager_bmchealthcomponents', $link) === 0) {
        $DB->insert('glpi_plugin_grcmanager_bmchealthcomponents', $link + [
            'kind' => 'power', 'name' => 'Alimentation BMC securite ' . $suffix, 'health' => 'Critical',
            'reading' => '800 W', 'date_read' => date('Y-m-d H:i:s'),
        ]);
    }
    if (countElementsInTable('glpi_plugin_grcmanager_bmcevents', $link) === 0) {
        $DB->insert('glpi_plugin_grcmanager_bmcevents', $link + [
            'date_event' => date('Y-m-d H:i:s'), 'severity' => 'Warning',
            'message' => 'Evenement BMC securite ' . $suffix, 'code' => 'Event.Test',
            'date_read' => date('Y-m-d H:i:s'),
        ]);
    }
}
