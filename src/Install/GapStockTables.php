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
 * Issue #870 : date de clôture des non-conformités (`date_closed`), pour le délai moyen de clôture
 * du rapport du stock d'écarts. Ajoutée si elle manque (rejouable) ; les constats déjà clos
 * reçoivent la date de vérification de la clôture, sinon leur date de dernière modification.
 * La table appartient aux audits et CAPA : supprimée par la désinstallation du cœur.
 */
final class GapStockTables implements FeatureTables
{
    public const NONCONFORMITIES = 'glpi_plugin_grcmanager_nonconformities';

    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        if (!$DB->tableExists(self::NONCONFORMITIES) || $DB->fieldExists(self::NONCONFORMITIES, 'date_closed')) {
            return;
        }
        $migration->addField(self::NONCONFORMITIES, 'date_closed', 'timestamp', [
            'null'    => true,
            'after'   => 'closure_verification_date',
            'comment' => 'Date de passage en statut clos ou verifie',
        ]);
        $migration->addKey(self::NONCONFORMITIES, 'date_closed');
        $migration->migrationOneTable(self::NONCONFORMITIES);

        foreach (
            $DB->request([
                'SELECT' => ['id', 'closure_verification_date', 'date_mod', 'date_creation'],
                'FROM'   => self::NONCONFORMITIES,
                'WHERE'  => ['status' => ['closed', 'verified']],
            ]) as $row
        ) {
            $date = $row['closure_verification_date'] ?? null;
            $date = $date !== null && $date !== '' ? $date . ' 00:00:00' : ($row['date_mod'] ?? $row['date_creation']);
            if ($date !== null) {
                $DB->update(self::NONCONFORMITIES, ['date_closed' => $date], ['id' => (int) $row['id']]);
            }
        }
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}
