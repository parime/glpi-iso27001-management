<?php

/**
 * Issue #1185 : alertes Wazuh transformées en incident de sécurité. Fragment inclus par
 * tests/security/setup.php xss (données piégées).
 */

use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\Services\Incident\WazuhAlertGrouping;
use GlpiPlugin\Grcmanager\WazuhAlertImport;

// Le plugin Wazuh n'est pas installé en CI : le groupe est construit comme le ferait la revue, avec
// une règle et un agent piégés sur l'ordinateur de A, puis importé par le même code que la page.
// Relancé : l'alerte déjà importée retrouve son incident.
$wazuhKey = 'xss-1185-alerte-fictive';
$wazuhExisting = $DB->request([
    'SELECT' => ['plugin_grcmanager_securityincidents_id'],
    'FROM'   => SecurityIncidentEdrImport::getAlertsTable(),
    'WHERE'  => ['alert_id' => WazuhAlertGrouping::alertId($wazuhKey)],
    'LIMIT'  => 1,
])->current();
$wazuhIncident = (int) ($wazuhExisting['plugin_grcmanager_securityincidents_id'] ?? 0);
if ($wazuhIncident === 0) {
    $wazuhGroups = WazuhAlertGrouping::group([[
        'id' => 1, 'key' => $wazuhKey, 'computers_id' => (int) $ids['computer_A'],
        'agent' => mb_substr($canary, 0, 200),
        'date' => '2099-01-01 10:00:00', 'level' => 12, 'description' => $canary, 'rule_id' => '1185',
        'techniques' => [],
    ]], [], 0);
    $wazuhIncident = (int) (WazuhAlertImport::importGroup($wazuhGroups[0])['id'] ?? 0);
}
$out['wazuh_incident'] = $wazuhIncident;
