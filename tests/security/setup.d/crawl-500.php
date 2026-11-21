<?php

/**
 * Parcours avant publication 2.80.0 : fragment inclus par tests/security/setup.php (jeu de données).
 */

// Un événement du journal d'audit sur un type qui n'est pas un objet GLPI (AdAttackPath, sans
// getTypeName()) : front/auditlog.php doit rester lisible (200) au lieu d'une erreur 500.
if (countElementsInTable('glpi_plugin_grcmanager_auditlogs', ['itemtype' => \GlpiPlugin\Grcmanager\AdAttackPath::class]) === 0) {
    \GlpiPlugin\Grcmanager\AuditLog::record('import', \GlpiPlugin\Grcmanager\AdAttackPath::class, 1, 0, 'success', [
        'domain' => 'exemple.test',
    ]);
}
