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

use GlpiPlugin\Grcmanager\Services\Audit\DuplicationRules;
use Migration;

/**
 * Issue #881 : audit d'origine d'un audit préparé pour le cycle suivant (0 pour un audit créé à la
 * main). Colonne ajoutée si absente (rejouable) ; elle disparaît avec la table des audits à la
 * désinstallation (Installer::uninstall()).
 */
final class AuditDuplicationTables implements FeatureTables
{
    public const AUDITS = 'glpi_plugin_grcmanager_audits';

    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::AUDITS) && !$DB->fieldExists(self::AUDITS, DuplicationRules::SOURCE_FIELD)) {
            $migration->addField(self::AUDITS, DuplicationRules::SOURCE_FIELD, 'fkey', [
                'comment' => 'Audit d origine (issue #881)',
            ]);
            $migration->addKey(self::AUDITS, DuplicationRules::SOURCE_FIELD);
            $migration->migrationOneTable(self::AUDITS);
        }
    }

    public static function uninstall(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}
