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

namespace GlpiPlugin\Grcmanager;

/**
 * Gestion des jetons d'abonnement au calendrier.
 * 
 * Ces jetons sont dédiés à l'accès au calendrier iCal et sont séparés des jetons personnels GLPI.
 * Ils ont une portée limitée (lecture seule du calendrier) et peuvent être révoqués indépendamment.
 * 
 * Issue #XXX : Protéger le lien d'abonnement au calendrier
 */
class CalendarToken
{
    /**
     * Durée de validité par défaut : 30 jours.
     */
    private const DEFAULT_EXPIRATION = 30 * 24 * 3600; // 30 jours en secondes

    /**
     * Génère un jeton d'abonnement au calendrier pour un utilisateur.
     *
     * @param int $users_id ID de l'utilisateur
     * @param int $expiration Durée de validité en secondes (par défaut 30 jours)
     * @return string Le jeton généré
     */
    public static function generateForUser(int $users_id, int $expiration = self::DEFAULT_EXPIRATION): string
    {
        global $DB;
        
        $token = self::generateRandomToken();
        $expires_at = date('Y-m-d H:i:s', time() + $expiration);
        $table = self::getTable();

        // Supprimer les jetons existants pour cet utilisateur
        self::revokeForUser($users_id);

        // Créer un nouveau jeton
        $DB->insert(
            $table,
            [
                'users_id' => $users_id,
                'token' => $token,
                'expires_at' => $expires_at,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $token;
    }

    /**
     * Vérifie si un jeton est valide.
     *
     * @param string $token Le jeton à vérifier
     * @return int|false L'ID de l'utilisateur si valide, false sinon
     */
    public static function validate(string $token)
    {
        global $DB;
        
        $table = self::getTable();
        
        $result = $DB->request(
            [
                'SELECT' => ['users_id', 'expires_at'],
                'FROM' => $table,
                'WHERE' => ['token' => $token],
            ]
        );

        if ($result->count() === 0) {
            return false;
        }

        $data = $result->current();
        
        // Vérifier l'expiration
        if (strtotime($data['expires_at']) < time()) {
            // Jetons expiré, le supprimer
            self::revoke($token);
            return false;
        }

        return (int) $data['users_id'];
    }

    /**
     * Révoque un jeton spécifique.
     *
     * @param string $token Le jeton à révoquer
     * @return bool True si réussi
     */
    public static function revoke(string $token): bool
    {
        global $DB;
        
        $table = self::getTable();
        
        return $DB->delete(
            $table,
            ['token' => $token]
        );
    }

    /**
     * Révoque tous les jetons pour un utilisateur.
     *
     * @param int $users_id ID de l'utilisateur
     * @return bool True si réussi
     */
    public static function revokeForUser(int $users_id): bool
    {
        global $DB;
        
        $table = self::getTable();
        
        return $DB->delete(
            $table,
            ['users_id' => $users_id]
        );
    }

    /**
     * Obtient le jeton actuel pour un utilisateur.
     *
     * @param int $users_id ID de l'utilisateur
     * @return string|null Le jeton ou null s'il n'en a pas
     */
    public static function getForUser(int $users_id): ?string
    {
        global $DB;
        
        $table = self::getTable();
        
        $result = $DB->request(
            [
                'SELECT' => ['token', 'expires_at'],
                'FROM' => $table,
                'WHERE' => ['users_id' => $users_id],
            ]
        );

        if ($result->count() === 0) {
            return null;
        }

        $data = $result->current();
        
        // Vérifier l'expiration
        if (strtotime($data['expires_at']) < time()) {
            self::revokeForUser($users_id);
            return null;
        }

        return $data['token'];
    }

    /**
     * Génère un jeton aléatoire sécurisé.
     *
     * @return string Le jeton généré
     */
    private static function generateRandomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Retourne le nom de la table des jetons.
     *
     * @return string
     */
    public static function getTable(): string
    {
        return 'glpi_plugin_grcmanager_calendar_tokens';
    }
}
