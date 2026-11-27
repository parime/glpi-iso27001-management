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
 * Issue #1165 : l'onglet natif « Base de connaissances » de GLPI 11 et 12
 * (KnowbaseItem_Item::getVisibilityCriteriaForItem()) joint toujours la table de l'objet et filtre
 * sur ses colonnes `entities_id` et `is_recursive`, sans vérifier qu'elles existent. La bibliothèque
 * des politiques est commune à toutes les entités et n'avait pas ces colonnes : l'ouverture d'une
 * politique ou d'une procédure d'exploitation plantait (« Unknown column ... entities_id ») pour tout
 * profil sans « Voir toutes les entités ».
 *
 * Les deux colonnes sont ajoutées avec des valeurs fixes (entité racine, récursive) : toute entité
 * voit donc les articles liés, comme avant. Policy::isEntityAssign() reste faux, la bibliothèque ne
 * devient pas cloisonnée par entité. Les colonnes disparaissent avec la table à la désinstallation.
 */
final class PolicyKnowbaseScopeTables implements FeatureTables
{
    private const POLICIES = 'glpi_plugin_grcmanager_policies';

    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        if (!$DB->tableExists(self::POLICIES)) {
            return;
        }
        if (!$DB->fieldExists(self::POLICIES, 'entities_id')) {
            $migration->addField(self::POLICIES, 'entities_id', 'fkey', [
                'comment' => 'Toujours 0 (racine) : colonne lue par l onglet Base de connaissances',
            ]);
            $migration->addKey(self::POLICIES, 'entities_id');
        }
        if (!$DB->fieldExists(self::POLICIES, 'is_recursive')) {
            $migration->addField(self::POLICIES, 'is_recursive', 'bool', [
                'value'   => 1,
                'comment' => 'Toujours 1 : bibliotheque commune a toutes les entites',
            ]);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        // Colonnes de glpi_plugin_grcmanager_policies, supprimée avec la table par Installer.php.
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}
