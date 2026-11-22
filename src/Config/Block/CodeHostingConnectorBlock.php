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

use Appliance;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\CodeSecurity;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Config\Tab\ConnectorsTab;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityConfig;
use Html;
use Session;

/**
 * Issue #1043 : connexion à GitHub et GitLab (jetons chiffrés jamais réaffichés, serveurs, lecture
 * automatique), déplacée de front/codesecurity.php. La page d'exploitation garde les résultats et les
 * boutons « Lire maintenant » et « Supprimer les relevés ». Droits d'origine : lecture du plugin et
 * des applications pour voir, modification du plugin pour enregistrer ou tester.
 */
final class CodeHostingConnectorBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-codesecurity-config';

    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 85;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_code_hosting_connector.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return CodeSecurity::canView();
    }

    public static function data(): array
    {
        $lastRead = CodeSecurityConfig::lastRead();

        return [
            'code_hosting' => [
                // Les jetons ne sont jamais renvoyés, seulement leur présence.
                'github_url'    => CodeSecurityConfig::rawGithubUrl(),
                'gitlab_url'    => CodeSecurityConfig::rawGitlabUrl(),
                'has_github'    => CodeSecurityConfig::hasGithubToken(),
                'has_gitlab'    => CodeSecurityConfig::hasGitlabToken(),
                'configured'    => CodeSecurityConfig::isConfigured(),
                'enabled'       => CodeSecurityConfig::isEnabled(),
                'allow_private' => Http::allowsPrivateNetworks(),
                'last_read'     => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_codesecurity']) && !isset($_POST['test_codesecurity'])) {
            return false;
        }
        Session::checkRight(CodeSecurity::RIGHTNAME, UPDATE);
        Session::checkRight(Appliance::$rightname, READ);
        if (isset($_POST['save_codesecurity'])) {
            self::save();
        } else {
            self::test();
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/codesecurity.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_codesecurity'])) {
            Session::checkRight(CodeSecurity::RIGHTNAME, UPDATE);
            MovedSettings::notice(ConnectorsTab::label());
            self::handle();
        }
    }

    private static function save(): void
    {
        $saved = CodeSecurityConfig::save(
            (string) ($_POST['codesecurity_github_url'] ?? ''),
            (string) ($_POST['codesecurity_github_token'] ?? ''),
            !empty($_POST['codesecurity_github_clear']),
            (string) ($_POST['codesecurity_gitlab_url'] ?? ''),
            (string) ($_POST['codesecurity_gitlab_token'] ?? ''),
            !empty($_POST['codesecurity_gitlab_clear']),
            !empty($_POST['codesecurity_enabled'])
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
        CodeSecurity::syncCronState(CodeSecurityConfig::isEnabled());
        Session::addMessageAfterRedirect(htmlescape(__('Code security settings saved.', 'grcmanager')));
    }

    /** Essai des jetons enregistrés : aucune alerte n'est lue, rien n'est enregistré. */
    private static function test(): void
    {
        $results = CodeSecurityConfig::client()->test();
        if ($results === []) {
            Session::addMessageAfterRedirect(htmlescape(CodeSecurity::errorMessage('not_configured')), false, ERROR);

            return;
        }
        $providers = CodeSecurity::providerInfos();
        foreach ($results as $provider => $error) {
            $label = (string) ($providers[$provider]['label'] ?? $provider);
            if ($error !== '') {
                Session::addMessageAfterRedirect(
                    htmlescape(sprintf(__('%1$s: %2$s', 'grcmanager'), $label, CodeSecurity::errorMessage($error))),
                    false,
                    ERROR
                );
                continue;
            }
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Connection to %s succeeded.', 'grcmanager'),
                $label
            )));
        }
    }
}
