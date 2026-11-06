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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #403 : référentiels de conformité activables un par un. Logique pure (aucune dépendance à
 * GLPI) : la lecture et l'écriture du réglage sont dans FrameworkConfig.
 *
 * Le réglage enregistre les référentiels DÉSACTIVÉS, pas les actifs : une installation existante
 * (réglage absent) garde tout actif, et un référentiel ajouté plus tard est actif par défaut.
 * Désactiver ne supprime rien : seuls l'affichage et les calculs l'ignorent.
 */
final class FrameworkSelection
{
    public const ISO27001 = 'iso27001';
    public const NIS2 = 'nis2';
    public const ANSSI = 'anssi';
    public const NIST_CSF = 'nist_csf';
    public const CIS_CONTROLS = 'cis_controls';
    public const ISO27090 = 'iso27090';
    public const DORA = 'dora';

    /**
     * Issue #379 : référentiels sectoriels, désactivés tant que personne ne les a activés (une
     * installation existante ne les voit pas apparaître d'office).
     */
    public const OPTIONAL = [self::DORA];

    /**
     * Référentiels activables, dans l'ordre d'affichage, avec leur nom (nom propre, non traduit).
     *
     * @var array<string, string>
     */
    public const FRAMEWORKS = [
        self::ISO27001     => 'ISO/IEC 27001:2022',
        self::NIS2         => 'NIS2',
        self::ANSSI        => 'ANSSI',
        self::NIST_CSF     => 'NIST CSF 2.0',
        self::CIS_CONTROLS => 'CIS Controls v8',
        self::ISO27090     => 'ISO/IEC 27090:2026',
        self::DORA         => 'DORA',
    ];

    /**
     * Référentiels désactivés lus depuis la valeur enregistrée (liste JSON). Une valeur absente,
     * illisible ou inconnue ne désactive rien.
     *
     * @return list<string>
     */
    public static function decode(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::known($decoded);
    }

    /**
     * Valeur à enregistrer pour les référentiels cochés : ceux qui ne le sont pas (liste JSON).
     *
     * @param array<mixed> $enabled
     */
    public static function encode(array $enabled): string
    {
        $enabled = self::known($enabled);

        return (string) json_encode(array_values(array_diff(array_keys(self::FRAMEWORKS), $enabled)));
    }

    /**
     * Référentiels actifs, dans l'ordre d'affichage.
     *
     * @param list<string> $disabled
     * @return list<string>
     */
    public static function enabled(array $disabled): array
    {
        return array_values(array_diff(array_keys(self::FRAMEWORKS), $disabled));
    }

    /**
     * Valeurs connues seulement, sans doublon, dans l'ordre d'affichage.
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function known(array $values): array
    {
        $values = array_map('strval', array_filter($values, 'is_scalar'));

        return array_values(array_intersect(array_keys(self::FRAMEWORKS), $values));
    }
}
