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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Setup;

use GlpiPlugin\Grcmanager\Services\Setup\StartChecklistRules;
use PHPUnit\Framework\TestCase;

/** Issue #1046 : avancement de la liste de démarrage de l'administrateur. */
final class StartChecklistRulesTest extends TestCase
{
    public function testNothingDoneGivesZeroPercentAndEveryStepInOrder(): void
    {
        $summary = StartChecklistRules::summarize([]);

        $this->assertSame(0, $summary['percent']);
        $this->assertSame(0, $summary['done']);
        $this->assertSame(6, $summary['total']);
        $this->assertFalse($summary['complete']);
        $this->assertSame(StartChecklistRules::STEPS, array_column($summary['steps'], 'key'));
    }

    public function testThePercentageIsRoundedDownAndOnlyReaches100WhenEverythingIsDone(): void
    {
        $done = array_fill_keys(StartChecklistRules::STEPS, true);
        $done['soa_scope'] = false;
        $summary = StartChecklistRules::summarize($done);
        $this->assertSame(83, $summary['percent']);
        $this->assertFalse($summary['complete']);

        $done['soa_scope'] = true;
        $summary = StartChecklistRules::summarize($done + ['unknown' => true]);
        $this->assertSame(100, $summary['percent']);
        $this->assertTrue($summary['complete']);
        $this->assertCount(6, $summary['steps'], 'An unknown key adds no step.');
    }

    public function testTheMatrixIsValidatedOnceSavedOrChangedFromTheDefaultGrid(): void
    {
        $grid = ['rare' => ['low' => 'low']];

        $this->assertFalse(StartChecklistRules::isMatrixValidated('', $grid, $grid, 4, 4, ['probability' => [], 'impact' => []]));
        $this->assertTrue(StartChecklistRules::isMatrixValidated('2026-01-15 10:00:00', $grid, $grid, 4, 4, []));
        $this->assertTrue(StartChecklistRules::isMatrixValidated('', ['rare' => ['low' => 'high']], $grid, 4, 4, []));
        $this->assertTrue(StartChecklistRules::isMatrixValidated('', $grid, $grid, 5, 4, []));
        $this->assertTrue(StartChecklistRules::isMatrixValidated('', $grid, $grid, 4, 4, ['probability' => ['rare' => 'Seldom']]));
    }

    public function testAConnectorCountsOnlyAfterASuccessfulRead(): void
    {
        $this->assertFalse(StartChecklistRules::hasSuccessfulRead([]));
        $this->assertFalse(StartChecklistRules::hasSuccessfulRead([['date' => '', 'error' => '']]));
        $this->assertFalse(StartChecklistRules::hasSuccessfulRead([['date' => '2026-01-15 10:00:00', 'error' => 'timeout']]));
        $this->assertTrue(StartChecklistRules::hasSuccessfulRead([
            ['date' => '2026-01-15 10:00:00', 'error' => 'timeout'],
            ['date' => '2026-01-16 10:00:00', 'error' => ''],
        ]));
    }

    public function testTheSoaIsScopedWhenEveryQuestionHasAnAnswer(): void
    {
        $questions = ['remote_work', 'software_development'];

        $this->assertFalse(StartChecklistRules::isSoaScoped([], $questions));
        $this->assertFalse(StartChecklistRules::isSoaScoped(['remote_work' => 'yes'], $questions));
        $this->assertTrue(StartChecklistRules::isSoaScoped(['remote_work' => 'yes', 'software_development' => 'no'], $questions));
        $this->assertFalse(StartChecklistRules::isSoaScoped([], []), 'No questionnaire: nothing to tick.');
    }
}
