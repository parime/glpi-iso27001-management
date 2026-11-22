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

namespace GlpiPlugin\Grcmanager\Services\CodeSecurity;

use Config;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;

/**
 * Issue #858 : réglages de la lecture des alertes de GitHub et de GitLab (contexte plugin:grcmanager,
 * supprimés à la désinstallation avec les autres réglages du plugin).
 *
 * Les deux jetons sont déclarés « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) :
 * GLPI les chiffre avec sa clé à l'enregistrement et les masque dans l'historique ; ils ne sont
 * déchiffrés qu'au moment de l'appel et ne sont jamais renvoyés au navigateur ni journalisés.
 */
final class CodeSecurityConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const GITHUB_TOKEN_KEY = 'codesecurity_github_token';
    /** Serveur GitHub Enterprise (vide : github.com). */
    public const GITHUB_URL_KEY = 'codesecurity_github_url';
    public const GITLAB_TOKEN_KEY = 'codesecurity_gitlab_token';
    /** Serveur GitLab (vide : gitlab.com). */
    public const GITLAB_URL_KEY = 'codesecurity_gitlab_url';
    /** Tâche automatique de lecture (désactivée par défaut). */
    public const ENABLED_KEY = 'codesecurity_enabled';
    /** Bilan de la dernière lecture (JSON : date, nombres, erreur). */
    public const LAST_READ_KEY = 'codesecurity_last_read';

    public const DEFAULT_GITLAB = 'https://gitlab.com';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::GITHUB_TOKEN_KEY, self::GITLAB_TOKEN_KEY];

    public static function rawGithubUrl(): string
    {
        return (string) (self::values()[self::GITHUB_URL_KEY] ?? '');
    }

    public static function rawGitlabUrl(): string
    {
        return (string) (self::values()[self::GITLAB_URL_KEY] ?? '');
    }

    /** Adresse de l'API GitHub : api.github.com, ou « /api/v3 » du serveur GitHub Enterprise. */
    public static function githubApi(): string
    {
        $raw = self::rawGithubUrl();
        if ($raw === '') {
            return CodeSecurityClient::GITHUB_API;
        }
        $server = Rules::normalizeServerUrl($raw, Http::allowsPrivateNetworks());

        return $server === null ? '' : (str_ends_with($server, '/api/v3') ? $server : $server . '/api/v3');
    }

    /** Hôte des dépôts GitHub reconnus en plus de github.com (GitHub Enterprise). */
    public static function githubHost(): string
    {
        $raw = self::rawGithubUrl();

        return $raw === '' ? 'github.com' : Rules::hostOf(self::githubApi());
    }

    public static function gitlabUrl(): string
    {
        $raw = self::rawGitlabUrl();

        return Rules::normalizeServerUrl($raw === '' ? self::DEFAULT_GITLAB : $raw, Http::allowsPrivateNetworks()) ?? '';
    }

    public static function gitlabHost(): string
    {
        return Rules::hostOf(self::gitlabUrl());
    }

    public static function hasGithubToken(): bool
    {
        return (string) (self::values()[self::GITHUB_TOKEN_KEY] ?? '') !== '';
    }

    public static function hasGitlabToken(): bool
    {
        return (string) (self::values()[self::GITLAB_TOKEN_KEY] ?? '') !== '';
    }

    public static function isEnabled(): bool
    {
        return (string) (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    /** Au moins un jeton : la lecture peut être lancée. */
    public static function isConfigured(): bool
    {
        return (self::hasGithubToken() && self::githubApi() !== '')
            || (self::hasGitlabToken() && self::gitlabUrl() !== '');
    }

    public static function client(): CodeSecurityClient
    {
        $values = self::values();
        $decrypt = static function (string $stored): string {
            return $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored);
        };

        return new CodeSecurityClient(
            self::githubApi(),
            $decrypt((string) ($values[self::GITHUB_TOKEN_KEY] ?? '')),
            self::gitlabUrl(),
            $decrypt((string) ($values[self::GITLAB_TOKEN_KEY] ?? ''))
        );
    }

    /**
     * Enregistre la connexion. Un jeton vide garde celui déjà enregistré (il n'est jamais réaffiché) ;
     * $clear… l'efface. Une adresse refusée n'est pas enregistrée : saved = false.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $githubUrl,
        #[\SensitiveParameter] string $githubToken,
        bool $clearGithub,
        string $gitlabUrl,
        #[\SensitiveParameter] string $gitlabToken,
        bool $clearGitlab,
        bool $enabled
    ): array {
        $allowHttp = Http::allowsPrivateNetworks();
        $githubUrl = rtrim(trim($githubUrl), '/');
        $gitlabUrl = rtrim(trim($gitlabUrl), '/');
        foreach ([$githubUrl, $gitlabUrl] as $url) {
            if ($url !== '' && Rules::normalizeServerUrl($url, $allowHttp) === null) {
                return ['saved' => false, 'changes' => []];
            }
        }
        // github.com et gitlab.com sont les valeurs par défaut : rien à garder.
        if (in_array(Rules::hostOf($githubUrl), ['github.com', 'api.github.com', 'www.github.com'], true)) {
            $githubUrl = '';
        }
        if ($gitlabUrl === self::DEFAULT_GITLAB) {
            $gitlabUrl = '';
        }
        $values = [];
        $changes = [];
        if ($githubUrl !== self::rawGithubUrl()) {
            $values[self::GITHUB_URL_KEY] = $githubUrl;
            $changes[self::GITHUB_URL_KEY] = $githubUrl;
        }
        if ($gitlabUrl !== self::rawGitlabUrl()) {
            $values[self::GITLAB_URL_KEY] = $gitlabUrl;
            $changes[self::GITLAB_URL_KEY] = $gitlabUrl;
        }
        $tokens = [
            self::GITHUB_TOKEN_KEY => [trim($githubToken), $clearGithub, self::hasGithubToken()],
            self::GITLAB_TOKEN_KEY => [trim($gitlabToken), $clearGitlab, self::hasGitlabToken()],
        ];
        foreach ($tokens as $key => [$token, $clear, $has]) {
            if ($clear && $has) {
                $values[$key] = '';
                $changes[$key] = 'changed';
            } elseif (!$clear && $token !== '') {
                $values[$key] = $token;
                // Seulement le fait qu'il ait changé, jamais sa valeur.
                $changes[$key] = 'changed';
            }
        }
        if ($enabled !== self::isEnabled()) {
            $values[self::ENABLED_KEY] = $enabled ? '1' : '0';
            $changes[self::ENABLED_KEY] = $enabled ? '1' : '0';
        }
        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }

        return ['saved' => true, 'changes' => $changes];
    }

    /**
     * @return array{date: string, error: string, repositories: int, alerts: int, failed: int}
     */
    public static function lastRead(): array
    {
        $raw = json_decode((string) (self::values()[self::LAST_READ_KEY] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'date'         => (string) ($raw['date'] ?? ''),
            'error'        => (string) ($raw['error'] ?? ''),
            'repositories' => (int) ($raw['repositories'] ?? 0),
            'alerts'       => (int) ($raw['alerts'] ?? 0),
            'failed'       => (int) ($raw['failed'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $lastRead
     */
    public static function saveLastRead(array $lastRead): void
    {
        Config::setConfigurationValues(self::CONTEXT, [self::LAST_READ_KEY => json_encode($lastRead)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(self::CONTEXT, [
            self::GITHUB_TOKEN_KEY, self::GITHUB_URL_KEY, self::GITLAB_TOKEN_KEY, self::GITLAB_URL_KEY,
            self::ENABLED_KEY, self::LAST_READ_KEY,
        ]);
    }
}
