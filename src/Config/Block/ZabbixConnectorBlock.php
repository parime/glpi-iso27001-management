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
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixConfig;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;
use Html;
use Session;

/**
 * Issue #1042 : connexion à Zabbix (adresse, jeton chiffré jamais réaffiché, entité, lecture
 * automatique) et actifs à superviser, déplacés de front/zabbixmonitoring.php. La page d'exploitation
 * garde les résultats et le bouton « Lire maintenant ». Droits d'origine : lecture du plugin et des
 * ordinateurs pour voir, modification du plugin pour enregistrer.
 */
final class ZabbixConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-zabbix-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_zabbix_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return ZabbixMonitoring::canView();
    }

    public static function data(): array
    {
        $lastRead = ZabbixConfig::lastRead();

        return [
            'zabbix' => [
                // Le jeton n'est jamais renvoyé, seulement sa présence.
                'url'           => ZabbixConfig::rawUrl(),
                'has_token'     => ZabbixConfig::hasToken(),
                'configured'    => ZabbixConfig::isConfigured(),
                'entity'        => ZabbixConfig::entity(),
                'enabled'       => ZabbixConfig::isEnabled(),
                'allow_private' => Http::allowsPrivateNetworks(),
                'settings'      => ZabbixConfig::settings(),
                'scopes'        => ZabbixMonitoring::scopeLabels(),
                'last_read'     => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
                'last_error'    => $lastRead['error'] !== '' ? ZabbixMonitoring::errorMessage($lastRead['error']) : '',
                'page_url'      => ZabbixMonitoring::getSearchURL(),
            ],
        ];
    }

    private static function posted(): bool
    {
        return isset($_POST['save_zabbix']) || isset($_POST['save_zabbix_settings']) || isset($_POST['test_zabbix']);
    }

    public static function handle(): bool
    {
        if (!self::posted()) {
            return false;
        }
        Session::checkRight(ZabbixMonitoring::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);
        if (isset($_POST['save_zabbix'])) {
            self::saveConnection();
        } elseif (isset($_POST['save_zabbix_settings'])) {
            self::saveSettings();
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/zabbixmonitoring.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_zabbix']) || isset($_POST['save_zabbix_settings'])) {
            Session::checkRight(ZabbixMonitoring::RIGHTNAME, UPDATE);
            MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab::label());
            self::handle();
        }
    }

    private static function saveConnection(): void
    {
        $entity = (int) ($_POST['zabbix_entities_id'] ?? ZabbixConfig::entity());
        if (!Session::haveAccessToEntity($entity)) {
            $entity = (int) Session::getActiveEntity();
        }
        $saved = ZabbixConfig::save(
            (string) ($_POST['zabbix_url'] ?? ''),
            (string) ($_POST['zabbix_api_token'] ?? ''),
            !empty($_POST['zabbix_clear_token']),
            $entity,
            !empty($_POST['zabbix_enabled'])
        );
        if (!$saved['saved']) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Not saved: the address must start with https:// (or http:// when internal addresses are allowed), '
                    . 'without user name, password or parameters.',
                'grcmanager'
            )), false, ERROR);

            return;
        }
        if ($saved['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $saved['changes']]);
        }
        ZabbixMonitoring::syncCronState(ZabbixConfig::isEnabled());
        Session::addMessageAfterRedirect(htmlescape(__('Zabbix settings saved.', 'grcmanager')));
    }

    private static function saveSettings(): void
    {
        $before = ZabbixConfig::settings();
        $after = ZabbixConfig::saveSettings([
            'scope'       => $_POST['zabbix_scope'] ?? '',
            'period_days' => $_POST['zabbix_period_days'] ?? '',
        ]);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => ['zabbix_settings' => $after]]);
        }
        ZabbixMonitoring::reset();
        Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
    }

    /** Essai de la connexion enregistrée : rien n'est lu ni enregistré. */
    private static function test(): void
    {
        $result = ZabbixConfig::client()->test();
        if ($result['error'] !== '') {
            Session::addMessageAfterRedirect(
                htmlescape(ZabbixMonitoring::errorMessage($result['error'])),
                false,
                ERROR
            );

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Connection to Zabbix %s succeeded.', 'grcmanager'),
            $result['version']
        )));
    }
}
