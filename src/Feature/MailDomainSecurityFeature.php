<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Feature;

use GlpiPlugin\Grcmanager\MailDomainSecurity;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #855 : sécurité de la messagerie et des domaines. Aide de la page, preuve sur les mesures
 * A.5.14 et A.8.21 et source de KRI, déclarées ici plutôt que dans les listes communes.
 */
final class MailDomainSecurityFeature implements
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'maildomainsecurity' => [
                'purpose'   => __(
                    'Shows whether the e-mail domains of the organisation are protected against spoofing and '
                        . 'phishing: who may send e-mails in their name (SPF), signature of the e-mails (DKIM), what '
                        . 'receiving servers do with fake e-mails (DMARC), encryption between mail servers '
                        . '(MTA-STS, TLS-RPT), signature of the DNS (DNSSEC), and the expiry of the domains and of '
                        . 'the certificate of the mail servers.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enter the domains of the organisation, with the DKIM selectors given by your e-mail service '
                        . 'and the expiry dates shown by the registrar, then click "Check now". The plugin only '
                        . 'reads public DNS records: it sends no e-mail, connects to no mail server and changes '
                        . 'nothing. Fix the gaps with your DNS host, then check again and record the result as an '
                        . 'effectiveness test of the control.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.14 and A.8.21', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Domaines lus dont la politique DMARC est absente, invalide ou en « none ».
            'mail_domains_without_dmarc' => [
                'label'     => __('Mail domains without an enforced DMARC policy (%)', 'grcmanager'),
                'unit'      => '%',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => [],
                'global'    => false,
                'compute'   => [MailDomainSecurity::class, 'domainsWithoutDmarcEnforced'],
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return MailDomainSecurity::getTypeName();
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_mail_domain_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return MailDomainSecurity::evidence($code);
    }
}
