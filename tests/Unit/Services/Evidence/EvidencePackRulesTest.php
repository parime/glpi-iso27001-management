<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Evidence;

use GlpiPlugin\Grcmanager\Services\Evidence\EvidencePackRules as Rules;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;
use PHPUnit\Framework\TestCase;

/**
 * Issue #868 : règles du dossier de preuves exportable pour l'auditeur.
 */
final class EvidencePackRulesTest extends TestCase
{
    public function testSizeLimitIsBounded(): void
    {
        self::assertSame(Rules::DEFAULT_MAX_MB, Rules::normalizeMaxMb(null));
        self::assertSame(Rules::DEFAULT_MAX_MB, Rules::normalizeMaxMb('abc'));
        self::assertSame(Rules::MIN_MAX_MB, Rules::normalizeMaxMb('0'));
        self::assertSame(Rules::MAX_MAX_MB, Rules::normalizeMaxMb(99999));
        self::assertSame(50, Rules::normalizeMaxMb('50'));
        self::assertSame(2 * 1024 * 1024, Rules::maxBytes(2));
    }

    public function testExpiredEvidenceIsNeverIncluded(): void
    {
        $max = 1000;
        self::assertSame(Rules::STATE_EXPIRED, Rules::state(EvidenceValidity::STATUS_EXPIRED, true, 10, 0, $max));
        self::assertSame(Rules::STATE_NO_FILE, Rules::state(EvidenceValidity::STATUS_VALID, false, 0, 0, $max));
        self::assertSame(Rules::STATE_INCLUDED, Rules::state(EvidenceValidity::STATUS_NONE, true, 400, 600, $max));
        self::assertSame(Rules::STATE_TOO_LARGE, Rules::state(EvidenceValidity::STATUS_EXPIRING, true, 401, 600, $max));
    }

    public function testEntryNamesAreSafeAndUnique(): void
    {
        self::assertSame('evidence/12-rapport_pentest_2026.pdf', Rules::entryName(12, 'rapport pentest 2026.pdf'));
        self::assertSame('evidence/7-passwd', Rules::entryName(7, '../../etc/passwd'));
        self::assertSame('evidence/3-document', Rules::entryName(3, '...'));
        $long = Rules::entryName(5, str_repeat('a', 300) . '.pdf');
        self::assertStringEndsWith('.pdf', $long);
        self::assertLessThanOrEqual(strlen('evidence/5-') + 100, strlen($long));
    }

    public function testFileNameAndChecksums(): void
    {
        self::assertSame('evidence-pack-entity3-20260115-093000.zip', Rules::fileName(3, '2026-01-15 09:30:00'));
        self::assertSame(
            "aaa  index.pdf\nbbb  evidence/1-a.pdf\n",
            Rules::checksums(['index.pdf' => 'aaa', 'evidence/1-a.pdf' => 'bbb'])
        );
    }

    public function testCodesAreSortedNaturally(): void
    {
        self::assertSame(['A.5.2', 'A.5.10', 'A.8.1'], Rules::sortCodes(['A.5.10', 'A.8.1', 'A.5.2']));
        self::assertSame(['4.1', '9.2', '10.1'], Rules::sortCodes(['10.1', '4.1', '9.2']));
    }

    public function testSummaryCountsEachState(): void
    {
        $summary = Rules::summary([
            ['state' => Rules::STATE_INCLUDED, 'size' => 100],
            ['state' => Rules::STATE_INCLUDED, 'size' => 50],
            ['state' => Rules::STATE_EXPIRED, 'size' => 999],
            ['state' => Rules::STATE_TOO_LARGE, 'size' => 999],
        ]);
        self::assertSame(2, $summary[Rules::STATE_INCLUDED]);
        self::assertSame(1, $summary[Rules::STATE_EXPIRED]);
        self::assertSame(0, $summary[Rules::STATE_NO_FILE]);
        self::assertSame(1, $summary[Rules::STATE_TOO_LARGE]);
        self::assertSame(150, $summary['bytes']);
    }
}
