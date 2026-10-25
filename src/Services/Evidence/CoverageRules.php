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

namespace GlpiPlugin\Grcmanager\Services\Evidence;

/**
 * Issue #164 : taux de couverture des preuves (actifs d'un type qui ont le document requis).
 */
final class CoverageRules
{
    public const LEVEL_FULL    = 'full';
    public const LEVEL_PARTIAL = 'partial';
    public const LEVEL_LOW     = 'low';
    public const LEVEL_NONE    = 'none';

    /** Seuil sous lequel la couverture est jugée faible, en pourcentage. */
    public const LOW_THRESHOLD = 80;

    /**
     * Pourcentage arrondi vers le bas : 99,6 % ne s'affiche pas « 100 % » tant qu'un actif manque.
     * Null s'il n'y a aucun actif à couvrir.
     */
    public static function rate(int $covered, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        return (int) floor(100 * max(0, min($covered, $total)) / $total);
    }

    public static function level(?int $rate): string
    {
        return match (true) {
            $rate === null              => self::LEVEL_NONE,
            $rate >= 100                => self::LEVEL_FULL,
            $rate >= self::LOW_THRESHOLD => self::LEVEL_PARTIAL,
            default                     => self::LEVEL_LOW,
        };
    }

    /**
     * Nom du champ « type » d'un type d'actif (ex. NetworkEquipment => networkequipmenttypes_id).
     */
    public static function typeField(string $itemtype): string
    {
        $short = strtolower((string) preg_replace('/^.*\\\\/', '', $itemtype));

        return $short . 'types_id';
    }
}
