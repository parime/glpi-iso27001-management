<?php

/**
 * Issue #622 : fiche « usage autorisé » piégée sur l'application de A (onglet de l'application, page
 * « Applications approuvées », onglet « Applications autorisées » des préférences). Fragment inclus
 * par tests/security/setup.php (jeu XSS).
 */

$DB->update('glpi_plugin_grcmanager_approvedapplications', [
    'allowed_uses' => $canary, 'restrictions' => $canary, 'user_services' => $canary, 'comment' => $canary,
], ['appliances_id' => (int) ($ids['appliance_A'] ?? 0)]);
