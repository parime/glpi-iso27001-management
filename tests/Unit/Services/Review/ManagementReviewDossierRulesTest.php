<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Review;

use GlpiPlugin\Grcmanager\Services\Review\ManagementReviewDossierRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #542 : dossier préparatoire de la revue de direction.
 */
final class ManagementReviewDossierRulesTest extends TestCase
{
    public function testSectionsFollowTheClauseOrder(): void
    {
        $clauses = array_column(Rules::SECTIONS, 0);
        self::assertSame('9.3.2 a', $clauses[0]);
        self::assertSame('9.3.2 g', end($clauses));
        $sorted = $clauses;
        sort($sorted);
        self::assertSame($sorted, $clauses);
        foreach (Rules::SECTIONS as [, $icon]) {
            self::assertStringStartsWith('ti-', $icon);
        }
    }

    public function testDefaultPeriodStartsAtThePreviousReview(): void
    {
        self::assertSame(
            ['start' => '2025-11-20', 'end' => '2026-11-25'],
            Rules::defaultPeriod('2025-11-20', '2026-11-25', '2026-11-19')
        );
        // Revue sans date : jusqu'à aujourd'hui.
        self::assertSame(
            ['start' => '2025-11-20', 'end' => '2026-11-19'],
            Rules::defaultPeriod('2025-11-20', null, '2026-11-19')
        );
        // Sans revue précédente (ou une revue précédente incohérente) : les douze derniers mois.
        self::assertSame(
            ['start' => '2025-11-19', 'end' => '2026-11-19'],
            Rules::defaultPeriod(null, '0000-00-00', '2026-11-19')
        );
        self::assertSame(
            ['start' => '2025-11-25', 'end' => '2026-11-25'],
            Rules::defaultPeriod('2027-01-01', '2026-11-25', '2026-11-19')
        );
    }

    public function testPeriodInputIsChecked(): void
    {
        $half = ['start' => '2026-01-01', 'end' => '2026-06-30'];
        self::assertSame($half, Rules::normalizePeriod('2026-01-01', '2026-06-30'));
        $day = ['start' => '2026-03-01', 'end' => '2026-03-01'];
        self::assertSame($day, Rules::normalizePeriod('2026-03-01', '2026-03-01'));
        self::assertNull(Rules::normalizePeriod('2026-06-30', '2026-01-01'), 'Début après la fin.');
        self::assertNull(Rules::normalizePeriod('2026-02-31', '2026-06-30'), 'Date impossible.');
        self::assertNull(Rules::normalizePeriod('', '2026-06-30'));
        self::assertNull(Rules::normalizePeriod(['x'], '2026-06-30'));
        self::assertNull(Rules::normalizePeriod('2010-01-01', '2026-06-30'), 'Plus de dix ans.');
        self::assertSame('2026-05-04', Rules::validDate('2026-05-04 10:00:00'));
        self::assertNull(Rules::validDate('04/05/2026'));
    }

    public function testInPeriodIncludesBothBounds(): void
    {
        self::assertTrue(Rules::inPeriod('2026-01-01 00:00:00', '2026-01-01', '2026-06-30'));
        self::assertTrue(Rules::inPeriod('2026-06-30 23:59:59', '2026-01-01', '2026-06-30'));
        self::assertFalse(Rules::inPeriod('2026-07-01', '2026-01-01', '2026-06-30'));
        self::assertFalse(Rules::inPeriod(null, '2026-01-01', '2026-06-30'));
    }

    public function testDecisionsAreSplitIntoActions(): void
    {
        $text = "- Renouveler la sensibilisation au hameçonnage\n\n* Budget pour le SOC externalisé\r\n"
            . "1. Réviser la politique de sauvegarde\n• Budget pour le SOC   externalisé\n   \n"
            . "<p>Nommer un suppléant au RSSI</p>";
        self::assertSame([
            'Renouveler la sensibilisation au hameçonnage',
            'Budget pour le SOC externalisé',
            'Réviser la politique de sauvegarde',
            'Nommer un suppléant au RSSI',
        ], Rules::decisionLines($text));
        self::assertSame([], Rules::decisionLines("  \n - \n"));
        self::assertSame(Rules::MAX_ACTION_LENGTH, mb_strlen(Rules::decisionLines(str_repeat('é', 2000))[0]));
    }

    public function testExistingActionsAreNotCreatedTwice(): void
    {
        self::assertSame(
            ['Nommer un suppléant au RSSI'],
            Rules::newDecisionLines(
                ['Budget pour le SOC externalisé', 'Nommer un suppléant au RSSI'],
                ['budget pour le  SOC externalisé ']
            )
        );
    }

    public function testActionSummaryCountsOverdueOpenActions(): void
    {
        $summary = Rules::actionSummary([
            ['status' => 'done', 'due_date' => '2026-01-01'],
            ['status' => 'planned', 'due_date' => '2026-10-01'],
            ['status' => 'in_progress', 'due_date' => '2026-12-01'],
            ['status' => 'planned', 'due_date' => null],
        ], '2026-11-19');
        self::assertSame(['total' => 4, 'done' => 1, 'open' => 3, 'overdue' => 1, 'percent' => 25], $summary);
        self::assertNull(Rules::actionSummary([], '2026-11-19')['percent']);
        self::assertFalse(
            Rules::isOverdue('2026-11-19', 'planned', '2026-11-19'),
            'Échéance du jour : pas encore en retard.'
        );
        self::assertFalse(Rules::isOverdue('2026-01-01', 'done', '2026-11-19'));
    }

    public function testTrendBetweenTwoReadings(): void
    {
        self::assertSame(['direction' => 'up', 'delta' => 7], Rules::trend(55, 62));
        self::assertSame(['direction' => 'down', 'delta' => -3], Rules::trend(10, 7));
        self::assertSame(['direction' => 'stable', 'delta' => 0], Rules::trend(4, 4));
        self::assertNull(Rules::trend(null, 4));
    }

    public function testMonthsInPeriodAreSorted(): void
    {
        self::assertSame(
            ['2026-01' => 50, '2026-03' => 58],
            Rules::monthsInPeriod(
                ['2026-03' => 58, '2025-12' => 40, '2026-01' => 50, '2026-07' => 70],
                '2026-01-15',
                '2026-06-30'
            )
        );
    }

    public function testFileNameIsPredictable(): void
    {
        self::assertSame('dossier-revue-direction-12-20261119103000.pdf', Rules::fileName(12, '2026-11-19 10:30:00'));
    }
}
