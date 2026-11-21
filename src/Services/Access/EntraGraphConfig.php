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

/**
 * Issue #852 : réglages de la lecture de Microsoft Entra ID par Microsoft Graph (contexte
 * plugin:grcmanager, supprimé à la désinstallation).
 *
 * Le secret de l'application est déclaré « configuration sécurisée » (Hooks::SECURED_CONFIGS,
 * setup.php) : GLPI le chiffre avec sa clé à l'enregistrement et le masque dans l'historique ; il
 * n'est déchiffré qu'au moment de l'appel et n'est jamais renvoyé au navigateur ni journalisé.
 */
final class EntraGraphConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const TENANT_KEY = 'mfa_graph_tenant';
    public const CLIENT_KEY = 'mfa_graph_client_id';
    public const SECRET_KEY = 'mfa_graph_secret';

    /** Clés à déclarer en configuration sécurisée. */
    public const SECURED = [self::SECRET_KEY];

    public static function tenant(): string
    {
        return (string) (self::values()[self::TENANT_KEY] ?? '');
    }

    public static function clientId(): string
    {
        return (string) (self::values()[self::CLIENT_KEY] ?? '');
    }

    public static function hasSecret(): bool
    {
        return (string) (self::values()[self::SECRET_KEY] ?? '') !== '';
    }

    /** Locataire, application et secret présents : la lecture peut être lancée. */
    public static function isConfigured(): bool
    {
        return EntraGraphClient::isValidTenant(self::tenant())
            && EntraGraphClient::isValidClientId(self::clientId())
            && self::hasSecret();
    }

    public static function client(): EntraGraphClient
    {
        $stored = (string) (self::values()[self::SECRET_KEY] ?? '');

        return new EntraGraphClient(
            self::tenant(),
            self::clientId(),
            $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored)
        );
    }

    /**
     * Enregistre les réglages. Un secret vide garde celui déjà enregistré (il n'est jamais
     * réaffiché) ; $clearSecret l'efface. Un locataire ou un identifiant invalide n'est pas
     * enregistré : renvoie false.
     *
     * @return array{saved: bool, changes: array<string, string>}
     */
    public static function save(
        string $tenant,
        string $clientId,
        #[\SensitiveParameter] string $secret,
        bool $clearSecret
    ): array {
        $tenant = mb_strtolower(trim($tenant));
        $clientId = mb_strtolower(trim($clientId));
        $secret = trim($secret);
        $valid = ($tenant === '' || EntraGraphClient::isValidTenant($tenant))
            && ($clientId === '' || EntraGraphClient::isValidClientId($clientId));
        if (!$valid) {
            return ['saved' => false, 'changes' => []];
        }
        $changes = [];
        $values = [];
        if ($tenant !== self::tenant()) {
            $values[self::TENANT_KEY] = $tenant;
            $changes[self::TENANT_KEY] = $tenant;
        }
        if ($clientId !== self::clientId()) {
            $values[self::CLIENT_KEY] = $clientId;
            $changes[self::CLIENT_KEY] = $clientId;
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

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return Config::getConfigurationValues(self::CONTEXT, [self::TENANT_KEY, self::CLIENT_KEY, self::SECRET_KEY]);
    }
}
