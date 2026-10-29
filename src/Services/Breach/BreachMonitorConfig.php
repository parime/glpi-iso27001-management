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

namespace GlpiPlugin\Grcmanager\Services\Breach;

use Config;
use GLPIKey;

/**
 * Issue #183 : réglages de la surveillance des fuites (contexte plugin:grcmanager).
 *
 * La clé d'API est déclarée « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) : GLPI la
 * chiffre avec sa clé à l'enregistrement et la masque dans l'historique ; elle est déchiffrée ici,
 * seulement pour l'appel à l'API, et n'est jamais renvoyée vers le navigateur.
 */
final class BreachMonitorConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const ENABLED_KEY = 'hibp_enabled';
    public const API_KEY = 'hibp_api_key';
    public const DOMAINS_KEY = 'hibp_domains';
    /** Dernier utilisateur interrogé adresse par adresse : reprise au lot suivant. */
    public const CURSOR_KEY = 'hibp_cursor';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::API_KEY];

    public static function isEnabled(): bool
    {
        return (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    public static function hasApiKey(): bool
    {
        return (self::values()[self::API_KEY] ?? '') !== '';
    }

    public static function apiKey(): string
    {
        $stored = (string) (self::values()[self::API_KEY] ?? '');

        return $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored);
    }

    /**
     * @return list<string>
     */
    public static function domains(): array
    {
        return BreachRules::normalizeDomains((string) (self::values()[self::DOMAINS_KEY] ?? ''));
    }

    public static function cursor(): int
    {
        return (int) (self::values()[self::CURSOR_KEY] ?? 0);
    }

    public static function setCursor(int $usersId): void
    {
        Config::setConfigurationValues(self::CONTEXT, [self::CURSOR_KEY => (string) max(0, $usersId)]);
    }

    /**
     * Enregistre les réglages du formulaire. Une clé vide garde la clé déjà enregistrée (elle n'est
     * jamais réaffichée) ; $clearKey l'efface.
     */
    public static function save(bool $enabled, string $domains, string $apiKey, bool $clearKey): void
    {
        $values = [
            self::ENABLED_KEY => $enabled ? '1' : '0',
            self::DOMAINS_KEY => implode(', ', BreachRules::normalizeDomains($domains)),
        ];
        $apiKey = trim($apiKey);
        if ($clearKey) {
            $values[self::API_KEY] = '';
        } elseif ($apiKey !== '') {
            $values[self::API_KEY] = $apiKey;
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(
            self::CONTEXT,
            [self::ENABLED_KEY, self::API_KEY, self::DOMAINS_KEY, self::CURSOR_KEY]
        );
    }
}
