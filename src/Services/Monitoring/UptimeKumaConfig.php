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
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;

/**
 * Issue #842 : réglages du connecteur Uptime Kuma (contexte plugin:grcmanager, supprimés à la
 * désinstallation avec les autres réglages du plugin).
 *
 * La clé d'API est déclarée « configuration sécurisée » (Hooks::SECURED_CONFIGS, setup.php) : GLPI
 * la chiffre avec sa clé à l'enregistrement et la masque dans l'historique ; elle n'est déchiffrée
 * qu'au moment de l'appel et n'est jamais renvoyée au navigateur ni journalisée.
 */
final class UptimeKumaConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const URL_KEY = 'uptimekuma_url';
    public const API_KEY = 'uptimekuma_api_key';
    /** Pages de statut publiques lues (identifiants séparés par des virgules). */
    public const PAGES_KEY = 'uptimekuma_status_pages';
    /** Entité où sont rangées les lectures (visibles aussi de ses sous-entités). */
    public const ENTITY_KEY = 'uptimekuma_entities_id';
    /** Tâche automatique de lecture (désactivée par défaut). */
    public const ENABLED_KEY = 'uptimekuma_enabled';
    /** Période, seuil des certificats et durée d'une panne longue (JSON). */
    public const SETTINGS_KEY = 'uptimekuma_settings';
    /** Bilan de la dernière lecture (JSON : date, nombres, erreur). */
    public const LAST_READ_KEY = 'uptimekuma_last_read';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::API_KEY];

    public static function rawUrl(): string
    {
        return (string) (self::values()[self::URL_KEY] ?? '');
    }

    /** Adresse du serveur, normalisée (vide si absente ou refusée). */
    public static function baseUrl(): string
    {
        return Rules::normalizeBaseUrl(self::rawUrl(), Http::allowsPrivateNetworks()) ?? '';
    }

    public static function hasApiKey(): bool
    {
        return (string) (self::values()[self::API_KEY] ?? '') !== '';
    }

    /**
     * @return list<string>
     */
    public static function statusPages(): array
    {
        return Rules::normalizeSlugs((string) (self::values()[self::PAGES_KEY] ?? ''));
    }

    public static function entity(): int
    {
        return max(0, (int) (self::values()[self::ENTITY_KEY] ?? 0));
    }

    public static function isEnabled(): bool
    {
        return (string) (self::values()[self::ENABLED_KEY] ?? '0') === '1';
    }

    /** Adresse, et clé d'API ou page de statut : la lecture peut être lancée. */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && (self::hasApiKey() || self::statusPages() !== []);
    }

    /**
     * @return array{period_days: int, cert_days: int, outage_minutes: int}
     */
    public static function settings(): array
    {
        return Rules::normalizeSettings(json_decode((string) (self::values()[self::SETTINGS_KEY] ?? ''), true));
    }

    /**
     * @param array<mixed> $settings
     * @return array{period_days: int, cert_days: int, outage_minutes: int}
     */
    public static function saveSettings(array $settings): array
    {
        $normalized = Rules::normalizeSettings($settings);
        Config::setConfigurationValues(self::CONTEXT, [self::SETTINGS_KEY => json_encode($normalized)]);

        return $normalized;
    }

    public static function client(): UptimeKumaClient
    {
        $stored = (string) (self::values()[self::API_KEY] ?? '');

        return new UptimeKumaClient(
            self::baseUrl(),
            $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored)
        );
    }

    /**
     * Enregistre la connexion. Une clé vide garde celle déjà enregistrée (elle n'est jamais
     * réaffichée) ; $clearKey l'efface. Une adresse refusée n'est pas enregistrée : saved = false.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $url,
        #[\SensitiveParameter] string $apiKey,
        bool $clearKey,
        string $pages,
        int $entity,
        bool $enabled
    ): array {
        $url = trim($url);
        if ($url !== '' && Rules::normalizeBaseUrl($url, Http::allowsPrivateNetworks()) === null) {
            return ['saved' => false, 'changes' => []];
        }
        $apiKey = trim($apiKey);
        $pages = implode(',', Rules::normalizeSlugs($pages));
        $changes = [];
        $values = [];
        if ($url !== self::rawUrl()) {
            $values[self::URL_KEY] = $url;
            $changes[self::URL_KEY] = $url;
        }
        if ($clearKey && self::hasApiKey()) {
            $values[self::API_KEY] = '';
            $changes[self::API_KEY] = 'changed';
        } elseif (!$clearKey && $apiKey !== '') {
            $values[self::API_KEY] = $apiKey;
            // Seulement le fait qu'elle ait changé, jamais sa valeur.
            $changes[self::API_KEY] = 'changed';
        }
        if ($pages !== implode(',', self::statusPages())) {
            $values[self::PAGES_KEY] = $pages;
            $changes[self::PAGES_KEY] = $pages;
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
     * @return array{date: string, error: string, page: string, monitors: int, samples: int, pages: int,
     *     metrics: bool, truncated: bool}
     */
    public static function lastRead(): array
    {
        $raw = json_decode((string) (self::values()[self::LAST_READ_KEY] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'date'      => (string) ($raw['date'] ?? ''),
            'error'     => (string) ($raw['error'] ?? ''),
            'page'      => (string) ($raw['page'] ?? ''),
            'monitors'  => (int) ($raw['monitors'] ?? 0),
            'samples'   => (int) ($raw['samples'] ?? 0),
            'pages'     => (int) ($raw['pages'] ?? 0),
            'metrics'   => (bool) ($raw['metrics'] ?? false),
            'truncated' => (bool) ($raw['truncated'] ?? false),
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
            self::URL_KEY, self::API_KEY, self::PAGES_KEY, self::ENTITY_KEY, self::ENABLED_KEY,
            self::SETTINGS_KEY, self::LAST_READ_KEY,
        ]);
    }
}
