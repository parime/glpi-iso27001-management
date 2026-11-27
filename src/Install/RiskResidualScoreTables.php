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

use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use Migration;

/**
 * Issue #1163 : score résiduel d'un risque (rang de la probabilité résiduelle x rang de l'impact
 * résiduel), à côté du score initial `computed_score`, pour comparer le risque avant et après
 * traitement (clause 6.1.3). Vide (NULL) tant que le risque résiduel n'est pas évalué ; jamais
 * saisi, recalculé avec le niveau résiduel (Risk::computeResidualLevel()).
 *
 * Colonne ajoutée si elle manque (rejouable), puis remplie une fois pour les risques dont le
 * risque résiduel est déjà évalué. La table des risques disparaît à la désinstallation
 * (Installer::uninstall()), colonne comprise.
 */
final class RiskResidualScoreTables implements FeatureTables
{
    public const RISKS = 'glpi_plugin_grcmanager_risks';

    public static function install(string $keySign, string $charset, string $collation): void
    {
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        if (!$DB->tableExists(self::RISKS) || $DB->fieldExists(self::RISKS, 'residual_score')) {
            return;
        }
        $migration->addField(self::RISKS, 'residual_score', 'decimal(5,2) DEFAULT NULL', [
            'nodefault' => true,
            'comment'   => 'Score residuel, vide tant que le risque residuel n est pas evalue',
        ]);
        $migration->migrationOneTable(self::RISKS);

        // Sur une installation neuve, les colonnes résiduelles arrivent plus tard : rien à remplir.
        if (!$DB->fieldExists(self::RISKS, 'residual_probability')) {
            return;
        }
        $scoring = RiskMatrixConfig::scoring();
        $rows = $DB->request([
            'SELECT' => ['id', 'residual_probability', 'residual_impact'],
            'FROM'   => self::RISKS,
            'WHERE'  => [
                ['NOT' => ['residual_probability' => '']],
                ['NOT' => ['residual_impact' => '']],
            ],
        ]);
        foreach ($rows as $row) {
            $DB->update(self::RISKS, [
                'residual_score' => $scoring->score(
                    (string) $row['residual_probability'],
                    (string) $row['residual_impact']
                ),
            ], ['id' => (int) $row['id']]);
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
