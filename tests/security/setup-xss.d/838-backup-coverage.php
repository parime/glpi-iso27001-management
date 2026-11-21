<?php

/**
 * Issue #838 : couverture des sauvegardes. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

use GlpiPlugin\Grcmanager\Install\BackupCoverageTables;

// Machine sauvegardée, travail et type piégés (page de la couverture des sauvegardes).
$DB->update(BackupCoverageTables::HOSTS, [
    'host' => mb_substr($canary, 0, 255), 'job' => mb_substr($canary, 0, 255),
], ['host' => 'Hote sauvegarde securite A']);
$DB->update(BackupCoverageTables::JOBS, [
    'name' => mb_substr($canary, 0, 255), 'type' => mb_substr($canary, 0, 64),
], ['name' => 'Travail sauvegarde securite A']);
// Mesure A.8.13 (preuve de la couverture des sauvegardes).
$out['control_a813'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.13'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
