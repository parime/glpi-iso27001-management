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

use GlpiPlugin\Grcmanager\IsmsRoles;
use Migration;

/**
 * Issue #1054 : rôles du SMSI. Aucune table : les réglages sont dans le contexte de configuration
 * du plugin (supprimé avec lui à la désinstallation). La mise à jour reprend l'ancien champ texte
 * du DPO (adresse détectée => adresse générique, le reste => note « à reprendre »).
 */
final class IsmsRolesTables implements FeatureTables
{
    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        IsmsRoles::migrateLegacyDpoContact();
    }

    public static function uninstall(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}
