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

use Migration;

/**
 * Issue #866 : suggestion de chaque ligne d'une revue des accès (garder, retirer, à examiner), ses
 * raisons (JSON de codes et paramètres) et les règles réglées sur la campagne (JSON).
 *
 * Champs ajoutés s'ils manquent (rejouable) ; les tables sont supprimées par la désinstallation
 * de la revue des accès (Installer::uninstall()).
 */
final class AccessReviewSuggestionFields
{
    public const CAMPAIGNS = 'glpi_plugin_grcmanager_accessreviews';
    public const LINES = 'glpi_plugin_grcmanager_accessreviews_users';

    public static function install(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::CAMPAIGNS) && !$DB->fieldExists(self::CAMPAIGNS, 'suggestion_rules')) {
            $migration->addField(self::CAMPAIGNS, 'suggestion_rules', 'text', ['null' => true]);
            $migration->migrationOneTable(self::CAMPAIGNS);
        }
        if ($DB->tableExists(self::LINES) && !$DB->fieldExists(self::LINES, 'suggestion')) {
            $migration->addField(self::LINES, 'suggestion', 'varchar(16) NOT NULL DEFAULT \'\'', [
                'after' => 'user_groups',
            ]);
            $migration->addField(self::LINES, 'suggestion_reasons', 'text', [
                'null'  => true,
                'after' => 'suggestion',
            ]);
            $migration->addField(self::LINES, 'date_suggestion', 'timestamp', [
                'null'  => true,
                'after' => 'suggestion_reasons',
            ]);
            $migration->addKey(self::LINES, 'suggestion');
            $migration->migrationOneTable(self::LINES);
        }
    }
}
