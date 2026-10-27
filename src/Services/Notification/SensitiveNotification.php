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

namespace GlpiPlugin\Grcmanager\Services\Notification;

use Config;

/**
 * Notifications des registres sensibles (risques, risques fournisseurs, actions de traitement) :
 * un e-mail circule en clair et reste dans les boîtes, transferts et archives. Par défaut, il ne
 * contient donc ni le titre du risque, ni sa catégorie, ni son niveau, ni le fournisseur : seulement
 * « Risque n° X », la date et le lien vers la fiche, lue ensuite avec les droits du destinataire.
 * Réglable dans la configuration du plugin (contexte plugin:grcmanager, clé notifications_minimal).
 */
final class SensitiveNotification
{
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CONFIG_KEY = 'notifications_minimal';

    /** Activé tant qu'un administrateur ne l'a pas explicitement désactivé. */
    public static function isMinimal(): bool
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY]);

        return ($values[self::CONFIG_KEY] ?? '1') !== '0';
    }

    public static function setMinimal(bool $minimal): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY => $minimal ? '1' : '0']);
    }

    /**
     * Export d'un registre sensible (rapport PDF) : tracé dans le journal des événements de GLPI
     * (Administration > Journaux), qui dit qui a sorti le registre et quand.
     */
    public static function logExport(string $type, string $what): void
    {
        \Glpi\Event::log(
            0,
            $type,
            4,
            'tools',
            sprintf(__('%1$s exporte : %2$s', 'grcmanager'), (string) ($_SESSION['glpiname'] ?? ''), $what)
        );
    }

    /**
     * Données d'une notification : les balises sensibles sont remplacées (libellé neutre) ou vidées.
     *
     * @param array<string, mixed>  $data      balises => valeurs
     * @param array<string, string> $neutral   balises remplacées => libellé neutre
     * @param list<string>          $emptied   balises vidées
     * @return array<string, mixed>
     */
    public static function reduce(array $data, array $neutral, array $emptied): array
    {
        foreach ($neutral as $tag => $label) {
            $data[$tag] = $label;
        }
        foreach ($emptied as $tag) {
            $data[$tag] = '';
        }

        return $data;
    }
}
