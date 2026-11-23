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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Section\AccessReviewSection;
use GlpiPlugin\Grcmanager\PrivilegedVault;
use GlpiPlugin\Grcmanager\Services\Access\BitwardenPublicClient;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultConfig;
use Session;
use User;

/**
 * Issue #859 : connexion à l'API publique d'organisation de Bitwarden (serveur, identifiant et secret
 * de la clé d'API de l'organisation, jamais réaffiché), essai de la connexion. La page d'exploitation
 * (Accès et comptes > Comptes à privilèges) garde la lecture sur clic et les imports. Droits : lecture
 * du plugin et des utilisateurs pour voir, modification du plugin pour enregistrer.
 */
final class BitwardenConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-bitwarden-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 82;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_bitwarden_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return PrivilegedVault::canView();
    }

    public static function data(): array
    {
        return [
            'bitwarden' => [
                // Le secret n'est jamais renvoyé, seulement sa présence.
                'server'        => PrivilegedVaultConfig::server(),
                'servers'       => self::servers(),
                'url'           => PrivilegedVaultConfig::rawUrl(),
                'client_id'     => PrivilegedVaultConfig::clientId(),
                'has_secret'    => PrivilegedVaultConfig::hasSecret(),
                'configured'    => PrivilegedVaultConfig::isConfigured(),
                'allow_private' => Http::allowsPrivateNetworks(),
                // Chemin cité en texte : la configuration ne mène pas à l'exploitation (charte, section 9).
                'page_path'     => sprintf(
                    '%s > %s > %s',
                    Menu::getMenuName(),
                    AccessReviewSection::title(),
                    PrivilegedVault::getTypeName(2)
                ),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_bitwarden']) && !isset($_POST['test_bitwarden'])) {
            return false;
        }
        Session::checkRight(PrivilegedVault::RIGHTNAME, UPDATE);
        Session::checkRight(User::$rightname, READ);
        if (isset($_POST['save_bitwarden'])) {
            self::save();
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    private static function save(): void
    {
        $saved = PrivilegedVaultConfig::save(
            (string) ($_POST['bitwarden_server'] ?? ''),
            (string) ($_POST['bitwarden_url'] ?? ''),
            (string) ($_POST['bitwarden_client_id'] ?? ''),
            (string) ($_POST['bitwarden_secret'] ?? ''),
            !empty($_POST['bitwarden_clear_secret'])
        );
        if (!$saved['saved']) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Not saved: the client ID must look like organization.<identifier>, and the address of a '
                    . 'self-hosted server must start with https:// (or http:// when internal addresses are '
                    . 'allowed), without user name, password or parameters.',
                'grcmanager'
            )), false, ERROR);

            return;
        }
        if ($saved['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $saved['changes']]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Bitwarden settings saved.', 'grcmanager')));
    }

    /** Essai de la connexion enregistrée : un jeton est demandé, rien n'est lu ni enregistré. */
    private static function test(): void
    {
        $error = PrivilegedVaultConfig::client()->test();
        if ($error !== '') {
            Session::addMessageAfterRedirect(htmlescape(PrivilegedVault::apiErrorMessage($error)), false, ERROR);

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(__('Connection to Bitwarden succeeded.', 'grcmanager')));
    }

    /** @return array<string, string> serveurs proposés */
    public static function servers(): array
    {
        return [
            BitwardenPublicClient::SERVER_US   => __('Bitwarden cloud (United States)', 'grcmanager'),
            BitwardenPublicClient::SERVER_EU   => __('Bitwarden cloud (European Union)', 'grcmanager'),
            BitwardenPublicClient::SERVER_SELF => __('Self-hosted Bitwarden server', 'grcmanager'),
        ];
    }
}
