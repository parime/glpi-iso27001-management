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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterSync;
use PHPUnit\Framework\TestCase;

/**
 * Issue #602 : plan de l'import d'un registre des risques (créations, modifications, sans doublon).
 */
final class RiskRegisterSyncTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function existing(): array
    {
        return [
            10 => [
                'external_ref' => 'R-01', 'title' => 'Vol de portable', 'description' => 'Texte saisi dans GLPI',
                'category' => 'technical', 'probability' => 'possible', 'impact' => 'high',
                'residual_probability' => '', 'residual_impact' => '', 'treatment' => 'mitigate',
                'justification' => '', 'users_id' => 11, 'review_date' => '2026-12-31', 'status' => 'in_treatment',
                'annex_a' => ['A.8.5', 'A.5.1'], 'assets' => ['Computer:5'], 'actions' => 0,
            ],
            20 => [
                'external_ref' => '', 'title' => 'Saisi dans GLPI', 'description' => '', 'category' => 'process',
                'probability' => 'rare', 'impact' => 'low', 'treatment' => '', 'users_id' => 0,
                'review_date' => null, 'status' => 'identified', 'annex_a' => [], 'assets' => [], 'actions' => 0,
            ],
            30 => ['external_ref' => 'dup', 'title' => 'A', 'status' => 'identified'],
            31 => ['external_ref' => 'DUP', 'title' => 'B', 'status' => 'identified'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{line: int, reference: string, title: string, values: array<string, mixed>}
     */
    private function row(int $line, string $reference, array $values): array
    {
        return ['line' => $line, 'reference' => $reference, 'title' => (string) $values['title'], 'values' => $values];
    }

    public function testUpsertByReferenceKeepsWhatTheFileDoesNotSay(): void
    {
        $plan = RiskRegisterSync::plan($this->existing(), [
            // Même risque, casse différente, seuls titre et impact donnés : la description reste.
            $this->row(2, 'r-01', ['title' => 'Vol de portable', 'impact' => 'critical', 'annex_a' => ['A.5.1', 'A.8.5']]),
            $this->row(3, 'R-NEW', ['title' => 'Nouveau risque', 'impact' => 'low', 'assets' => ['Computer:5']]),
            $this->row(4, 'GLPI-RISK-20', ['title' => 'Saisi dans GLPI', 'probability' => 'rare']),
            $this->row(5, 'dup', ['title' => 'Ambigu']),
        ]);

        $this->assertCount(1, $plan['errors']);
        $this->assertSame(RiskRegisterSync::ERROR_AMBIGUOUS_REFERENCE, $plan['errors'][0]['reason']);
        [$update, $create, $unchanged] = $plan['steps'];

        $this->assertSame(RiskRegisterSync::UPDATE, $update['action']);
        $this->assertSame(10, $update['id']);
        $this->assertSame(['impact' => 'critical'], $update['fields']);
        $this->assertSame([], $update['lists'], 'Mêmes mesures dans un autre ordre : pas de changement.');
        $this->assertSame(['impact' => ['old' => 'high', 'new' => 'critical']], $update['changes']);

        $this->assertSame(RiskRegisterSync::CREATE, $create['action']);
        $this->assertSame([
            'external_ref' => 'R-NEW',
            'title'        => 'Nouveau risque',
            'category'     => 'process',
            'probability'  => 'possible',
            'impact'       => 'low',
            'status'       => 'identified',
        ], $create['fields']);
        $this->assertSame(['assets' => ['Computer:5']], $create['lists']);

        $this->assertSame(RiskRegisterSync::UNCHANGED, $unchanged['action']);
        $this->assertSame(20, $unchanged['id']);
        $this->assertSame(
            [RiskRegisterSync::CREATE => 1, RiskRegisterSync::UPDATE => 1, RiskRegisterSync::UNCHANGED => 1],
            RiskRegisterSync::summary($plan['steps'])
        );
    }

    public function testStatusOnlyChangesWhenTheFileSaysSo(): void
    {
        $plan = RiskRegisterSync::plan($this->existing(), [
            $this->row(2, 'R-01', ['title' => 'Vol de portable', 'treatment' => 'accept']),
        ]);
        $this->assertArrayNotHasKey('status', $plan['steps'][0]['fields']);

        $plan = RiskRegisterSync::plan($this->existing(), [
            $this->row(2, 'R-01', ['title' => 'Vol de portable', 'treatment' => 'accept', 'status' => 'accepted']),
        ]);
        $this->assertSame(['old' => 'in_treatment', 'new' => 'accepted'], $plan['steps'][0]['changes']['status']);
    }

    public function testClosingAMitigatedRiskWithoutPlanIsRejected(): void
    {
        $plan = RiskRegisterSync::plan($this->existing(), [
            $this->row(2, 'R-01', ['title' => 'Vol de portable', 'status' => 'closed']),
            $this->row(3, 'R-02', ['title' => 'Nouveau', 'status' => 'closed', 'treatment' => 'transfer']),
            $this->row(4, 'R-03', ['title' => 'Nouveau évité', 'status' => 'closed', 'treatment' => 'avoid']),
        ]);

        $this->assertSame([2, 3], array_column($plan['errors'], 'line'));
        $this->assertSame(RiskRegisterSync::ERROR_CLOSE_WITHOUT_PLAN, $plan['errors'][0]['reason']);
        $this->assertCount(1, $plan['steps']);
    }

    public function testReimportOfTheSameFileChangesNothing(): void
    {
        $existing = $this->existing();
        $plan = RiskRegisterSync::plan($existing, [
            $this->row(2, 'R-01', [
                'title' => 'Vol de portable', 'description' => 'Texte saisi dans GLPI', 'category' => 'technical',
                'probability' => 'possible', 'impact' => 'high', 'treatment' => 'mitigate', 'users_id' => 11,
                'review_date' => '2026-12-31', 'status' => 'in_treatment', 'annex_a' => ['A.5.1', 'A.8.5'],
                'assets' => ['Computer:5'],
            ]),
        ]);

        $this->assertSame(RiskRegisterSync::UNCHANGED, $plan['steps'][0]['action']);
        $this->assertSame([], $plan['steps'][0]['changes']);
    }
}
