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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Readiness;

use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Base;
use GlpiPlugin\Grcmanager\Services\Readiness\Nis2ReadinessRules as R;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1158 : règles du parcours de mise en conformité NIS2.
 */
final class Nis2ReadinessRulesTest extends TestCase
{
    public function testCriteriaAreGroupedInOrderAndCiteAnArticle(): void
    {
        $previous = 0;
        foreach (R::CRITERIA as $key => $criterion) {
            $position = array_search($criterion['group'], R::GROUPS, true);
            self::assertNotFalse($position, $key);
            self::assertGreaterThanOrEqual($previous, $position, $key . ' : critères rangés par groupe');
            $previous = $position;
            $article = '\d+(\(\d+\))?(\([a-j]\))?';
            $pattern = '/^' . $article . '(, ' . $article . ')*$/';
            self::assertMatchesRegularExpression($pattern, $criterion['articles'], $key);
            foreach ($criterion['iso'] as $step) {
                self::assertArrayHasKey($step, Base::STEPS, $key . ' : étape ISO 27001 connue');
            }
        }
        foreach (R::RECURRING_REVIEWS as $review) {
            self::assertArrayHasKey($review, PeriodicReviewCatalog::REVIEWS, $review);
        }
    }

    public function testRecyfCriteriaOnlyWhenEnabled(): void
    {
        self::assertContains('recyf_coverage', R::criteria(true));
        self::assertContains('category', R::criteria(true));
        self::assertNotContains('recyf_coverage', R::criteria(false));
        self::assertNotContains('recyf_evidence', R::criteria(false));
        self::assertNotContains('category', R::criteria(false));
        self::assertContains('incidents', R::criteria(false));
    }

    public function testCombineIgnoresLockedSteps(): void
    {
        self::assertSame(Base::STATUS_DONE, R::combine([Base::STATUS_DONE, Base::STATUS_DONE]));
        self::assertSame(Base::STATUS_TODO, R::combine([Base::STATUS_TODO, Base::STATUS_TODO]));
        self::assertSame(Base::STATUS_PARTIAL, R::combine([Base::STATUS_DONE, Base::STATUS_TODO]));
        self::assertSame(Base::STATUS_DONE, R::combine([Base::STATUS_DONE, Base::STATUS_LOCKED]));
        self::assertSame(Base::STATUS_LOCKED, R::combine([Base::STATUS_LOCKED]));
        self::assertSame(Base::STATUS_LOCKED, R::combine([]));
    }

    public function testStatuses(): void
    {
        self::assertSame(Base::STATUS_TODO, R::category(0));
        self::assertSame(Base::STATUS_DONE, R::category(1));

        self::assertSame(Base::STATUS_TODO, R::registration(0, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::registration(1, 0), 'contact jamais vérifié');
        self::assertSame(Base::STATUS_DONE, R::registration(1, 1));

        self::assertSame(Base::STATUS_TODO, R::supplyChain(0, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::supplyChain(3, 1));
        self::assertSame(Base::STATUS_DONE, R::supplyChain(2, 2));

        self::assertSame(Base::STATUS_TODO, R::access(0, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::access(0, 1));
        self::assertSame(Base::STATUS_DONE, R::access(1, 1));

        self::assertSame(Base::STATUS_TODO, R::mfa(null, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::mfa(100, 1), 'administrateur sans MFA');
        self::assertSame(Base::STATUS_PARTIAL, R::mfa(80, 0));
        self::assertSame(Base::STATUS_DONE, R::mfa(100, 0));

        self::assertSame(Base::STATUS_TODO, R::incidents(1, 0, true), 'notification en retard');
        self::assertSame(Base::STATUS_PARTIAL, R::incidents(0, 1, true));
        self::assertSame(Base::STATUS_PARTIAL, R::incidents(0, 0, false), 'aucun CSIRT inscrit');
        self::assertSame(Base::STATUS_DONE, R::incidents(0, 0, true));

        self::assertSame(Base::STATUS_TODO, R::recyfCoverage(0, 0));
        self::assertSame(Base::STATUS_TODO, R::recyfCoverage(10, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::recyfCoverage(10, 4));
        self::assertSame(Base::STATUS_DONE, R::recyfCoverage(10, 10));

        self::assertSame(Base::STATUS_TODO, R::recyfEvidence(0, 0), 'rien de mis en œuvre');
        self::assertSame(Base::STATUS_TODO, R::recyfEvidence(3, 3));
        self::assertSame(Base::STATUS_PARTIAL, R::recyfEvidence(3, 1));
        self::assertSame(Base::STATUS_DONE, R::recyfEvidence(3, 0));

        self::assertSame(Base::STATUS_TODO, R::audit(0, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::audit(0, 2), 'audit planifié, rien de terminé');
        self::assertSame(Base::STATUS_DONE, R::audit(1, 2));

        self::assertSame(Base::STATUS_TODO, R::recurring(4, 0, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::recurring(4, 2, 0));
        self::assertSame(Base::STATUS_PARTIAL, R::recurring(4, 4, 1), 'revue en retard');
        self::assertSame(Base::STATUS_DONE, R::recurring(4, 4, 0));
    }
}
