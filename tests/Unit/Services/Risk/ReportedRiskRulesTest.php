<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\ReportedRiskRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #872 : contrôles d'un signalement de risque et des décisions du tri.
 */
final class ReportedRiskRulesTest extends TestCase
{
    public function testAReportNeedsATitleAndADescription(): void
    {
        self::assertSame(Rules::ERROR_NAME_REQUIRED, Rules::reportError('  ', 'Porte ouverte'));
        self::assertSame(Rules::ERROR_DESCRIPTION_REQUIRED, Rules::reportError('Porte', " \n "));
        self::assertSame(Rules::ERROR_NAME_TOO_LONG, Rules::reportError(str_repeat('é', 256), 'x'));
        self::assertSame(
            Rules::ERROR_DESCRIPTION_TOO_LONG,
            Rules::reportError('Porte', str_repeat('a', Rules::DESCRIPTION_MAX + 1))
        );
        self::assertNull(Rules::reportError(str_repeat('é', 255), 'La porte de la salle ne ferme plus.'));
    }

    public function testUnknownSeverityBecomesUnknown(): void
    {
        self::assertSame(Rules::SEVERITY_HIGH, Rules::normalizeSeverity('high'));
        self::assertSame(Rules::SEVERITY_UNKNOWN, Rules::normalizeSeverity('critical'));
    }

    public function testOnlyANewReportIsTriagedOnce(): void
    {
        self::assertSame(Rules::ERROR_UNKNOWN_DECISION, Rules::decisionError(Rules::STATUS_NEW, 'new', '', 0));
        foreach ([Rules::STATUS_ACCEPTED, Rules::STATUS_MERGED, Rules::STATUS_REJECTED] as $status) {
            self::assertSame(
                Rules::ERROR_ALREADY_DECIDED,
                Rules::decisionError($status, Rules::STATUS_REJECTED, 'Motif', 0)
            );
        }
        self::assertNull(Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_ACCEPTED, '', 0));
    }

    public function testARejectionNeedsAReasonAndALinkNeedsARisk(): void
    {
        self::assertSame(
            Rules::ERROR_REASON_REQUIRED,
            Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_REJECTED, '   ', 0)
        );
        self::assertNull(Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_REJECTED, 'Hors périmètre', 0));
        self::assertSame(
            Rules::ERROR_RISK_REQUIRED,
            Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_MERGED, '', 0)
        );
        self::assertNull(Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_MERGED, '', 12));
        self::assertSame(
            Rules::ERROR_REASON_TOO_LONG,
            Rules::decisionError(Rules::STATUS_NEW, Rules::STATUS_ACCEPTED, str_repeat('a', Rules::REASON_MAX + 1), 0)
        );
    }

    public function testSummaryCountsEveryStatus(): void
    {
        $summary = Rules::summary([
            ['status' => Rules::STATUS_NEW], ['status' => Rules::STATUS_NEW], ['status' => Rules::STATUS_REJECTED],
            ['status' => 'other'],
        ]);
        self::assertSame([
            Rules::STATUS_NEW => 2, Rules::STATUS_ACCEPTED => 0, Rules::STATUS_MERGED => 0, Rules::STATUS_REJECTED => 1,
        ], $summary);
    }

    public function testQueueShowsNewReportsFirstBySeverityThenOldest(): void
    {
        $row = static fn (string $id, string $status, string $severity, string $date): array => [
            'id' => $id, 'status' => $status, 'severity' => $severity, 'date_creation' => $date,
        ];
        $sorted = Rules::sortQueue([
            $row('done-old', Rules::STATUS_ACCEPTED, 'high', '2026-01-01 10:00:00'),
            $row('new-low', Rules::STATUS_NEW, 'low', '2026-01-01 10:00:00'),
            $row('new-high-late', Rules::STATUS_NEW, 'high', '2026-03-01 10:00:00'),
            $row('new-unknown', Rules::STATUS_NEW, '', '2026-01-01 09:00:00'),
            $row('new-high-early', Rules::STATUS_NEW, 'high', '2026-02-01 10:00:00'),
            $row('done-recent', Rules::STATUS_REJECTED, 'low', '2026-04-01 10:00:00'),
        ]);
        self::assertSame(
            ['new-high-early', 'new-high-late', 'new-low', 'new-unknown', 'done-recent', 'done-old'],
            array_column($sorted, 'id')
        );
    }
}
