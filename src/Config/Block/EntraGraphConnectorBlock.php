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
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab;
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphConfig;
use Session;
use User;

/**
 * Issue #1043 : application Microsoft Entra ID / Microsoft Graph (locataire, identifiant, secret
 * chiffré jamais réaffiché), déplacée de front/mfacoverage.php. Elle sert à la couverture MFA et aux
 * droits SharePoint, OneDrive et Teams (front/shareaccess.php), qui gardent leurs boutons de lecture.
 * Droits d'origine : lecture du plugin et des utilisateurs pour voir, modification du plugin pour
 * enregistrer ou tester.
 */
final class EntraGraphConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-entra-graph-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 80;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_entra_graph_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return MfaCoverage::canView();
    }

    public static function data(): array
    {
        $imports = MfaCoverage::imports();

        return [
            'entra_graph' => [
                // Le secret n'est jamais renvoyé, seulement sa présence.
                'tenant'     => EntraGraphConfig::tenant(),
                'client_id'  => EntraGraphConfig::clientId(),
                'has_secret' => EntraGraphConfig::hasSecret(),
                'configured' => EntraGraphConfig::isConfigured(),
                'last_read'  => (string) ($imports['entra']['date'] ?? ''),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_mfa_graph']) && !isset($_POST['test_mfa_graph'])) {
            return false;
        }
        Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
        Session::checkRight(User::$rightname, READ);
        if (isset($_POST['save_mfa_graph'])) {
            self::save();
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/mfacoverage.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_mfa_graph'])) {
            Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
            MovedSettings::notice(ConnectorsTab::label());
            self::handle();
        }
    }

    private static function save(): void
    {
        $saved = EntraGraphConfig::save(
            (string) ($_POST['mfa_graph_tenant'] ?? ''),
            (string) ($_POST['mfa_graph_client_id'] ?? ''),
            (string) ($_POST['mfa_graph_secret'] ?? ''),
            !empty($_POST['mfa_graph_clear_secret'])
        );
        if (!$saved['saved']) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Not saved: the tenant must be a domain name or an ID, and the application ID an ID such as '
                    . '00000000-0000-0000-0000-000000000000.',
                'grcmanager'
            )), false, ERROR);

            return;
        }
        if ($saved['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $saved['changes']]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Microsoft Graph settings saved.', 'grcmanager')));
    }

    /** Essai de la connexion enregistrée : demande d'un jeton, rien n'est lu ni enregistré. */
    private static function test(): void
    {
        $error = EntraGraphConfig::client()->test();
        if ($error !== '') {
            Session::addMessageAfterRedirect(htmlescape(MfaCoverage::graphErrorMessage($error)), false, ERROR);

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(__('Connection to Microsoft Graph succeeded.', 'grcmanager')));
    }
}
