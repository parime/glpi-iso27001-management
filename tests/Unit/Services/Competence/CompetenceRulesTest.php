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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Competence;

use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\Competence\DefaultCompetences;
use PHPUnit\Framework\TestCase;

/**
 * Issue #774 : règles des compétences (article 7.2), sur des données fictives.
 */
final class CompetenceRulesTest extends TestCase
{
    private const TODAY = '2026-11-20';

    public function testNormalization(): void
    {
        $this->assertSame(1, Rules::normalizeLevel('9'));
        $this->assertSame(3, Rules::normalizeLevel('3'));
        $this->assertSame('training', Rules::normalizeMode('<b>'));
        $this->assertSame('diploma', Rules::normalizeMode('diploma'));
        $this->assertNull(Rules::normalizeEvidenceType('bogus'));
        $this->assertSame('2026-02-28', Rules::normalizeDate('2026-02-28 10:00:00'));
        $this->assertNull(Rules::normalizeDate('2026-02-30'));
        $this->assertNull(Rules::normalizeDate(''));
    }

    public function testTrainingExpiryAndPass(): void
    {
        $this->assertSame('2027-01-15', Rules::trainingExpiry('2026-01-15', 12));
        $this->assertNull(Rules::trainingExpiry('2026-01-15', 0));
        $this->assertNull(Rules::trainingExpiry(null, 12));
        $this->assertTrue(Rules::trainingPassed('completed', 'not_applicable'));
        $this->assertTrue(Rules::trainingPassed('completed', 'passed'));
        $this->assertFalse(Rules::trainingPassed('completed', 'failed'));
        $this->assertFalse(Rules::trainingPassed('pending', 'passed'));
    }

    public function testStatus(): void
    {
        $this->assertSame(Rules::STATUS_MISSING, Rules::status(2, [], self::TODAY));
        $this->assertSame(Rules::STATUS_PARTIAL, Rules::status(2, [['level' => 1, 'expires' => null]], self::TODAY));
        $this->assertSame(Rules::STATUS_MET, Rules::status(2, [
            ['level' => 1, 'expires' => null], ['level' => 3, 'expires' => self::TODAY],
        ], self::TODAY));
        $this->assertSame(
            Rules::STATUS_EXPIRED,
            Rules::status(1, [['level' => 3, 'expires' => '2026-11-19']], self::TODAY)
        );
        $this->assertSame(['level' => 1, 'expired' => true], Rules::acquired([
            ['level' => 3, 'expires' => '2025-01-01'], ['level' => 1, 'expires' => null],
        ], self::TODAY));
    }

    public function testRequiredByPersonKeepsTheHighestLevel(): void
    {
        $required = Rules::requiredByPerson(
            [10 => [1, 2, 2], 11 => [2]],
            [1 => [100 => 3, 101 => 1], 2 => [100 => 1]]
        );
        $this->assertSame(['level' => 3, 'functions' => [1, 2]], $required[10][100]);
        $this->assertSame(['level' => 1, 'functions' => [1]], $required[10][101]);
        $this->assertSame([100 => ['level' => 1, 'functions' => [2]]], $required[11]);
    }

    public function testProposedTrainingsAndSummary(): void
    {
        $this->assertSame([5, 7], Rules::proposedTrainings(2, [7 => 3, 6 => 1, 5 => 2]));
        $this->assertSame([], Rules::proposedTrainings(3, [6 => 1]));
        $this->assertSame(
            ['people' => 2, 'people_with_gaps' => 1, 'required' => 4, 'gaps' => 1, 'rate' => 75],
            Rules::summary([1 => [1 => 'met', 2 => 'met'], 2 => [1 => 'partial', 2 => 'met']])
        );
        $this->assertNull(Rules::summary([])['rate']);
        $this->assertTrue(Rules::hasEvidence('Certificat 1', 0));
        $this->assertFalse(Rules::hasEvidence('  ', 0));
    }

    public function testDefaultCatalogIsConsistent(): void
    {
        foreach (DefaultCompetences::FUNCTIONS as $requirements) {
            foreach ($requirements as $competence => [$level, $mode]) {
                $this->assertTrue(DefaultCompetences::competenceExists($competence));
                $this->assertContains($level, Rules::LEVELS);
                $this->assertContains($mode, Rules::MODES);
            }
        }
    }
}
