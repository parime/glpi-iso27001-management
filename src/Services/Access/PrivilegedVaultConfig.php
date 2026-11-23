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

namespace GlpiPlugin\Grcmanager\Services\Access;

use Config;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Services\Access\BitwardenPublicClient as Client;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules as Rules;

/**
 * Issue #859 : réglages du coffre-fort de mots de passe (contexte plugin:grcmanager, supprimés à la
 * désinstallation avec les autres réglages du plugin) : connexion à l'API publique de Bitwarden
 * (onglet Connecteurs) et délai de renouvellement attendu des secrets (onglet Personnes et
 * politiques).
 *
 * Le secret du client est déclaré « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) :
 * GLPI le chiffre avec sa clé à l'enregistrement et le masque dans l'historique ; il n'est déchiffré
 * qu'au moment de l'appel et n'est jamais renvoyé au navigateur ni journalisé.
 */
final class PrivilegedVaultConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const SERVER_KEY = 'vault_bitwarden_server';
    public const URL_KEY = 'vault_bitwarden_url';
    public const CLIENT_KEY = 'vault_bitwarden_client_id';
    public const SECRET_KEY = 'vault_bitwarden_secret';
    public const ROTATION_KEY = 'vault_rotation_months';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::SECRET_KEY];

    public static function server(): string
    {
        $server = (string) (self::values()[self::SERVER_KEY] ?? Client::SERVER_US);

        return in_array($server, [Client::SERVER_US, Client::SERVER_EU, Client::SERVER_SELF], true)
            ? $server : Client::SERVER_US;
    }

    public static function rawUrl(): string
    {
        return (string) (self::values()[self::URL_KEY] ?? '');
    }

    public static function clientId(): string
    {
        return (string) (self::values()[self::CLIENT_KEY] ?? '');
    }

    public static function hasSecret(): bool
    {
        return (string) (self::values()[self::SECRET_KEY] ?? '') !== '';
    }

    /**
     * @return array{api: string, identity: string}
     */
    public static function endpoints(): array
    {
        return Client::endpoints(self::server(), self::rawUrl(), Http::allowsPrivateNetworks());
    }

    public static function isConfigured(): bool
    {
        return self::endpoints()['api'] !== '' && Client::isValidClientId(self::clientId()) && self::hasSecret();
    }

    public static function client(): Client
    {
        $stored = (string) (self::values()[self::SECRET_KEY] ?? '');
        $endpoints = self::endpoints();

        return new Client(
            $endpoints['api'],
            $endpoints['identity'],
            self::clientId(),
            $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored)
        );
    }

    /**
     * Enregistre la connexion. Un secret vide garde celui déjà enregistré (il n'est jamais
     * réaffiché) ; $clearSecret l'efface. Adresse ou identifiant refusés : saved = false, rien
     * n'est enregistré.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $server,
        string $url,
        string $clientId,
        #[\SensitiveParameter] string $secret,
        bool $clearSecret
    ): array {
        $server = in_array($server, [Client::SERVER_US, Client::SERVER_EU, Client::SERVER_SELF], true)
            ? $server : Client::SERVER_US;
        $url = trim($url);
        $clientId = trim($clientId);
        if (
            ($server === Client::SERVER_SELF && Client::normalizeBaseUrl($url, Http::allowsPrivateNetworks()) === null)
            || ($clientId !== '' && !Client::isValidClientId($clientId))
        ) {
            return ['saved' => false, 'changes' => []];
        }
        $secret = trim($secret);
        $values = [];
        $changes = [];
        $compared = [
            self::SERVER_KEY => [$server, self::server()],
            self::URL_KEY    => [$url, self::rawUrl()],
            self::CLIENT_KEY => [$clientId, self::clientId()],
        ];
        foreach ($compared as $key => $pair) {
            if ($pair[0] !== $pair[1]) {
                $values[$key] = $pair[0];
                $changes[$key] = $pair[0];
            }
        }
        if ($clearSecret && self::hasSecret()) {
            $values[self::SECRET_KEY] = '';
            $changes[self::SECRET_KEY] = 'changed';
        } elseif (!$clearSecret && $secret !== '') {
            $values[self::SECRET_KEY] = $secret;
            // Seulement le fait qu'il ait changé, jamais sa valeur.
            $changes[self::SECRET_KEY] = 'changed';
        }
        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }

        return ['saved' => true, 'changes' => $changes];
    }

    /** Délai de renouvellement attendu des secrets du coffre, en mois. */
    public static function rotationMonths(): int
    {
        return Rules::normalizeRotationMonths(self::values()[self::ROTATION_KEY] ?? Rules::DEFAULT_ROTATION_MONTHS);
    }

    public static function saveRotationMonths(mixed $months): int
    {
        $months = Rules::normalizeRotationMonths($months);
        Config::setConfigurationValues(self::CONTEXT, [self::ROTATION_KEY => (string) $months]);

        return $months;
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(self::CONTEXT, [
            self::SERVER_KEY, self::URL_KEY, self::CLIENT_KEY, self::SECRET_KEY, self::ROTATION_KEY,
        ]);
    }
}
