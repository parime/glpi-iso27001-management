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

use Computer;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamClient;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig;
use Html;
use Session;

/**
 * Issue #1042 : connexion à Veeam Backup & Replication (adresse, compte de lecture, mot de passe
 * chiffré jamais réaffiché, révision de l'API), déplacée de front/backupcoverage.php. La page
 * d'exploitation garde les résultats et le bouton « Lire maintenant depuis Veeam ». Droits d'origine :
 * lecture du plugin et des ordinateurs pour voir, modification du plugin pour enregistrer.
 */
final class VeeamConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-veeam-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 60;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_veeam_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return BackupCoverage::canView();
    }

    public static function data(): array
    {
        $lastRead = BackupCoverage::lastVeeamRead();

        return [
            'veeam' => [
                // Le mot de passe n'est jamais renvoyé, seulement sa présence.
                'url'          => VeeamConfig::rawUrl(),
                'username'     => VeeamConfig::username(),
                'api_version'  => VeeamConfig::apiVersion(),
                'api_versions' => VeeamClient::API_VERSIONS,
                'has_password' => VeeamConfig::hasPassword(),
                'configured'   => VeeamConfig::isConfigured(),
                'lookback'     => VeeamClient::LOOKBACK_DAYS,
                'last_read'    => $lastRead !== '' ? (string) Html::convDateTime($lastRead) : '',
                'page_url'     => BackupCoverage::getSearchURL(),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_veeam']) && !isset($_POST['test_veeam'])) {
            return false;
        }
        Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);
        if (isset($_POST['save_veeam'])) {
            self::save();
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/backupcoverage.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_veeam'])) {
            Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
            MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab::label());
            self::handle();
        }
    }

    private static function save(): void
    {
        $saved = VeeamConfig::save(
            (string) ($_POST['veeam_url'] ?? ''),
            (string) ($_POST['veeam_username'] ?? ''),
            (string) ($_POST['veeam_password'] ?? ''),
            (string) ($_POST['veeam_api_version'] ?? ''),
            !empty($_POST['veeam_clear_password'])
        );
        if (!$saved['saved']) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Not saved: the address must start with https:// (for example https://veeam.example.test:9419) and '
                    . 'contain no user name or password.',
                'grcmanager'
            )), false, ERROR);

            return;
        }
        if ($saved['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $saved['changes']]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Veeam settings saved.', 'grcmanager')));
    }

    /** Essai de la connexion enregistrée : demande d'un jeton, rien n'est lu ni enregistré. */
    private static function test(): void
    {
        $error = VeeamConfig::client()->test();
        if ($error !== '') {
            Session::addMessageAfterRedirect(htmlescape(BackupCoverage::veeamErrorMessage($error)), false, ERROR);

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(__('Connection to Veeam succeeded.', 'grcmanager')));
    }
}
