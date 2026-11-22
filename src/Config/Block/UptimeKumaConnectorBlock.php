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
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use Html;
use Session;

/**
 * Issue #1042 : connexion à Uptime Kuma (adresse, clé d'API chiffrée jamais réaffichée, pages de
 * statut, entité, lecture automatique) et seuils, déplacés de front/uptimekuma.php. La page
 * d'exploitation garde les résultats et le bouton « Lire maintenant ». Droits d'origine : lecture du
 * plugin et des ordinateurs pour voir, modification du plugin pour enregistrer.
 */
final class UptimeKumaConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-kuma-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 50;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_uptime_kuma_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return UptimeKumaMonitoring::canView();
    }

    public static function data(): array
    {
        $lastRead = UptimeKumaConfig::lastRead();

        return [
            'kuma' => [
                // La clé n'est jamais renvoyée, seulement sa présence.
                'url'           => UptimeKumaConfig::rawUrl(),
                'has_key'       => UptimeKumaConfig::hasApiKey(),
                'pages'         => implode(', ', UptimeKumaConfig::statusPages()),
                'configured'    => UptimeKumaConfig::isConfigured(),
                'entity'        => UptimeKumaConfig::entity(),
                'enabled'       => UptimeKumaConfig::isEnabled(),
                'allow_private' => Http::allowsPrivateNetworks(),
                'settings'      => UptimeKumaConfig::settings(),
                'last_read'     => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
                'last_error'    => $lastRead['error'] !== ''
                    ? UptimeKumaMonitoring::errorMessage($lastRead['error'], $lastRead['page']) : '',
                'page_url'      => UptimeKumaMonitoring::getSearchURL(),
            ],
        ];
    }

    private static function posted(): bool
    {
        return isset($_POST['save_uptimekuma'])
            || isset($_POST['save_uptimekuma_settings'])
            || isset($_POST['test_uptimekuma']);
    }

    public static function handle(): bool
    {
        if (!self::posted()) {
            return false;
        }
        Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);
        if (isset($_POST['save_uptimekuma'])) {
            self::saveConnection();
        } elseif (isset($_POST['save_uptimekuma_settings'])) {
            self::saveSettings();
        } else {
            self::test();
        }
        Html::redirect(ConfigTabs::anchorUrl(self::ANCHOR));

        return true;
    }

    /** Ancien envoi de front/uptimekuma.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_uptimekuma']) || isset($_POST['save_uptimekuma_settings'])) {
            Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
            MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab::label());
            self::handle();
        }
    }

    private static function saveConnection(): void
    {
        $entity = (int) ($_POST['uptimekuma_entities_id'] ?? UptimeKumaConfig::entity());
        if (!Session::haveAccessToEntity($entity)) {
            $entity = (int) Session::getActiveEntity();
        }
        $saved = UptimeKumaConfig::save(
            (string) ($_POST['uptimekuma_url'] ?? ''),
            (string) ($_POST['uptimekuma_api_key'] ?? ''),
            !empty($_POST['uptimekuma_clear_key']),
            (string) ($_POST['uptimekuma_status_pages'] ?? ''),
            $entity,
            !empty($_POST['uptimekuma_enabled'])
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
        UptimeKumaMonitoring::syncCronState(UptimeKumaConfig::isEnabled());
        Session::addMessageAfterRedirect(htmlescape(__('Uptime Kuma settings saved.', 'grcmanager')));
    }

    private static function saveSettings(): void
    {
        $before = UptimeKumaConfig::settings();
        $after = UptimeKumaConfig::saveSettings([
            'period_days'    => $_POST['uptimekuma_period_days'] ?? '',
            'cert_days'      => $_POST['uptimekuma_cert_days'] ?? '',
            'outage_minutes' => $_POST['uptimekuma_outage_minutes'] ?? '',
        ]);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => ['uptimekuma_settings' => $after]]);
        }
        UptimeKumaMonitoring::reset();
        Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
    }

    /** Essai de la connexion enregistrée : rien n'est lu ni enregistré. */
    private static function test(): void
    {
        $result = UptimeKumaConfig::client()->test(UptimeKumaConfig::statusPages());
        if ($result['error'] !== '') {
            Session::addMessageAfterRedirect(
                htmlescape(UptimeKumaMonitoring::errorMessage($result['error'], $result['page'])),
                false,
                ERROR
            );

            return;
        }
        Session::addMessageAfterRedirect(htmlescape(__('Connection to Uptime Kuma succeeded.', 'grcmanager')));
    }
}
