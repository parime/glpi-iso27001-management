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

namespace GlpiPlugin\Grcmanager\Install;

use Config;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use Migration;

/**
 * Issue #1053 : référentiels regroupés par famille, NIS2 avec ses déclinaisons nationales. Aucune
 * table : une seule reprise du réglage des référentiels, faite une fois (clé frameworks_families),
 * pour que rien ne soit perdu :
 * - ReCyF suivait NIS2 sans réglage propre : s'il a déjà servi (entité classée ou registre créé),
 *   la déclinaison France est cochée ;
 * - « NIS2 Italia – ACN » pouvait être actif sans NIS2 : NIS2 est alors coché, avec sa déclinaison
 *   Italie.
 * Les données de chaque référentiel ne sont pas touchées. La désinstallation retire le réglage avec
 * le reste du contexte plugin:grcmanager.
 */
final class FrameworkFamiliesTables implements FeatureTables
{
    public const MIGRATED_KEY = 'frameworks_families';

    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        $values = Config::getConfigurationValues(
            FrameworkConfig::CONFIG_CONTEXT,
            [FrameworkConfig::CONFIG_KEY, FrameworkConfig::KNOWN_KEY, self::MIGRATED_KEY]
        );
        if ((string) ($values[self::MIGRATED_KEY] ?? '') === '1') {
            return;
        }
        $disabled = self::decode($values[FrameworkConfig::CONFIG_KEY] ?? null);
        $known = self::decode($values[FrameworkConfig::KNOWN_KEY] ?? null);
        $recyfUsed = false;
        foreach ([RecyfTables::ENTITIES, RecyfTables::REQUIREMENTS] as $table) {
            if ($DB->tableExists($table) && countElementsInTable($table) > 0) {
                $recyfUsed = true;
            }
        }
        $acnOn = in_array(FrameworkSelection::ACN_IT, $known, true)
            && !in_array(FrameworkSelection::ACN_IT, $disabled, true);
        $nis2On = !in_array(FrameworkSelection::NIS2, $disabled, true);
        $enable = [];
        if ($recyfUsed && $nis2On) {
            $enable[] = FrameworkSelection::RECYF;
        }
        if ($acnOn && !$nis2On) {
            $enable[] = FrameworkSelection::NIS2;
        }
        $changes = [self::MIGRATED_KEY => '1'];
        if ($enable !== []) {
            $changes[FrameworkConfig::CONFIG_KEY] = (string) json_encode(array_values(array_diff($disabled, $enable)));
            $changes[FrameworkConfig::KNOWN_KEY] = (string) json_encode(
                array_values(array_unique(array_merge($known, $enable)))
            );
        }
        Config::setConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, $changes);
        FrameworkConfig::reset();
    }

    public static function uninstall(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private static function decode(mixed $stored): array
    {
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];

        return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
    }
}
