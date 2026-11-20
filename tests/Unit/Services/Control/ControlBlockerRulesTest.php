<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Control\ControlBlockerRules as R;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #867 : rattachement, gravité et tri des blocages d'une mesure.
 */
final class ControlBlockerRulesTest extends TestCase
{
    public function testConsistencyRulesExistAndHaveACategory(): void
    {
        foreach (R::CONSISTENCY_RULES as $rule => $category) {
            self::assertArrayHasKey($rule, ConsistencyRules::RULES, $rule);
            self::assertContains($category, R::CATEGORIES, $rule);
        }
        self::assertArrayNotHasKey('nonconformity_overdue', R::CONSISTENCY_RULES, 'lues directement, sans doublon');
    }

    public function testReferenceMatchesTheWholeControlCode(): void
    {
        self::assertTrue(R::referencesControl('A.8.16', 'A.8.16'));
        self::assertTrue(R::referencesControl('Annexe A.8.16, A.5.7', 'A.5.7'));
        self::assertTrue(R::referencesControl('a.8.16 (journalisation)', 'A.8.16'));
        self::assertFalse(R::referencesControl('A.8.16', 'A.8.1'));
        self::assertFalse(R::referencesControl('A.8.1.2', 'A.8.1'));
        self::assertFalse(R::referencesControl('XA.8.1', 'A.8.1'));
        self::assertFalse(R::referencesControl('', 'A.8.1'));
        self::assertFalse(R::referencesControl('A.8.1', ''));
        self::assertTrue(R::referencesControl('A.8.1.', 'A.8.1'), 'point final de phrase');
    }

    public function testTestBlockers(): void
    {
        self::assertSame([], R::testBlockers(ControlTestRules::RESULT_EFFECTIVE, ControlTestRules::DUE_PLANNED));
        self::assertSame(
            [['kind' => 'ineffective', 'severity' => 'high'], ['kind' => 'overdue', 'severity' => 'medium']],
            R::testBlockers(ControlTestRules::RESULT_INEFFECTIVE, ControlTestRules::DUE_OVERDUE)
        );
        self::assertSame(
            [['kind' => 'partial', 'severity' => 'medium']],
            R::testBlockers(ControlTestRules::RESULT_PARTIAL, ControlTestRules::DUE_SOON)
        );
        self::assertSame(
            [['kind' => 'overdue', 'severity' => 'medium']],
            R::testBlockers(ControlTestRules::RESULT_EFFECTIVE, ControlTestRules::DUE_OVERDUE)
        );
    }

    public function testSeverities(): void
    {
        self::assertSame('high', R::technicalSeverity(ControlTestRules::RESULT_INEFFECTIVE));
        self::assertSame('medium', R::technicalSeverity(ControlTestRules::RESULT_PARTIAL));
        self::assertNull(R::technicalSeverity(ControlTestRules::RESULT_EFFECTIVE));
        self::assertNull(R::technicalSeverity(null));
        self::assertSame('high', R::nonconformitySeverity('major', false));
        self::assertSame('high', R::nonconformitySeverity('minor', true));
        self::assertSame('medium', R::nonconformitySeverity('minor', false));
    }

    public function testBlockersAreSortedBySeverityThenCategory(): void
    {
        $sorted = R::sortBlockers([
            ['category' => R::CATEGORY_WAIVER, 'severity' => 'medium', 'name' => 'b'],
            ['category' => R::CATEGORY_TEST, 'severity' => 'high', 'name' => 'c'],
            ['category' => R::CATEGORY_TECHNICAL, 'severity' => 'medium', 'name' => 'a'],
            ['category' => R::CATEGORY_TECHNICAL, 'severity' => 'low', 'name' => 'd'],
        ]);

        self::assertSame(['c', 'a', 'b', 'd'], array_column($sorted, 'name'));
    }

    public function testSummary(): void
    {
        self::assertSame(
            ['total' => 0, 'worst' => null, 'by_severity' => ['high' => 0, 'medium' => 0, 'low' => 0]],
            R::summarize([])
        );
        $summary = R::summarize([['severity' => 'low'], ['severity' => 'medium'], ['severity' => 'low']]);
        self::assertSame(3, $summary['total']);
        self::assertSame('medium', $summary['worst']);
        self::assertSame(2, $summary['by_severity']['low']);
    }

    public function testSortAndOrderAreNormalized(): void
    {
        self::assertSame('severity', R::normalizeSort('drop table'));
        self::assertSame('code', R::normalizeSort('code'));
        self::assertSame('ASC', R::normalizeOrder(null));
        self::assertSame('DESC', R::normalizeOrder('desc'));
    }

    public function testRowsSorting(): void
    {
        $row = static fn (string $code, int $high, int $medium): array => [
            'code'    => $code,
            'summary' => R::summarize(array_merge(
                array_fill(0, $high, ['severity' => 'high']),
                array_fill(0, $medium, ['severity' => 'medium'])
            )),
        ];
        $rows = [$row('A.5.10', 0, 3), $row('A.5.2', 1, 0), $row('A.8.7', 2, 0), $row('A.5.1', 0, 1)];

        $codes = static fn (string $sort, string $order): array
            => array_column(R::sortRows($rows, $sort, $order), 'code');
        self::assertSame(['A.8.7', 'A.5.2', 'A.5.10', 'A.5.1'], $codes('severity', 'ASC'));
        self::assertSame(['A.5.10', 'A.8.7', 'A.5.2', 'A.5.1'], $codes('blockers', 'ASC'));
        self::assertSame(['A.5.1', 'A.5.2', 'A.5.10', 'A.8.7'], $codes('code', 'ASC'));
        self::assertSame(['A.8.7', 'A.5.10', 'A.5.2', 'A.5.1'], $codes('code', 'DESC'));
    }
}
