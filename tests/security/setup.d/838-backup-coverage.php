<?php

/**
 * Issue #838 : couverture des sauvegardes. Fragment inclus par tests/security/setup.php (jeu de données).
 */

use GlpiPlugin\Grcmanager\Install\BackupCoverageTables;

// Machine sauvegardée inconnue de GLPI et travail lu dans Veeam, par entité.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $backupHost = ['entities_id' => $entity, 'source' => 'acronis', 'host' => 'Hote sauvegarde securite ' . $suffix];
    if (countElementsInTable(BackupCoverageTables::HOSTS, $backupHost) === 0) {
        $DB->insert(BackupCoverageTables::HOSTS, $backupHost + [
            'ip' => '192.0.2.98', 'job' => 'Plan securite', 'last_result' => 'success',
            'last_success' => date('Y-m-d H:i:s'),
        ]);
    }
    $backupJob = ['entities_id' => $entity, 'source' => 'veeam', 'name' => 'Travail sauvegarde securite ' . $suffix];
    if (countElementsInTable(BackupCoverageTables::JOBS, $backupJob) === 0) {
        $DB->insert(BackupCoverageTables::JOBS, $backupJob + ['type' => 'Backup', 'last_result' => 'failed']);
    }
}
