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

namespace GlpiPlugin\Grcmanager\Config\Block;

use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\MailDomainSecurity;
use GlpiPlugin\Grcmanager\Services\MailDomain\SystemDnsReader;
use Session;

/**
 * Issue #1042 : serveur DNS de la question DNSSEC des contrôles de messagerie, déplacé de
 * front/maildomainsecurity.php. Droits d'origine : lecture du plugin pour voir, modification pour
 * enregistrer ou tester.
 */
final class MailDnsConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-mail-dns-config';

    /** Zone signée interrogée par « Tester » : sa réponse DS montre que le serveur relaie DNSSEC. */
    private const TEST_ZONE = 'org';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 70;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_mail_dns_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return MailDomainSecurity::canView();
    }

    public static function data(): array
    {
        return [
            'mail_dns' => [
                'server'   => MailDomainSecurity::dnsServer(),
                'page_url' => MailDomainSecurity::getSearchURL(),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_dns_server']) && !isset($_POST['test_dns_server'])) {
            return false;
        }
        Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
        if (isset($_POST['save_dns_server'])) {
            if (MailDomainSecurity::setDnsServer((string) ($_POST['dns_server'] ?? '')) === null) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'Not saved: enter the IP address of a DNS server, for example 192.0.2.53, or leave empty.',
                    'grcmanager'
                )), false, ERROR);
            } else {
                Session::addMessageAfterRedirect(htmlescape(__('DNS server saved.', 'grcmanager')));
            }
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/maildomainsecurity.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_dns_server'])) {
            Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
            MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab::label());
            self::handle();
        }
    }

    /** Question DNSSEC (enregistrement DS) posée au serveur enregistré, ou à celui du système. */
    private static function test(): void
    {
        if ((new SystemDnsReader(MailDomainSecurity::dnsServer()))->hasDs(self::TEST_ZONE) === true) {
            Session::addMessageAfterRedirect(htmlescape(
                __('The DNS server answers the DNSSEC questions.', 'grcmanager')
            ));

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(__(
            'The DNS server did not answer the DNSSEC question: DNSSEC will show "Unknown". Enter another DNS server.',
            'grcmanager'
        )), false, WARNING);
    }
}
