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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AdAuditRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #835 : lecture des scores, gravité des règles, mesures de l'Annexe A, comparaison d'imports.
 */
final class AdAuditRulesTest extends TestCase
{
    public function testCategories(): void
    {
        $this->assertSame(Rules::CATEGORY_STALE, Rules::normalizeCategory('StaleObjects'));
        $this->assertSame(Rules::CATEGORY_PRIVILEGED, Rules::normalizeCategory('PrivilegiedAccounts'));
        $this->assertSame(Rules::CATEGORY_PRIVILEGED, Rules::normalizeCategory(' privilegedaccounts '));
        $this->assertSame(Rules::CATEGORY_TRUST, Rules::normalizeCategory('Trusts'));
        $this->assertSame(Rules::CATEGORY_ANOMALY, Rules::normalizeCategory('Anomalies'));
        $this->assertSame(Rules::CATEGORY_OTHER, Rules::normalizeCategory('Unknown'));
    }

    public function testScoreLevelsAndSeverity(): void
    {
        $this->assertNull(Rules::scoreLevel(null));
        $this->assertSame(Rules::LEVEL_GOOD, Rules::scoreLevel(0));
        $this->assertSame(Rules::LEVEL_GOOD, Rules::scoreLevel(20));
        $this->assertSame(Rules::LEVEL_WATCH, Rules::scoreLevel(21));
        $this->assertSame(Rules::LEVEL_WATCH, Rules::scoreLevel(50));
        $this->assertSame(Rules::LEVEL_POOR, Rules::scoreLevel(51));
        $this->assertSame(0, Rules::clampScore(-3));
        $this->assertSame(100, Rules::clampScore(250));

        $this->assertSame(Rules::SEVERITY_INFO, Rules::severity(0));
        $this->assertSame(Rules::SEVERITY_LOW, Rules::severity(5));
        $this->assertSame(Rules::SEVERITY_MEDIUM, Rules::severity(10));
        $this->assertSame(Rules::SEVERITY_HIGH, Rules::severity(30));
        $this->assertSame(['finding_type' => 'nonconformity', 'severity' => 'major'], Rules::findingPreset(50));
        $this->assertSame(['finding_type' => 'nonconformity', 'severity' => 'minor'], Rules::findingPreset(15));
        $this->assertSame(['finding_type' => 'observation', 'severity' => 'minor'], Rules::findingPreset(1));
    }

    public function testAnnexAControls(): void
    {
        $this->assertSame(['A.5.18', 'A.8.8'], Rules::controls(Rules::CATEGORY_STALE, 'S-Inactive'));
        $this->assertSame(['A.5.18', 'A.8.8'], Rules::controls(Rules::CATEGORY_STALE, 'S-OS-2012'));
        $this->assertSame(['A.5.15', 'A.8.2'], Rules::controls(Rules::CATEGORY_PRIVILEGED, 'P-Delegated'));
        $this->assertSame(
            ['A.5.15', 'A.5.17', 'A.8.2', 'A.8.5'],
            Rules::controls(Rules::CATEGORY_PRIVILEGED, 'P-Kerberoasting')
        );
        $this->assertSame(
            ['A.5.17', 'A.8.5', 'A.8.9'],
            Rules::controls(Rules::CATEGORY_ANOMALY, 'A-LAPS-Not-Installed')
        );
        $this->assertSame(['A.8.5', 'A.8.9', 'A.8.20'], Rules::controls(Rules::CATEGORY_ANOMALY, 'A-NoGPOLLMNR'));
        $this->assertSame(['A.8.9'], Rules::controls('nonexistent', 'X'));
        foreach (Rules::EVIDENCE_CONTROLS as $code) {
            $this->assertMatchesRegularExpression('/^A\.\d+\.\d+$/', $code);
        }
    }

    public function testDiffAndTrend(): void
    {
        $this->assertSame(
            ['new' => [], 'resolved' => [], 'unchanged' => ['A', 'B']],
            Rules::diff(['A', 'B', 'A'], null),
            'Premier import : rien d\'apparu ni de résolu.'
        );
        $this->assertSame(
            ['new' => ['C'], 'resolved' => ['A'], 'unchanged' => ['B']],
            Rules::diff(['B', 'C'], ['A', 'B'])
        );
        $this->assertNull(Rules::trend(40, null));
        $this->assertSame(-15, Rules::trend(40, 55));
        $this->assertSame(5, Rules::trend(60, 55));
    }

    public function testDomainNormalization(): void
    {
        $this->assertSame('exemple.test', Rules::normalizeDomain(' EXEMPLE.test '));
        $this->assertSame('ad-01.exemple.test', Rules::normalizeDomain('ad-01.exemple.test'));
        $this->assertSame('', Rules::normalizeDomain('<script>'));
        $this->assertSame('', Rules::normalizeDomain('exemple..test'));
        $this->assertSame('', Rules::normalizeDomain(''));
    }
}
