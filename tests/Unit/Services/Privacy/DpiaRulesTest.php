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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Privacy;

use GlpiPlugin\Grcmanager\Services\Privacy\DpiaRules;
use PHPUnit\Framework\TestCase;

final class DpiaRulesTest extends TestCase
{
    /**
     * @return array<string, array<string, int>>
     */
    private function rated(int $residualSeverity = 1, int $residualLikelihood = 1): array
    {
        $risks = [];
        foreach (DpiaRules::RISKS as $risk) {
            $risks[$risk] = [
                'severity' => 3, 'likelihood' => 3,
                'residual_severity' => $residualSeverity, 'residual_likelihood' => $residualLikelihood,
            ];
        }

        return $risks;
    }

    public function testCriteriaAreFilteredAndTheThresholdIsTwo(): void
    {
        self::assertSame(
            ['evaluation_scoring', 'large_scale'],
            DpiaRules::normalizeCriteria(['large_scale', 'inconnu', 'evaluation_scoring', 'large_scale'])
        );
        self::assertSame(['large_scale'], DpiaRules::normalizeCriteria('["large_scale"]'));
        self::assertSame([], DpiaRules::normalizeCriteria('pas du json'));
        self::assertFalse(DpiaRules::isRequired(['large_scale']));
        self::assertTrue(DpiaRules::isRequired(['large_scale', 'sensitive_data']));
    }

    public function testRisksAreBoundedAndUnknownOnesIgnored(): void
    {
        $risks = DpiaRules::normalizeRisks([
            'illegitimate_access' => ['severity' => 9, 'likelihood' => -1, 'residual_severity' => 'x'],
            'inconnu'             => ['severity' => 4],
        ]);

        self::assertSame(DpiaRules::RISKS, array_keys($risks));
        self::assertSame(4, $risks['illegitimate_access']['severity']);
        self::assertSame(0, $risks['illegitimate_access']['likelihood']);
        self::assertSame(0, $risks['illegitimate_access']['residual_severity']);
        self::assertSame(0, $risks['disappearance']['severity']);
    }

    public function testLevelNeedsBothAxesToBeHigh(): void
    {
        self::assertSame('none', DpiaRules::level(0, 3));
        self::assertSame('low', DpiaRules::level(2, 2));
        self::assertSame('medium', DpiaRules::level(4, 1));
        self::assertSame('medium', DpiaRules::level(1, 3));
        self::assertSame('high', DpiaRules::level(3, 3));
    }

    public function testPriorConsultationOnlyLooksAtResidualRisk(): void
    {
        // Risque initial élevé, résiduel faible.
        self::assertFalse(DpiaRules::needsPriorConsultation($this->rated(2, 2)));
        self::assertTrue(DpiaRules::needsPriorConsultation($this->rated(3, 4)));
        self::assertFalse(DpiaRules::needsPriorConsultation([]));
    }

    public function testWorstResidualRiskMapsToTheRegisterScales(): void
    {
        self::assertNull(DpiaRules::worstResidualRisk([]));
        $risks = $this->rated(1, 1);
        $risks['disappearance']['residual_severity'] = 4;
        $risks['disappearance']['residual_likelihood'] = 2;

        self::assertSame(
            ['risk' => 'disappearance', 'impact' => 'critical', 'probability' => 'possible'],
            DpiaRules::worstResidualRisk($risks)
        );
    }

    public function testApprovalRequiresEveryElement(): void
    {
        self::assertSame(['necessity', 'risks', 'measures', 'dpo_opinion'], DpiaRules::missingForApproval([]));

        $partial = $this->rated();
        $partial['disappearance']['residual_likelihood'] = 0;
        self::assertContains('risks', DpiaRules::missingForApproval(['risk_assessment' => json_encode($partial)]));

        self::assertSame([], DpiaRules::missingForApproval([
            'necessity' => 'Nécessaire', 'measures' => 'Chiffrement', 'dpo_opinion' => 'Favorable',
            'risk_assessment' => json_encode($this->rated()),
        ]));
    }

    public function testRecordStatusIsOnlySuggestedWhenItDiffers(): void
    {
        $two = ['large_scale', 'sensitive_data'];
        self::assertSame('required', DpiaRules::suggestedRecordStatus('not_assessed', $two, 'draft'));
        self::assertSame('required', DpiaRules::suggestedRecordStatus('not_required', $two, 'draft'));
        self::assertNull(DpiaRules::suggestedRecordStatus('required', $two, 'draft'));
        self::assertSame('done', DpiaRules::suggestedRecordStatus('required', $two, 'approved'));
        self::assertNull(DpiaRules::suggestedRecordStatus('done', $two, 'approved'));
        self::assertNull(DpiaRules::suggestedRecordStatus('done', [], 'draft'));
        self::assertSame('not_required', DpiaRules::suggestedRecordStatus('not_assessed', ['large_scale'], 'draft'));
        // Rien de coché : rien à proposer.
        self::assertNull(DpiaRules::suggestedRecordStatus('not_assessed', [], 'draft'));
    }
}
