<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use PHPUnit\Framework\TestCase;

final class SoaApprovalLogicTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'code' => 'A.5.10', 'applicability' => 'yes', 'implementation_status' => 'implemented',
                'justification' => '', 'date_mod' => '2026-01-01 10:00:00'],
            ['id' => 2, 'code' => 'A.5.9', 'applicability' => 'no', 'implementation_status' => 'not_started',
                'justification' => 'Hors périmètre', 'date_mod' => '2026-01-01 10:00:00'],
        ];
    }

    public function testFingerprintIgnoresRowOrderTechnicalFieldsAndSurroundingSpaces(): void
    {
        $reference = SoaApprovalLogic::fingerprint(self::rows());

        $shuffled = array_reverse(self::rows());
        $shuffled[0]['date_mod'] = '2026-09-09 09:09:09';
        $shuffled[1]['id'] = 99;
        $shuffled[1]['justification'] = '  ';

        self::assertSame(64, strlen($reference));
        self::assertSame($reference, SoaApprovalLogic::fingerprint($shuffled));
    }

    public function testAnyContentChangeChangesTheFingerprint(): void
    {
        $reference = SoaApprovalLogic::fingerprint(self::rows());

        $changes = ['applicability' => 'partial', 'implementation_status' => 'verified', 'justification' => 'Nouveau'];
        foreach ($changes as $field => $value) {
            $rows = self::rows();
            $rows[0][$field] = $value;
            self::assertNotSame($reference, SoaApprovalLogic::fingerprint($rows), $field);
        }
    }

    public function testVersionStatusNeedsEveryApproverAndOneRefusalIsEnough(): void
    {
        self::assertSame('pending', SoaApprovalLogic::versionStatus([]));
        self::assertSame('pending', SoaApprovalLogic::versionStatus(['approved', 'pending']));
        self::assertSame('approved', SoaApprovalLogic::versionStatus(['approved', 'approved']));
        self::assertSame('rejected', SoaApprovalLogic::versionStatus(['approved', 'rejected', 'pending']));
    }

    public function testCurrentState(): void
    {
        $fingerprint = SoaApprovalLogic::fingerprint(self::rows());

        self::assertSame('never_approved', SoaApprovalLogic::currentState(null, $fingerprint));
        self::assertSame('up_to_date', SoaApprovalLogic::currentState($fingerprint, $fingerprint));
        self::assertSame('changed', SoaApprovalLogic::currentState(str_repeat('0', 64), $fingerprint));
    }
}
