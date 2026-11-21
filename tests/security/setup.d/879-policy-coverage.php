<?php

/**
 * Issue #879 : textes de la bibliotheque des politiques relies aux mesures. Fragment inclus par
 * tests/security/setup.php (jeu de donnees).
 */

// Politique de A reliee a la mesure de A, politique de B a la mesure de B (section nommee).
foreach (['A' => 'control', 'B' => 'control_B'] as $suffix => $controlKey) {
    $link = [
        'plugin_grcmanager_policies_id' => (int) $ids['policy_' . $suffix],
        'plugin_grcmanager_controls_id' => (int) $ids[$controlKey],
        'section'                       => 'Section couverture 879 ' . $suffix,
    ];
    if (countElementsInTable('glpi_plugin_grcmanager_policies_controls', $link) === 0) {
        $DB->insert('glpi_plugin_grcmanager_policies_controls', $link + ['users_id' => 2, 'date_creation' => date('Y-m-d H:i:s')]);
    }
}
