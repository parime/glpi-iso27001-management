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

namespace GlpiPlugin\Grcmanager\Services\Monitoring;

use Config;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #836 : réglages du connecteur Zabbix (contexte plugin:grcmanager, supprimés à la
 * désinstallation avec les autres réglages du plugin).
 *
 * Le jeton d'API est déclaré « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) : GLPI
 * le chiffre avec sa clé à l'enregistrement et le masque dans l'historique ; il n'est déchiffré qu'au
 * moment de l'appel et n'est jamais renvoyé au navigateur ni journalisé.
 */
final class ZabbixConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const URL_KEY = 'zabbix_url';
    public const TOKEN_KEY = 'zabbix_api_token';
    /** Entité où sont rangées les lectures (visibles aussi de ses sous-entités). */
    public const ENTITY_KEY = 'zabbix_entities_id';
    /** Tâche automatique de lecture (désactivée par défaut). */
    public const ENABLED_KEY = 'zabbix_enabled';
    /** Actifs concernés et période des problèmes (JSON). */
    public const SETTINGS_KEY = 'zabbix_settings';
    /** Bilan de la dernière lecture (JSON : date, version, nombres, erreur). */
    public const LAST_READ_KEY = 'zabbix_last_read';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::TOKEN_KEY];

    public static function rawUrl(): string
    {
        return (string) (self::values()[self::URL_KEY] ?? '');
    }

    /** Adresse de l'API, normalisée (vide si absente ou refusée). */
    public static function apiUrl(): string
    {
        return ZabbixRules::normalizeApiUrl(self::rawUrl(), Http::allowsPrivateNetworks()) ?? '';
    }

    public static function hasToken(): bool
    {
        return (string) (self::values()[self::TOKEN_KEY] ?? '') !== '';
    }

    public static function entity(): int
    {
        return max(0, (int) (self::values()[self::ENTITY_KEY] ?? 0));
    }

    public static function isEnabled(): bool
    {
        return (string) (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    /** Adresse et jeton présents : la lecture peut être lancée. */
    public static function isConfigured(): bool
    {
        return self::apiUrl() !== '' && self::hasToken();
    }

    /**
     * @return array{scope: string, period_days: int}
     */
    public static function settings(): array
    {
        return ZabbixRules::normalizeSettings(json_decode((string) (self::values()[self::SETTINGS_KEY] ?? ''), true));
    }

    /**
     * @param array<mixed> $settings
     * @return array{scope: string, period_days: int}
     */
    public static function saveSettings(array $settings): array
    {
        $normalized = ZabbixRules::normalizeSettings($settings);
        Config::setConfigurationValues(self::CONTEXT, [self::SETTINGS_KEY => json_encode($normalized)]);

        return $normalized;
    }

    public static function client(): ZabbixClient
    {
        $stored = (string) (self::values()[self::TOKEN_KEY] ?? '');

        return new ZabbixClient(self::apiUrl(), $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored));
    }

    /**
     * Enregistre la connexion. Un jeton vide garde celui déjà enregistré (il n'est jamais réaffiché) ;
     * $clearToken l'efface. Une adresse refusée n'est pas enregistrée : saved = false.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $url,
        #[\SensitiveParameter] string $token,
        bool $clearToken,
        int $entity,
        bool $enabled
    ): array {
        $url = trim($url);
        if ($url !== '' && ZabbixRules::normalizeApiUrl($url, Http::allowsPrivateNetworks()) === null) {
            return ['saved' => false, 'changes' => []];
        }
        $token = trim($token);
        $changes = [];
        $values = [];
        if ($url !== self::rawUrl()) {
            $values[self::URL_KEY] = $url;
            $changes[self::URL_KEY] = $url;
        }
        if ($clearToken && self::hasToken()) {
            $values[self::TOKEN_KEY] = '';
            $changes[self::TOKEN_KEY] = 'changed';
        } elseif (!$clearToken && $token !== '') {
            $values[self::TOKEN_KEY] = $token;
            // Seulement le fait qu'il ait changé, jamais sa valeur.
            $changes[self::TOKEN_KEY] = 'changed';
        }
        $entity = max(0, $entity);
        if ($entity !== self::entity()) {
            $values[self::ENTITY_KEY] = $entity;
            $changes[self::ENTITY_KEY] = (string) $entity;
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
     * @return array{date: string, version: string, error: string, hosts: int, problems: int, services: int,
     *     truncated: bool, sla_supported: bool}
     */
    public static function lastRead(): array
    {
        $raw = json_decode((string) (self::values()[self::LAST_READ_KEY] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'date'          => (string) ($raw['date'] ?? ''),
            'version'       => (string) ($raw['version'] ?? ''),
            'error'         => (string) ($raw['error'] ?? ''),
            'hosts'         => (int) ($raw['hosts'] ?? 0),
            'problems'      => (int) ($raw['problems'] ?? 0),
            'services'      => (int) ($raw['services'] ?? 0),
            'truncated'     => (bool) ($raw['truncated'] ?? false),
            'sla_supported' => (bool) ($raw['sla_supported'] ?? false),
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
            self::URL_KEY, self::TOKEN_KEY, self::ENTITY_KEY, self::ENABLED_KEY, self::SETTINGS_KEY,
            self::LAST_READ_KEY,
        ]);
    }
}
