<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
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

namespace GlpiPlugin\Grcmanager;

use Config;

/**
 * Issue #664 : jeton dédié au lien d'abonnement du calendrier de conformité.
 *
 * Avant, le lien portait le jeton personnel GLPI de l'utilisateur, qui ouvre aussi l'API GLPI avec
 * tous ses droits : un lien qui fuit (journal d'un serveur, historique, partage d'agenda) donnait
 * bien plus que le calendrier. Désormais :
 *
 * - un jeton propre au calendrier, un par utilisateur : `<id>.<secret>`, le secret étant 32 octets
 *   aléatoires (random_bytes) en hexadécimal ;
 * - seule l'empreinte SHA-256 du secret est conservée ; la ligne est retrouvée par son numéro et
 *   l'empreinte comparée en temps constant (hash_equals) ;
 * - il n'ouvre que le flux iCal en lecture (front/compliancecalendar.ics.php), jamais l'API ni une
 *   autre page ; le jeton personnel GLPI n'y est plus accepté ;
 * - il expire (durée réglable dans la configuration du plugin, 365 jours par défaut : un agenda
 *   abonné le relit sans intervention, une durée courte casserait l'abonnement sans prévenir) ;
 * - l'utilisateur le régénère ou le révoque depuis le calendrier ; l'ancien lien cesse aussitôt.
 *
 * Le lien complet n'est montré qu'une fois, juste après sa création (il n'est pas conservé en
 * clair) : la page affiche ensuite seulement ses dates.
 */
final class CalendarToken
{
    public const TABLE = 'glpi_plugin_grcmanager_calendartokens';
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const LIFETIME_KEY = 'calendar_token_lifetime_days';
    public const DEFAULT_LIFETIME_DAYS = 365;
    public const MIN_LIFETIME_DAYS = 1;
    public const MAX_LIFETIME_DAYS = 1825;

    private const SECRET_BYTES = 32;
    private const SESSION_KEY = 'grcmanager_calendar_token_once';

    public static function lifetimeDays(): int
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::LIFETIME_KEY]);

        return self::normalizeLifetime($values[self::LIFETIME_KEY] ?? null);
    }

    /**
     * Une valeur absente, non numérique ou hors bornes revient à la durée par défaut.
     */
    public static function normalizeLifetime(mixed $days): int
    {
        if (!is_numeric($days)) {
            return self::DEFAULT_LIFETIME_DAYS;
        }
        $days = (int) $days;

        return $days >= self::MIN_LIFETIME_DAYS && $days <= self::MAX_LIFETIME_DAYS ? $days : self::DEFAULT_LIFETIME_DAYS;
    }

    /**
     * @return bool vrai si le réglage a changé
     */
    public static function saveLifetime(mixed $days): bool
    {
        $before = self::lifetimeDays();
        $after = self::normalizeLifetime($days);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::LIFETIME_KEY => $after]);

        return $before !== $after;
    }

    /**
     * Crée (ou remplace) le jeton de l'utilisateur et renvoie sa valeur en clair, seule fois où elle
     * existe : l'ancien lien cesse aussitôt de fonctionner.
     */
    public static function generate(int $usersId): string
    {
        global $DB;

        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $now = date('Y-m-d H:i:s');
        $DB->delete(self::TABLE, ['users_id' => $usersId]);
        $DB->insert(self::TABLE, [
            'users_id'        => $usersId,
            'token_hash'      => hash('sha256', $secret),
            'date_expiration' => date('Y-m-d H:i:s', strtotime('+' . self::lifetimeDays() . ' days')),
            'date_creation'   => $now,
        ]);

        return $DB->insertId() . '.' . $secret;
    }

    public static function revoke(int $usersId): bool
    {
        global $DB;

        $DB->delete(self::TABLE, ['users_id' => $usersId]);

        return $DB->affectedRows() > 0;
    }

    /**
     * État du jeton de l'utilisateur, sans le secret : null s'il n'en a pas.
     *
     * @return array{date_creation: string, date_expiration: string, date_last_use: string, expired: bool}|null
     */
    public static function status(int $usersId): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $usersId]])->current();
        if (!is_array($row)) {
            return null;
        }

        return [
            'date_creation'   => (string) $row['date_creation'],
            'date_expiration' => (string) $row['date_expiration'],
            'date_last_use'   => (string) ($row['date_last_use'] ?? ''),
            'expired'         => (string) $row['date_expiration'] <= date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Utilisateur propriétaire d'un jeton valide (bon format, empreinte identique, non expiré), ou
     * null. Un jeton personnel GLPI n'a pas ce format : il est toujours refusé.
     */
    public static function findUser(string $token): ?int
    {
        global $DB;

        if (preg_match('/^([1-9][0-9]{0,9})\.([0-9a-f]{64})$/D', $token, $matches) !== 1) {
            return null;
        }
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => (int) $matches[1]]])->current();
        $expected = is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64);
        // Comparaison en temps constant, même quand la ligne n'existe pas.
        if (!hash_equals($expected, hash('sha256', $matches[2])) || !is_array($row)) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        if ((string) $row['date_expiration'] === '' || (string) $row['date_expiration'] <= $now) {
            return null;
        }
        $DB->update(self::TABLE, ['date_last_use' => $now], ['id' => (int) $row['id']]);

        return (int) $row['users_id'];
    }

    /**
     * Lien d'abonnement complet pour un jeton en clair.
     */
    public static function feedURL(string $token, bool $mine): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/compliancecalendar.ics.php?'
            . http_build_query(['token' => $token] + ($mine ? ['mine' => 1] : []));
    }

    /**
     * Garde le jeton en clair pour l'affichage suivant seulement (après la redirection).
     */
    public static function rememberForDisplay(string $token): void
    {
        $_SESSION[self::SESSION_KEY] = $token;
    }

    public static function pullForDisplay(): ?string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
