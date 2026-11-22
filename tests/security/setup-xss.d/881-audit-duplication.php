<?php

/**
 * Issue #881 : audit piege prepare a partir de l'audit piege (lien vers l'audit d'origine et liste
 * des audits prepares). Fragment inclus par tests/security/setup.php xss.
 */

$out['audit_next'] = $insert('audits', [
    'title' => $canary, 'scope' => $canary, 'status' => 'planned', 'planned_date' => date('Y-m-d'),
    'audit_type' => 'surveillance_2', 'lead_auditor' => $canary,
    'plugin_grcmanager_audits_id_source' => (int) $out['audit'],
]);
