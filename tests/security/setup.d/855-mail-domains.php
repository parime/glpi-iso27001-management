<?php

/**
 * Issue #855 : sécurité de la messagerie et des domaines. Fragment inclus par tests/security/setup.php
 * (jeu de données) : un domaine fictif déjà lu par entité (aucune requête DNS).
 */

use GlpiPlugin\Grcmanager\Install\MailDomainTables;

foreach (['a' => $a, 'b' => $b] as $suffix => $entity) {
    $mailDomain = ['entities_id' => $entity, 'name' => 'securite-' . $suffix . '.example'];
    if (countElementsInTable(MailDomainTables::DOMAINS, $mailDomain) === 0) {
        $DB->insert(MailDomainTables::DOMAINS, $mailDomain + [
            'dkim_selectors' => 'selector1',
            'domain_expiry'  => date('Y-m-d', strtotime('+10 days')),
            'comment'        => 'Domaine messagerie securite ' . strtoupper($suffix),
            'last_check'     => date('Y-m-d H:i:s'),
            'result'         => (string) json_encode(
                \GlpiPlugin\Grcmanager\Services\MailDomain\MailDomainRules::analyse([
                    'resolved' => true, 'mx' => ['mx.securite-' . $suffix . '.example'], 'txt' => ['v=spf1 +all'],
                    'dmarc' => ['v=DMARC1; p=none'], 'mta_sts' => [], 'tls_rpt' => [],
                    'dkim' => ['selector1' => []], 'dnssec' => false,
                ])
            ),
            'date_creation'  => date('Y-m-d H:i:s'),
            'date_mod'       => date('Y-m-d H:i:s'),
        ]);
    }
}
