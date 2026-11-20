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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\SampleRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #607 : tirage d'un échantillon (reproductibilité, absence de doublon), résultat proposé à
 * partir des écarts et relecture des tests. Données fictives : identifiants 1 à 500.
 */
final class SampleRulesTest extends TestCase
{
    public function testSameSeedAndPopulationAlwaysGiveTheSameSample(): void
    {
        $population = range(1, 500);
        $first = SampleRules::draw($population, 25, 'audit-2026-exemple');
        self::assertCount(25, $first);
        self::assertSame($first, SampleRules::draw($population, 25, 'audit-2026-exemple'));

        // L'ordre de lecture de la population ne change rien.
        $shuffled = $population;
        shuffle($shuffled);
        self::assertSame($first, SampleRules::draw($shuffled, 25, 'audit-2026-exemple'));

        // Une plus petite taille donne le début du même classement.
        self::assertSame(array_slice($first, 0, 5), SampleRules::draw($population, 5, 'audit-2026-exemple'));

        // Une autre graine donne un autre échantillon.
        self::assertNotSame($first, SampleRules::draw($population, 25, 'autre-graine'));
    }

    public function testDrawHasNoDuplicateAndStaysInThePopulation(): void
    {
        $population = [7, 7, 12, 12, 12, 40, 41, '42', 0, -3];
        $drawn = SampleRules::draw($population, 50, 'graine');
        self::assertSame(count($drawn), count(array_unique($drawn)), 'Aucun doublon.');
        sort($drawn);
        self::assertSame([7, 12, 40, 41, 42], $drawn, 'Tirage sans remise, identifiants invalides ignorés.');
        self::assertSame([], SampleRules::draw([], 10, 'graine'));
        self::assertSame([], SampleRules::draw([1, 2, 3], 0, 'graine'));
    }

    public function testRankingIsTheDocumentedSha256Fingerprint(): void
    {
        self::assertSame(hash('sha256', 'abc:42'), SampleRules::rankKey('abc', 42));
        $keys = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $keys[$id] = hash('sha256', 'abc:' . $id);
        }
        asort($keys);
        self::assertSame(array_slice(array_keys($keys), 0, 2), SampleRules::draw([5, 4, 3, 2, 1], 2, 'abc'));
    }

    public function testSampleSizeFromFrequencyOrInput(): void
    {
        self::assertSame(25, SampleRules::sampleSize('', 'daily', 1000));
        self::assertSame(2, SampleRules::sampleSize(null, 'quarterly', 1000));
        self::assertSame(1, SampleRules::sampleSize('0', 'annual', 1000));
        $default = SampleRules::FREQUENCY_SIZES[SampleRules::DEFAULT_FREQUENCY];
        self::assertSame($default, SampleRules::sampleSize('', 'x', 1000));
        self::assertSame(10, SampleRules::sampleSize('10', 'daily', 1000));
        self::assertSame(3, SampleRules::sampleSize('10', 'daily', 3), 'Jamais plus que la population.');
        self::assertSame(SampleRules::MAX_SAMPLE, SampleRules::sampleSize('100000', 'daily', 100000));
    }

    public function testSeedPeriodAndPopulationNormalisation(): void
    {
        self::assertSame('abc-DEF_123', SampleRules::normalizeSeed(' abc-DEF_123 '));
        self::assertNull(SampleRules::normalizeSeed('a b'));
        self::assertNull(SampleRules::normalizeSeed('<script>'));
        self::assertNull(SampleRules::normalizeSeed(str_repeat('a', 65)));
        self::assertNull(SampleRules::normalizeSeed(''));
        self::assertMatchesRegularExpression(SampleRules::SEED_PATTERN, SampleRules::newSeed());
        self::assertNotSame(SampleRules::newSeed(), SampleRules::newSeed());

        self::assertSame(['2026-03-01', '2026-03-31'], SampleRules::period('2026-03-01', '2026-03-31'));
        self::assertSame([null, '2026-03-31'], SampleRules::period('', '2026-03-31'));
        self::assertNull(SampleRules::period('2026-04-01', '2026-03-31'));

        self::assertSame('Change', SampleRules::normalizePopulation('Change'));
        self::assertNull(SampleRules::normalizePopulation('Profile'));
        self::assertNull(SampleRules::normalizePopulation(['User']));
    }

    public function testProposedResultFromDeviations(): void
    {
        $items = static fn (array $results): array => array_map(
            static fn (string $r): array => ['result' => $r, 'comment' => $r === 'compliant' ? '' : 'Ecart fictif'],
            $results
        );

        $all = SampleRules::tally($items(array_fill(0, 10, 'compliant')));
        self::assertSame(ControlTestRules::RESULT_EFFECTIVE, SampleRules::proposedResult($all));

        // 1 écart sur 10 : 90 % conformes, au-dessus du seuil.
        $one = SampleRules::tally($items([...array_fill(0, 9, 'compliant'), 'noncompliant']));
        self::assertSame(1, $one['noncompliant']);
        self::assertSame(ControlTestRules::RESULT_PARTIAL, SampleRules::proposedResult($one));

        $many = SampleRules::tally($items([...array_fill(0, 5, 'compliant'), ...array_fill(0, 5, 'noncompliant')]));
        self::assertSame(ControlTestRules::RESULT_INEFFECTIVE, SampleRules::proposedResult($many));

        // Les non applicables ne comptent pas.
        $na = SampleRules::tally($items(['compliant', 'na', 'na']));
        self::assertSame(ControlTestRules::RESULT_EFFECTIVE, SampleRules::proposedResult($na));
        self::assertNull(SampleRules::proposedResult(SampleRules::tally($items(['na', 'na']))));

        // Rien n'est proposé tant qu'un élément reste à évaluer.
        $pending = SampleRules::tally([['result' => 'compliant'], ['result' => ''], ['result' => 'inconnu']]);
        self::assertSame(2, $pending['pending']);
        self::assertNull(SampleRules::proposedResult($pending));
    }

    public function testRecordingRequiresEveryItemAndAJustifiedOverride(): void
    {
        $done = SampleRules::tally([
            ['result' => 'compliant'],
            ['result' => 'noncompliant', 'comment' => 'Sans validation'],
        ]);
        $proposed = SampleRules::proposedResult($done);
        self::assertSame(ControlTestRules::RESULT_INEFFECTIVE, $proposed);
        self::assertNull(SampleRules::recordError($done, $proposed, (string) $proposed, ''));
        self::assertSame('justification', SampleRules::recordError($done, $proposed, 'partial', '  '));
        self::assertNull(SampleRules::recordError($done, $proposed, 'partial', 'Ecart corrige le jour meme'));
        self::assertSame('result', SampleRules::recordError($done, $proposed, 'maybe', 'x'));

        $missing = SampleRules::tally([['result' => 'noncompliant', 'comment' => ' ']]);
        self::assertSame('comment', SampleRules::recordError($missing, 'ineffective', 'ineffective', ''));
        $pending = SampleRules::tally([['result' => '']]);
        self::assertSame('pending', SampleRules::recordError($pending, null, 'effective', ''));
        $allNa = SampleRules::tally([['result' => 'na', 'comment' => 'Hors periode']]);
        self::assertSame('all_na', SampleRules::recordError($allNa, null, 'effective', ''));
        self::assertSame('empty', SampleRules::recordError(SampleRules::tally([]), null, 'effective', ''));
    }

    public function testReviewRules(): void
    {
        self::assertTrue(ControlTestRules::countsForVerified(ControlTestRules::REVIEW_NONE));
        self::assertTrue(ControlTestRules::countsForVerified(ControlTestRules::REVIEW_APPROVED));
        self::assertFalse(ControlTestRules::countsForVerified(ControlTestRules::REVIEW_PENDING));
        self::assertFalse(ControlTestRules::countsForVerified(ControlTestRules::REVIEW_RETURNED));
        self::assertSame('', ControlTestRules::normalizeReviewStatus('done'));

        // Ni le testeur ni la personne qui a enregistré le test ne le relisent.
        self::assertTrue(ControlTestRules::canReview('pending', 3, 2, 2));
        self::assertFalse(ControlTestRules::canReview('pending', 2, 2, 4));
        self::assertFalse(ControlTestRules::canReview('pending', 4, 2, 4));
        self::assertFalse(ControlTestRules::canReview('approved', 3, 2, 2));
        self::assertFalse(ControlTestRules::canReview('', 3, 2, 2));
        self::assertFalse(ControlTestRules::canReview('pending', 0, 2, 2));

        // En attente de relecture, un test efficace n'apporte pas encore « vérifié » ; un test
        // inefficace le retire tout de suite.
        self::assertNull(ControlTestRules::statusAtRecording('implemented', 'effective', 'pending'));
        self::assertSame('verified', ControlTestRules::statusAtRecording('implemented', 'effective', ''));
        self::assertSame('implemented', ControlTestRules::statusAtRecording('verified', 'ineffective', 'pending'));
    }
}
