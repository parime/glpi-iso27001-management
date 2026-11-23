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

namespace GlpiPlugin\Grcmanager\Services\Cartography;

/**
 * Issue #1082 : couches de la cartographie du SI (urbanisation), du réseau au processus métier.
 * Un type d'élément appartient à une seule couche ; un type inconnu (matériel, actif personnalisé)
 * va dans la couche des matériels.
 */
final class Layers
{
    public const NETWORK = 1;
    public const INFRASTRUCTURE = 2;
    public const APPLICATION = 3;
    public const BUSINESS = 4;

    /** Du bas vers le haut. */
    public const ALL = [self::NETWORK, self::INFRASTRUCTURE, self::APPLICATION, self::BUSINESS];

    public const DOCKER_SERVICE = 'GlpiPlugin\\Dockerinventory\\Service';
    public const BUSINESS_PROCESS = 'GlpiPlugin\\Grcmanager\\BusinessProcess';

    private const BY_ITEMTYPE = [
        'NetworkEquipment'     => self::NETWORK,
        'PassiveDCEquipment'   => self::NETWORK,
        'Software'             => self::APPLICATION,
        'Appliance'            => self::APPLICATION,
        'DatabaseInstance'     => self::APPLICATION,
        'Database'             => self::APPLICATION,
        self::DOCKER_SERVICE   => self::APPLICATION,
        self::BUSINESS_PROCESS => self::BUSINESS,
    ];

    public static function of(string $itemtype): int
    {
        return self::BY_ITEMTYPE[$itemtype] ?? self::INFRASTRUCTURE;
    }

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::NETWORK        => __('Network', 'grcmanager'),
            self::INFRASTRUCTURE => __('Servers, workstations and virtualisation', 'grcmanager'),
            self::APPLICATION    => __('Applications', 'grcmanager'),
            self::BUSINESS       => __('Business processes', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function icons(): array
    {
        return [
            self::NETWORK        => 'ti-network',
            self::INFRASTRUCTURE => 'ti-server',
            self::APPLICATION    => 'ti-apps',
            self::BUSINESS       => 'ti-building',
        ];
    }

    /**
     * Sens d'une connexion réseau entre deux éléments : l'élément de la couche la plus basse porte
     * l'autre. Entre deux éléments de la même couche (deux switchs), aucun sens : le plugin ne
     * connaît pas la redondance, la liaison ne propage pas l'impact.
     *
     * @return int -1 : $a porte $b ; 1 : $b porte $a ; 0 : aucun sens
     */
    public static function direction(string $a, string $b): int
    {
        return self::of($a) <=> self::of($b);
    }
}
