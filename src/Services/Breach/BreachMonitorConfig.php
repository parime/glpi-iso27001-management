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
    /** Issue #1055 : fournisseur choisi (PROVIDER_HIBP ou PROVIDER_XON). */
    public const PROVIDER_KEY = 'breach_provider';
    /** Issue #1055 : clé XposedOrNot de recherche par domaine (facultative). */
    public const XON_API_KEY = 'xon_api_key';

    public const PROVIDER_HIBP = 'hibp';
    public const PROVIDER_XON = 'xposedornot';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::API_KEY, self::XON_API_KEY];

    public static function isEnabled(): bool
    {
        return (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    /** Clé Have I Been Pwned enregistrée. */
    public static function hasApiKey(): bool
    {
        return (self::values()[self::API_KEY] ?? '') !== '';
    }

    public static function apiKey(): string
    {
        return self::decrypted(self::API_KEY);
    }

    /** Issue #1055 : clé XposedOrNot (recherche par domaine) enregistrée. */
    public static function hasXonApiKey(): bool
    {
        return (self::values()[self::XON_API_KEY] ?? '') !== '';
    }

    /**
     * Fournisseur choisi. Sans choix enregistré : Have I Been Pwned si sa clé l'est déjà (installations
     * antérieures au #1055), sinon XposedOrNot, gratuit et utilisable sans clé.
     */
    public static function provider(): string
    {
        $stored = (string) (self::values()[self::PROVIDER_KEY] ?? '');
        if (in_array($stored, [self::PROVIDER_HIBP, self::PROVIDER_XON], true)) {
            return $stored;
        }

        return self::hasApiKey() ? self::PROVIDER_HIBP : self::PROVIDER_XON;
    }

    /** La tâche peut tourner : activée, et clé présente pour Have I Been Pwned (XposedOrNot s'en passe). */
    public static function isReady(): bool
    {
        return self::isEnabled() && (self::provider() === self::PROVIDER_XON || self::hasApiKey());
    }

    /** Client du fournisseur choisi, avec sa clé déchiffrée. */
    public static function client(): BreachProvider
    {
        return self::provider() === self::PROVIDER_XON
            ? new XposedOrNotClient(self::decrypted(self::XON_API_KEY))
            : new HibpClient(self::apiKey());
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
     * jamais réaffichée) ; $clearKey (Have I Been Pwned) et $clearXonKey (XposedOrNot) l'effacent. Un
     * fournisseur inconnu ou vide garde le choix actuel.
     */
    public static function save(
        bool $enabled,
        string $domains,
        string $apiKey,
        bool $clearKey,
        string $provider = '',
        string $xonApiKey = '',
        bool $clearXonKey = false,
    ): void {
        $values = [
            self::ENABLED_KEY => $enabled ? '1' : '0',
            self::DOMAINS_KEY => implode(', ', BreachRules::normalizeDomains($domains)),
        ];
        if (in_array($provider, [self::PROVIDER_HIBP, self::PROVIDER_XON], true)) {
            $values[self::PROVIDER_KEY] = $provider;
        }
        $keys = [self::API_KEY => [$apiKey, $clearKey], self::XON_API_KEY => [$xonApiKey, $clearXonKey]];
        foreach ($keys as $key => $input) {
            $value = trim($input[0]);
            if ($input[1]) {
                $values[$key] = '';
            } elseif ($value !== '') {
                $values[$key] = $value;
            }
        }
        // Le curseur de reprise ne vaut que pour un fournisseur : un changement repart du début.
        if (isset($values[self::PROVIDER_KEY]) && $values[self::PROVIDER_KEY] !== self::provider()) {
            $values[self::CURSOR_KEY] = '0';
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    private static function decrypted(string $key): string
    {
        $stored = (string) (self::values()[$key] ?? '');

        return $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored);
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(
            self::CONTEXT,
            [
                self::ENABLED_KEY, self::API_KEY, self::DOMAINS_KEY, self::CURSOR_KEY,
                self::PROVIDER_KEY, self::XON_API_KEY,
            ]
        );
    }
}
