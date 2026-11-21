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

namespace GlpiPlugin\Grcmanager\Services\Backup;

use Config;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #838 : réglages du connecteur Veeam Backup & Replication (contexte plugin:grcmanager,
 * supprimé à la désinstallation).
 *
 * Le mot de passe du compte de lecture est déclaré « configuration sécurisée »
 * (Hooks::SECURED_CONFIGS, setup.php) : GLPI le chiffre avec sa clé à l'enregistrement et le
 * masque dans l'historique ; il n'est déchiffré qu'au moment de l'appel et n'est jamais renvoyé au
 * navigateur ni journalisé.
 */
final class VeeamConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const URL_KEY = 'veeam_url';
    public const USER_KEY = 'veeam_username';
    public const PASSWORD_KEY = 'veeam_password';
    public const VERSION_KEY = 'veeam_api_version';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::PASSWORD_KEY];

    /** Adresse enregistrée, normalisée (vide si absente ou refusée). */
    public static function url(): string
    {
        return VeeamClient::normalizeUrl(
            (string) (self::values()[self::URL_KEY] ?? ''),
            Http::allowsPrivateNetworks()
        ) ?? '';
    }

    /** Adresse telle qu'enregistrée, pour le formulaire. */
    public static function rawUrl(): string
    {
        return (string) (self::values()[self::URL_KEY] ?? '');
    }

    public static function username(): string
    {
        return (string) (self::values()[self::USER_KEY] ?? '');
    }

    public static function apiVersion(): string
    {
        $version = (string) (self::values()[self::VERSION_KEY] ?? '');

        return VeeamClient::isValidApiVersion($version) ? $version : VeeamClient::DEFAULT_API_VERSION;
    }

    public static function hasPassword(): bool
    {
        return (string) (self::values()[self::PASSWORD_KEY] ?? '') !== '';
    }

    /** Adresse, compte et mot de passe présents : la lecture peut être lancée. */
    public static function isConfigured(): bool
    {
        return self::url() !== '' && trim(self::username()) !== '' && self::hasPassword();
    }

    public static function client(): VeeamClient
    {
        $stored = (string) (self::values()[self::PASSWORD_KEY] ?? '');

        return new VeeamClient(
            self::url(),
            self::username(),
            $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored),
            self::apiVersion()
        );
    }

    /**
     * Enregistre les réglages. Un mot de passe vide garde celui déjà enregistré (il n'est jamais
     * réaffiché) ; $clearPassword l'efface. Une adresse refusée (pas HTTPS, identifiants dans
     * l'adresse) n'est pas enregistrée : saved = false.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $url,
        string $username,
        #[\SensitiveParameter] string $password,
        string $apiVersion,
        bool $clearPassword
    ): array {
        $url = trim($url);
        $normalized = $url === '' ? '' : VeeamClient::normalizeUrl($url, Http::allowsPrivateNetworks());
        if ($normalized === null) {
            return ['saved' => false, 'changes' => []];
        }
        $username = mb_substr(trim($username), 0, 255);
        $apiVersion = VeeamClient::isValidApiVersion($apiVersion) ? $apiVersion : VeeamClient::DEFAULT_API_VERSION;
        $password = trim($password);
        $changes = [];
        $values = [];
        $stored = self::values();
        $wanted = [self::URL_KEY => $normalized, self::USER_KEY => $username, self::VERSION_KEY => $apiVersion];
        foreach ($wanted as $key => $value) {
            if ($value !== (string) ($stored[$key] ?? '')) {
                $values[$key] = $value;
                $changes[$key] = $value;
            }
        }
        if ($clearPassword && self::hasPassword()) {
            $values[self::PASSWORD_KEY] = '';
            $changes[self::PASSWORD_KEY] = 'changed';
        } elseif (!$clearPassword && $password !== '') {
            $values[self::PASSWORD_KEY] = $password;
            // Seulement le fait qu'il ait changé, jamais sa valeur.
            $changes[self::PASSWORD_KEY] = 'changed';
        }
        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }

        return ['saved' => true, 'changes' => $changes];
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(
            self::CONTEXT,
            [self::URL_KEY, self::USER_KEY, self::PASSWORD_KEY, self::VERSION_KEY]
        );
    }
}
