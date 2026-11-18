<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\SoaSelectionRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #609 : motifs de sélection, exclusions et synthèse par thème de la SoA.
 */
final class SoaSelectionRulesTest extends TestCase
{
    public function testNoReasonIsContainedInAnother(): void
    {
        // Le filtre de la recherche cherche le motif dans la liste stockée (LIKE).
        foreach (SoaSelectionRules::REASONS as $reason) {
            foreach (SoaSelectionRules::REASONS as $other) {
                if ($reason !== $other) {
                    self::assertStringNotContainsString($reason, $other);
                }
            }
        }
    }

    public function testEncodeKeepsKnownReasonsInCatalogueOrder(): void
    {
        self::assertSame('risk_assessment,legal', SoaSelectionRules::encodeReasons(['legal', 'risk_assessment']));
        self::assertSame('contractual', SoaSelectionRules::encodeReasons('contractual, unknown,contractual'));
        self::assertSame('', SoaSelectionRules::encodeReasons(null));
        self::assertSame('', SoaSelectionRules::encodeReasons([]));
        self::assertSame([], SoaSelectionRules::decodeReasons(null));
        self::assertSame(
            ['stakeholder', 'best_practice'],
            SoaSelectionRules::decodeReasons('best_practice,stakeholder')
        );
    }

    public function testAddReasonKeepsTheOthers(): void
    {
        self::assertSame('risk_assessment', SoaSelectionRules::addReason(null, 'risk_assessment'));
        self::assertSame('risk_assessment,legal', SoaSelectionRules::addReason('legal', 'risk_assessment'));
        self::assertSame('legal', SoaSelectionRules::addReason('legal', 'legal'));
    }

    public function testSuggestedReasonsComeFromRisksAndObligations(): void
    {
        self::assertSame([], SoaSelectionRules::suggestedReasons(false, []));
        self::assertSame(['risk_assessment'], SoaSelectionRules::suggestedReasons(true, []));
        self::assertSame(
            ['risk_assessment', 'legal', 'contractual'],
            SoaSelectionRules::suggestedReasons(true, ['contractual', 'regulatory', 'legal', 'other'])
        );
    }

    public function testTheFormTicksTheSuggestionsOnlyWhileNothingIsSet(): void
    {
        self::assertSame(['risk_assessment'], SoaSelectionRules::formReasons(null, ['risk_assessment']));
        // Enregistré vide : la case décochée le reste.
        self::assertSame([], SoaSelectionRules::formReasons('', ['risk_assessment']));
        self::assertSame(['best_practice'], SoaSelectionRules::formReasons('best_practice', ['risk_assessment']));
    }

    public function testExclusionAndChoices(): void
    {
        self::assertTrue(SoaSelectionRules::isExclusion('no'));
        self::assertTrue(SoaSelectionRules::isExclusion('partial'));
        self::assertFalse(SoaSelectionRules::isExclusion('yes'));
        self::assertSame(
            'certified_provider',
            SoaSelectionRules::normalizeChoice(' certified_provider ', SoaSelectionRules::EXCLUSION_CATEGORIES)
        );
        self::assertSame('', SoaSelectionRules::normalizeChoice('<b>x</b>', SoaSelectionRules::EXCLUSION_CATEGORIES));
        self::assertSame('high', SoaSelectionRules::normalizeChoice('high', SoaSelectionRules::RESIDUAL_LEVELS));
        self::assertSame('', SoaSelectionRules::normalizeChoice(null, SoaSelectionRules::RESIDUAL_LEVELS));
    }

    public function testThemeSummaryCountsApplicableExcludedAndProgress(): void
    {
        $row = static fn (string $theme, string $applicability, string $status): array => [
            'theme' => $theme, 'applicability' => $applicability, 'implementation_status' => $status,
        ];
        $lines = SoaSelectionRules::themeSummary([
            $row('physical', 'no', 'implemented'),
            $row('physical', 'yes', 'verified'),
            $row('physical', 'partial', 'in_progress'),
            $row('physical', 'yes', 'not_started'),
            $row('people', 'yes', 'implemented'),
            $row('unknown', 'yes', 'implemented'),
        ], ['organizational', 'people', 'physical', 'technological']);

        self::assertSame(['organizational', 'people', 'physical', 'technological'], array_column($lines, 'theme'));
        self::assertSame([
            'theme' => 'physical', 'total' => 4, 'applicable' => 3, 'partial' => 1, 'excluded' => 1,
            'implemented' => 1, 'in_progress' => 1, 'not_started' => 1,
        ], $lines[2]);
        self::assertSame(0, $lines[0]['total']);

        $totals = SoaSelectionRules::themeTotals($lines);
        self::assertSame(5, $totals['total']);
        self::assertSame(4, $totals['applicable']);
        self::assertSame(1, $totals['excluded']);
        self::assertSame(2, $totals['implemented']);
    }
}
