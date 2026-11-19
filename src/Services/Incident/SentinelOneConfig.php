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

namespace GlpiPlugin\Grcmanager\Services\Incident;

use Config;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #707 : réglages du connecteur SentinelOne (contexte plugin:grcmanager).
 *
 * Le jeton d'API est déclaré « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) : GLPI
 * le chiffre avec sa clé (GLPIKey) à l'enregistrement et le masque dans l'historique ; il n'est
 * déchiffré qu'au moment de l'appel et n'est jamais renvoyé au navigateur ni journalisé.
 */
final class SentinelOneConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const ENABLED_KEY = 'sentinelone_enabled';
    public const URL_KEY = 'sentinelone_url';
    public const TOKEN_KEY = 'sentinelone_api_token';
    /** Entité des alertes dont l'ordinateur n'est pas reconnu dans GLPI. */
    public const ENTITY_KEY = 'sentinelone_entities_id';
    /** Date de création de la dernière menace / alerte lue : reprise au passage suivant. */
    public const THREATS_SINCE_KEY = 'sentinelone_threats_since';
    public const ALERTS_SINCE_KEY = 'sentinelone_alerts_since';
    /** Bilan du dernier passage (JSON : date et résultat de chaque liste). */
    public const LAST_RUN_KEY = 'sentinelone_last_run';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::TOKEN_KEY];

    /** Profondeur de la première lecture des menaces et alertes, en jours. */
    public const FIRST_RUN_DAYS = 30;

    public static function isEnabled(): bool
    {
        return (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    /** Adresse de la console enregistrée, normalisée (vide si absente ou refusée). */
    public static function url(): string
    {
        return SentinelOneParser::normalizeConsoleUrl(
            (string) (self::values()[self::URL_KEY] ?? ''),
            Http::allowsPrivateNetworks()
        ) ?? '';
    }

    /** Adresse telle qu'enregistrée, pour le formulaire. */
    public static function rawUrl(): string
    {
        return (string) (self::values()[self::URL_KEY] ?? '');
    }

    public static function hasToken(): bool
    {
        return (self::values()[self::TOKEN_KEY] ?? '') !== '';
    }

    public static function token(): string
    {
        $stored = (string) (self::values()[self::TOKEN_KEY] ?? '');

        return $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored);
    }

    /** Adresse et jeton présents : la connexion peut être testée. */
    public static function isConfigured(): bool
    {
        return self::url() !== '' && self::hasToken();
    }

    public static function entityId(): int
    {
        return max(0, (int) (self::values()[self::ENTITY_KEY] ?? 0));
    }

    public static function client(): SentinelOneClient
    {
        return new SentinelOneClient(self::url(), self::token());
    }

    public static function since(string $list): string
    {
        $key = $list === 'alerts' ? self::ALERTS_SINCE_KEY : self::THREATS_SINCE_KEY;
        $value = (string) (self::values()[$key] ?? '');

        return $value !== ''
            ? $value
            : gmdate('Y-m-d\TH:i:s.000\Z', time() - self::FIRST_RUN_DAYS * 86400);
    }

    public static function setSince(string $list, string $date): void
    {
        Config::setConfigurationValues(self::CONTEXT, [
            ($list === 'alerts' ? self::ALERTS_SINCE_KEY : self::THREATS_SINCE_KEY) => $date,
        ]);
    }

    /**
     * @return array{date: string, steps: array<string, array{items: int, complete: bool, error: string}>}|null
     */
    public static function lastRun(): ?array
    {
        $run = json_decode((string) (self::values()[self::LAST_RUN_KEY] ?? ''), true);

        return is_array($run) && isset($run['date'], $run['steps']) && is_array($run['steps']) ? $run : null;
    }

    /**
     * @param array<string, array{items: int, complete: bool, error: string}> $steps
     */
    public static function setLastRun(array $steps): void
    {
        Config::setConfigurationValues(self::CONTEXT, [self::LAST_RUN_KEY => (string) json_encode([
            'date'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'steps' => $steps,
        ])]);
    }

    /**
     * Enregistre les réglages du formulaire. Un jeton vide garde le jeton déjà enregistré (il n'est
     * jamais réaffiché) ; $clearToken l'efface. Une adresse refusée (pas HTTPS, identifiants dans
     * l'adresse) n'est pas enregistrée : renvoie false.
     */
    public static function save(
        bool $enabled,
        string $url,
        #[\SensitiveParameter] string $token,
        bool $clearToken,
        int $entityId
    ): bool {
        $url = trim($url);
        $normalized = $url === '' ? '' : SentinelOneParser::normalizeConsoleUrl($url, Http::allowsPrivateNetworks());
        $values = [
            self::ENTITY_KEY => (string) max(0, $entityId),
        ];
        if ($normalized !== null) {
            if ($normalized !== self::url()) {
                // Autre console : la lecture reprend de zéro.
                $values[self::THREATS_SINCE_KEY] = '';
                $values[self::ALERTS_SINCE_KEY] = '';
            }
            $values[self::URL_KEY] = $normalized;
        }
        $token = trim($token);
        if ($clearToken) {
            $values[self::TOKEN_KEY] = '';
        } elseif ($token !== '') {
            $values[self::TOKEN_KEY] = $token;
        }
        $values[self::ENABLED_KEY] = $enabled && $normalized !== null ? '1' : '0';
        Config::setConfigurationValues(self::CONTEXT, $values);

        return $normalized !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(self::CONTEXT, [
            self::ENABLED_KEY, self::URL_KEY, self::TOKEN_KEY, self::ENTITY_KEY,
            self::THREATS_SINCE_KEY, self::ALERTS_SINCE_KEY, self::LAST_RUN_KEY,
        ]);
    }
}
