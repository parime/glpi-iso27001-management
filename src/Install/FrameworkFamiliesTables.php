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
 * - ReCyF suivait NIS2 sans réglage propre : sur une mise à jour où NIS2 était actif, ou dès que
 *   des données ReCyF existent, la déclinaison France est cochée (voir toEnable()) ;
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
        $enable = self::toEnable($disabled, $known, self::isUpdate(), $recyfUsed);
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

    /**
     * Référentiels à cocher lors de la reprise (logique pure, testée seule) :
     * - France (ReCyF) : sur une mise à jour où NIS2 était actif (le registre ReCyF suivait NIS2 et
     *   restait donc visible), ou dès que des données ReCyF existent (entité classée, fiches) ;
     * - NIS2 : si « NIS2 Italia – ACN » était actif sans NIS2 (la déclinaison Italie le suppose).
     * Une nouvelle installation (pas de mise à jour, aucune donnée) ne coche aucune déclinaison.
     *
     * @param list<string> $disabled référentiels désactivés enregistrés
     * @param list<string> $known référentiels connus au dernier enregistrement
     * @return list<string>
     */
    public static function toEnable(array $disabled, array $known, bool $update, bool $recyfUsed): array
    {
        $nis2On = !in_array(FrameworkSelection::NIS2, $disabled, true);
        $acnOn = in_array(FrameworkSelection::ACN_IT, $known, true)
            && !in_array(FrameworkSelection::ACN_IT, $disabled, true);
        $enable = [];
        if (($update && $nis2On) || $recyfUsed) {
            $enable[] = FrameworkSelection::RECYF;
        }
        if ($acnOn && !$nis2On) {
            $enable[] = FrameworkSelection::NIS2;
        }

        return $enable;
    }

    /** Mise à jour d'une installation existante (le plugin était déjà installé dans GLPI). */
    private static function isUpdate(): bool
    {
        $plugin = new \Plugin();

        return $plugin->getFromDBbyDir('grcmanager') && (int) $plugin->fields['state'] !== \Plugin::NOTINSTALLED;
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
