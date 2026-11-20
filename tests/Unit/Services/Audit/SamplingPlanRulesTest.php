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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Audit;

use GlpiPlugin\Grcmanager\Services\Audit\SamplingPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Responsibility\DefaultActivities;
use PHPUnit\Framework\TestCase;

/**
 * Issue #772, lot 2 : règles du plan d'audit de l'organisme de certification.
 */
final class SamplingPlanRulesTest extends TestCase
{
    public function testFindingIsMatchedToAProcessByLabelOrControl(): void
    {
        self::assertSame('acces logiques et physiques', Rules::normalizeLabel("  Accès  LOGIQUES et\tphysiques. "));
        self::assertTrue(Rules::findingConcernsProcess('acces logiques', '', 'Accès logiques', []));
        self::assertTrue(Rules::findingConcernsProcess('', 'a.5.18; A.8.2', 'Accès', ['A.5.15', 'A.5.18']));
        self::assertFalse(Rules::findingConcernsProcess('Fournisseurs', 'A.8.8', 'Accès', ['A.5.15']));
        self::assertFalse(Rules::findingConcernsProcess('', '', '', []), 'rien de saisi : aucun rapprochement');
        self::assertFalse(Rules::findingConcernsProcess('', '8.1', 'Exploitation', ['A.8.1']), 'clause et mesure');
        self::assertSame(['A.5.15', 'A.5.18', '9.2'], Rules::splitCodes(' a.5.15, A.5.18 /9.2 ,A.5.15'));
    }

    public function testAuditsByYearAndMatrix(): void
    {
        $audits = [
            ['id' => 3, 'status' => 'planned', 'date' => '2027-11-15'],
            ['id' => 1, 'status' => 'completed', 'date' => '2025-03-10'],
            ['id' => 2, 'status' => 'cancelled', 'date' => '2026-12-03'],
            ['id' => 4, 'status' => 'planned', 'date' => ''],
            ['id' => 5, 'status' => 'planned', 'date' => '2028-01-10'],
            ['id' => 6, 'status' => 'planned', 'date' => '2025-01-05'],
        ];
        $byYear = Rules::auditsByYear($audits, [2025, 2026, 2027]);
        self::assertSame([6, 1], array_column($byYear[2025], 'id'), 'par date');
        self::assertSame([], $byYear[2026], 'audit annulé écarté');
        self::assertSame([3], array_column($byYear[2027], 'id'));

        $matrix = Rules::matrix([10 => 'Contexte', 11 => 'Accès'], [1 => [11], 3 => [11, 10]], [1, 3]);
        self::assertSame([1 => false, 3 => true], $matrix['rows'][0]['cells']);
        self::assertSame(2, $matrix['rows'][1]['count']);
        self::assertSame(3, $matrix['sampled']);
        self::assertSame(0, $matrix['never']);
        self::assertSame(1, Rules::matrix([10 => 'Contexte'], [], [1])['never']);
    }

    public function testAuditOfTheYearAndPreviousAudits(): void
    {
        $audits = [
            ['id' => 1, 'status' => 'completed', 'date' => '2025-03-10'],
            ['id' => 2, 'status' => 'planned', 'date' => '2026-12-03'],
            ['id' => 3, 'status' => 'planned', 'date' => '2027-11-15'],
        ];
        self::assertSame(2, Rules::auditOfTheYear($audits, '2026-11-20'));
        self::assertSame(2, Rules::auditOfTheYear($audits, '2026-12-20'), 'dernier de l\'année, même passé');
        self::assertSame(3, Rules::auditOfTheYear($audits, '2027-01-02'));
        self::assertSame(3, Rules::auditOfTheYear($audits, '2030-01-02'), 'sinon le dernier');
        self::assertSame(0, Rules::auditOfTheYear([], '2026-11-20'));

        self::assertSame([1], Rules::previousAudits($audits, 2, '2026-12-03'));
        self::assertSame([1, 3], Rules::previousAudits($audits, 2, ''), 'audit sans date : tous les autres');
    }

    public function testScreensCoverOnlyKnownDefaultActivities(): void
    {
        foreach (array_keys(Rules::SCREENS) as $key) {
            self::assertTrue(DefaultActivities::exists($key), $key);
        }
        self::assertSame([], Rules::screensFor('activite_libre'));
        self::assertContains('GlpiPlugin\\Grcmanager\\AccessReview', Rules::screensFor('access_review'));
    }
}
