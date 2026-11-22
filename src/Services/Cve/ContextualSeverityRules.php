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

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Issue #887 : niveau d'une vulnérabilité du parc ajusté au contexte des machines touchées
 * (ISO 27001 A.8.8), règles pures, sans base de données.
 *
 * Le niveau de départ vient des signaux de la CVE (RemediationRules::level()). Pour chaque machine
 * qui porte la version vulnérable :
 * - exposée à Internet (déclarée dans sa classification, ou publiée par un flux actif de la matrice
 *   des flux) : un cran plus urgent, sans dépasser « critique » ;
 * - sinon, en environnement de test ou de développement : un cran moins urgent, sans descendre sous
 *   « faible » ;
 * - sinon (production, ou environnement non renseigné) : inchangé.
 * Une vulnérabilité exploitée activement (CISA KEV) n'est jamais déplacée. Le niveau retenu est le
 * plus urgent des machines touchées : il suffit d'une machine de production ou exposée pour que le
 * niveau ne baisse pas. Un environnement non renseigné compte comme la production (prudence).
 */
final class ContextualSeverityRules
{
    public const ENV_PRODUCTION = 'production';
    public const ENV_TEST = 'test';
    public const ENV_DEVELOPMENT = 'development';

    /** Environnements proposés ; la chaîne vide signifie « non renseigné ». */
    public const ENVIRONMENTS = [self::ENV_PRODUCTION, self::ENV_TEST, self::ENV_DEVELOPMENT];

    /** Exposition déduite de la matrice des flux (valeur par défaut). */
    public const EXPOSURE_AUTO = '';
    public const EXPOSURE_INTERNET = 'internet';
    public const EXPOSURE_INTERNAL = 'internal';

    public const EXPOSURES = [self::EXPOSURE_AUTO, self::EXPOSURE_INTERNET, self::EXPOSURE_INTERNAL];

    public const REASON_NONE = '';
    public const REASON_EXPOSED = 'exposed';
    public const REASON_NON_PRODUCTION = 'non_production';

    public static function sanitizeEnvironment(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ENVIRONMENTS, true) ? $value : '';
    }

    public static function sanitizeExposure(mixed $value): string
    {
        return is_string($value) && in_array($value, self::EXPOSURES, true) ? $value : self::EXPOSURE_AUTO;
    }

    /**
     * Une machine est exposée quand sa classification le déclare, ou, sans déclaration, quand un flux
     * actif de publication vers Internet de la matrice des flux la vise.
     */
    public static function isExposed(string $exposure, bool $publishedByFlow): bool
    {
        return match (self::sanitizeExposure($exposure)) {
            self::EXPOSURE_INTERNET => true,
            self::EXPOSURE_INTERNAL => false,
            default                 => $publishedByFlow,
        };
    }

    public static function machineLevel(string $base, string $environment, bool $exposed): string
    {
        $index = array_search($base, RemediationRules::LEVELS, true);
        if ($index === false || $base === RemediationRules::KEV) {
            return $base;
        }
        if ($exposed) {
            // Jamais au-dessus de « critique » : KEV reste réservé à l'exploitation constatée.
            return RemediationRules::LEVELS[max(1, $index - 1)];
        }
        if (in_array(self::sanitizeEnvironment($environment), [self::ENV_TEST, self::ENV_DEVELOPMENT], true)) {
            return RemediationRules::LEVELS[min(count(RemediationRules::LEVELS) - 1, $index + 1)];
        }

        return $base;
    }

    /**
     * Niveau retenu pour une vulnérabilité : le plus urgent de ses machines.
     *
     * @param list<array{environment: string, exposed: bool}> $machines
     * @return array{level: string, reason: string}
     */
    public static function adjust(string $base, array $machines): array
    {
        if ($machines === []) {
            return ['level' => $base, 'reason' => self::REASON_NONE];
        }
        $best = null;
        foreach ($machines as $machine) {
            $level = self::machineLevel($base, (string) $machine['environment'], (bool) $machine['exposed']);
            if ($best === null || self::rank($level) < self::rank($best)) {
                $best = $level;
            }
        }

        return ['level' => $best, 'reason' => self::reason($base, $best)];
    }

    /**
     * Raison d'un écart entre le niveau de départ et le niveau retenu : plus urgent, c'est une
     * exposition à Internet ; moins urgent, des machines hors production seulement.
     */
    public static function reason(string $base, string $level): string
    {
        return match (true) {
            self::rank($level) < self::rank($base) => self::REASON_EXPOSED,
            self::rank($level) > self::rank($base) => self::REASON_NON_PRODUCTION,
            default                                => self::REASON_NONE,
        };
    }

    private static function rank(string $level): int
    {
        $index = array_search($level, RemediationRules::LEVELS, true);

        return $index === false ? count(RemediationRules::LEVELS) : (int) $index;
    }
}
