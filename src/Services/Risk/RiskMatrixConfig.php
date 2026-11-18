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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Loads/saves the administrable probability x impact matrix from
 * `glpi_plugin_grcmanager_riskmatrixconfig` (a single row, id=1, seeded at install with
 * RiskMatrixDefaults::MATRIX, see src/Install/Installer.php). Used by both front/config.php (the
 * admin screen) and \GlpiPlugin\Grcmanager\Risk::computeRiskLevel() (inc/risk.class.php) so the matrix
 * that actually scores a risk is always exactly what the admin screen shows/saves, not a second
 * copy of the loading logic drifting out of sync.
 *
 * NOTE: depends on GLPI's runtime global $DB, not unit-tested in isolation, same exclusion
 * rationale as the sibling plugin glpi-vulnerability-manager, see phpstan.neon.dist. The pure
 * lookup logic (RiskScoringService) IS unit-tested independently.
 *
 * Issue #706 : the same row stores the matrix size (`scale_size`, 4 by default). load() always
 * returns a complete grid for that size, whatever is stored (see RiskScale::normalizeMatrix()).
 * The size only changes through RiskScaleChange, after the administrator validated the
 * correspondence of the old values to the new ones.
 */
final class RiskMatrixConfig
{
    private const TABLE = 'glpi_plugin_grcmanager_riskmatrixconfig';

    /** @var array{size: int, matrix: array<mixed>}|null */
    private static ?array $cache = null;

    /**
     * Taille de la matrice (4 ou 5). 4 tant que la colonne n'existe pas (mise à jour en cours).
     */
    public static function size(): int
    {
        return self::read()['size'];
    }

    /**
     * @return array<string, array<string, string>> probability => impact => risk_level
     */
    public static function load(): array
    {
        $stored = self::read();

        return RiskScale::normalizeMatrix($stored['matrix'], $stored['size']);
    }

    /**
     * Service de calcul configuré avec la grille et la taille enregistrées.
     */
    public static function scoring(): RiskScoringService
    {
        return new RiskScoringService(self::load(), self::size());
    }

    /**
     * @param array<string, array<string, string>> $matrix probability => impact => risk_level
     */
    public static function save(array $matrix): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'matrix'   => json_encode(RiskScale::normalizeMatrix($matrix, self::size())),
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => 1]);
        self::reset();
    }

    /**
     * Change la taille et la grille ensemble (seul appelant : RiskScaleChange::apply()).
     *
     * @param array<string, array<string, string>> $matrix
     */
    public static function saveScale(int $size, array $matrix): void
    {
        global $DB;

        $size = RiskScale::normalizeSize($size);
        $DB->update(self::TABLE, [
            'scale_size' => $size,
            'matrix'     => json_encode(RiskScale::normalizeMatrix($matrix, $size)),
            'date_mod'   => date('Y-m-d H:i:s'),
        ], ['id' => 1]);
        self::reset();
    }

    /**
     * Oublie la configuration lue (après une écriture, ou dans les tests).
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * @return array{size: int, matrix: array<mixed>}
     */
    private static function read(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }
        $size = RiskScale::DEFAULT_SIZE;
        $matrix = RiskMatrixDefaults::MATRIX;
        if ($DB->tableExists(self::TABLE)) {
            $hasSize = $DB->fieldExists(self::TABLE, 'scale_size');
            foreach ($DB->request(['FROM' => self::TABLE, 'ORDER' => 'id', 'LIMIT' => 1]) as $row) {
                $decoded = json_decode((string) $row['matrix'], true);
                $matrix = is_array($decoded) ? $decoded : $matrix;
                $size = $hasSize ? RiskScale::normalizeSize((int) $row['scale_size']) : $size;
            }
        }
        self::$cache = ['size' => $size, 'matrix' => $matrix];

        return self::$cache;
    }
}
