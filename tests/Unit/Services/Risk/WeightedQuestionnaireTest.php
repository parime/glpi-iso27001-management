<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #864 : score pondéré des questionnaires fournisseurs configurables.
 */
final class WeightedQuestionnaireTest extends TestCase
{
    public function testTheScoreIsWeighted(): void
    {
        $rows = [
            ['answer' => 'yes', 'expected_answer' => 'yes', 'weight' => 3],
            ['answer' => 'no', 'expected_answer' => 'yes', 'weight' => 1],
        ];

        // (3 x 1 + 1 x 0) / 4 = 75 %.
        self::assertSame(75, Rules::score($rows));
    }

    public function testPartialEarnsHalfAPointTimesTheWeight(): void
    {
        $rows = [
            ['answer' => 'partial', 'expected_answer' => 'yes', 'weight' => 4],
            ['answer' => 'yes', 'expected_answer' => 'yes', 'weight' => 1],
        ];

        // (4 x 0,5 + 1) / 5 = 60 %.
        self::assertSame(60, Rules::score($rows));
    }

    public function testTheExpectedAnswerCanBeNo(): void
    {
        $breach = ['expected_answer' => 'no', 'weight' => 2];

        self::assertSame(100, Rules::score([$breach + ['answer' => 'no']]));
        self::assertSame(0, Rules::score([$breach + ['answer' => 'yes']]));
        self::assertSame(1.0, Rules::points('no', 'no'));
        self::assertSame(0.0, Rules::points('yes', 'no'));
        self::assertSame(0.5, Rules::points('partial', 'no'));
    }

    public function testNotApplicableUnansweredAndZeroWeightAreNotCounted(): void
    {
        $rows = [
            ['answer' => 'yes', 'expected_answer' => 'yes', 'weight' => 2],
            ['answer' => 'na', 'expected_answer' => 'yes', 'weight' => 10],
            ['answer' => '', 'expected_answer' => 'yes', 'weight' => 10],
            ['answer' => 'no', 'expected_answer' => 'yes', 'weight' => 0],
        ];

        self::assertSame(100, Rules::score($rows));
        self::assertNull(Rules::score([['answer' => 'na', 'weight' => 5]]));
        self::assertNull(Rules::score([]));
    }

    public function testUnknownValuesAreNormalized(): void
    {
        self::assertSame('', Rules::normalizeAnswer('maybe'));
        self::assertSame('', Rules::normalizeAnswer(['yes']));
        self::assertSame('yes', Rules::normalizeAnswer(' YES '));
        self::assertSame('yes', Rules::normalizeExpected('partial'));
        self::assertSame(10, Rules::normalizeWeight(99));
        self::assertSame(0, Rules::normalizeWeight(-3));
        self::assertSame(1, Rules::normalizeWeight('abc'));
        self::assertSame('security', Rules::normalizeDomain('<script>'));
        self::assertSame('privacy', Rules::normalizeDomain('privacy'));
        self::assertNull(Rules::points('maybe', 'yes'));
    }

    public function testTheSummaryCountsGapsAndMissingEvidence(): void
    {
        $evidence = ['expected_answer' => 'yes', 'weight' => 1, 'requires_evidence' => 1];
        $summary = Rules::summary([
            ['answer' => 'yes', 'documents_id' => 0] + $evidence,
            ['answer' => 'yes', 'documents_id' => 12] + $evidence,
            ['answer' => 'partial', 'expected_answer' => 'yes', 'weight' => 2],
            ['answer' => 'na', 'documents_id' => 0] + $evidence,
            ['answer' => '', 'expected_answer' => 'yes', 'weight' => 1, 'requires_evidence' => 1],
        ]);

        self::assertSame(5, $summary['total']);
        self::assertSame(4, $summary['answered']);
        self::assertSame(1, $summary['not_applicable']);
        self::assertSame(1, $summary['gaps']);
        self::assertSame(1, $summary['missing_evidence'], 'ni « sans objet » ni une question sans réponse');
        // (1 + 1 + 2 x 0,5) / 4 = 75 %.
        self::assertSame(75, $summary['score']);
        self::assertSame('fair', $summary['level']);
        self::assertFalse($summary['complete']);
    }

    public function testTheFingerprintFollowsTheStructure(): void
    {
        $questions = [
            ['code' => 'Q01', 'question' => 'Encryption at rest?', 'expected_answer' => 'yes', 'weight' => 2],
            ['code' => 'Q02', 'question' => 'Breach in the last year?', 'expected_answer' => 'no', 'weight' => 1],
        ];
        $fingerprint = Rules::fingerprint($questions);

        self::assertSame(16, strlen($fingerprint));
        self::assertSame($fingerprint, Rules::fingerprint($questions));
        $questions[0]['weight'] = 3;
        self::assertNotSame($fingerprint, Rules::fingerprint($questions));
    }

    public function testCodesAndTextsAreCleaned(): void
    {
        self::assertSame('Q03', Rules::nextCode(['Q01', 'Q02']));
        self::assertSame('Q04', Rules::nextCode(['Q01', 'Q03', 'Q02']));
        self::assertSame('Q02', Rules::nextCode(['Q01']));
        self::assertSame('A.5_19', Rules::cleanCode(' A.5 19 '));
        self::assertSame("line 1\nline 2", Rules::cleanText("  line 1\nline 2\x07  "));
        self::assertSame('abc', Rules::cleanText('abcdef', 3));
        self::assertSame('', Rules::cleanText(['x']));
    }
}
