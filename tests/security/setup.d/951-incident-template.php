<?php

/**
 * Issue #951 : un gabarit d'incident de sécurité, pour les formulaires d'ajout de champs du
 * gabarit. Fragment inclus par tests/security/setup.php (jeu de données).
 */

$templateTable = 'glpi_plugin_grcmanager_secincidenttemplates';
$templateCriteria = ['name' => 'Gabarit incident securite', 'entities_id' => 0];
if (countElementsInTable($templateTable, $templateCriteria) === 0) {
    $DB->insert($templateTable, $templateCriteria + ['is_recursive' => 1]);
}
$templateRow = $DB->request(['FROM' => $templateTable, 'WHERE' => $templateCriteria, 'LIMIT' => 1])->current();
$ids['incident_template'] = (int) ($templateRow['id'] ?? 0);
