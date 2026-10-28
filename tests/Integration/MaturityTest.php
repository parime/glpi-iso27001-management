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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Maturity;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #166 : score de maturité calculé sur les données réelles, historique mensuel.
 */
final class MaturityTest extends GrcmanagerIntegrationTestCase
{
    public function testScoreIsExplainedAndAuditMovesIt(): void
    {
        global $DB;

        $before = Maturity::current();
        $this->assertSame(array_keys(MaturityScore::WEIGHTS), array_keys($before['components']));
        $this->assertGreaterThanOrEqual(0, $before['score']);
        $this->assertLessThanOrEqual(100, $before['score']);

        // Un audit interne terminé ce mois-ci porte la composante « audit » à 100.
        $auditId = (int) (new \GlpiPlugin\Grcmanager\Audit())->add([
            'title'       => 'PHPUnit audit maturité',
            'status'      => 'completed',
            'actual_date' => date('Y-m-d'),
            'planned_date' => date('Y-m-d'),
        ]);
        try {
            $after = Maturity::current();
            $this->assertSame(100.0, $after['components']['audit']['value']);
            $this->assertGreaterThanOrEqual($before['score'], $after['score']);

            // Instantané du mois : une seule ligne, mise à jour à chaque passage.
            $score = Maturity::snapshot();
            Maturity::snapshot();
            $snapshots = countElementsInTable(Maturity::SNAPSHOTS_TABLE, ['month' => date('Y-m')]);
            $this->assertSame(1, $snapshots);
            $history = Maturity::history();
            $this->assertSame(['month' => date('Y-m'), 'score' => $score], end($history));

            $card = \GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService::maturityScore();
            $this->assertSame($score, $card['number']);
        } finally {
            (new \GlpiPlugin\Grcmanager\Audit())->delete(['id' => $auditId], true);
            $DB->delete(Maturity::SNAPSHOTS_TABLE, ['month' => date('Y-m')]);
        }
    }

    public function testCronIsDescribed(): void
    {
        $this->assertNotEmpty(Maturity::cronInfo('maturitysnapshot'));
        $this->assertSame([], Maturity::cronInfo('autre'));
    }
}
