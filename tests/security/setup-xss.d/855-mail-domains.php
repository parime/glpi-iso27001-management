<?php

/**
 * Issue #855 : domaine, commentaire et enregistrement SPF piégés (page de la sécurité de la messagerie,
 * preuve A.5.14). Fragment inclus par tests/security/setup.php xss.
 */

use GlpiPlugin\Grcmanager\Install\MailDomainTables;
use GlpiPlugin\Grcmanager\Services\MailDomain\MailDomainRules;

$DB->update(MailDomainTables::DOMAINS, [
    'name'           => mb_substr($canary, 0, 253),
    'comment'        => mb_substr($canary, 0, 255),
    'dkim_selectors' => mb_substr($canary, 0, 700),
    'result'         => (string) json_encode(MailDomainRules::analyse([
        'resolved' => true, 'mx' => [$canary], 'txt' => ['v=spf1 ' . $canary . ' -all'],
        'dmarc' => ['v=DMARC1; p=reject; rua=' . $canary], 'mta_sts' => [], 'tls_rpt' => [],
        'dkim' => [$canary => ['v=DKIM1; p=x']], 'dnssec' => null,
    ])),
], ['name' => 'securite-a.example']);
// Mesure A.5.14 (preuve de la sécurité de la messagerie).
$out['control_a514'] = (int) ($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.5.14'], 'LIMIT' => 1,
])->current()['id'] ?? 0);
