<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidence;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class AssetsignEvidenceTest extends TestCase
{
    public function testOnlyTheThreeAssetControlsAreRelevant(): void
    {
        self::assertSame(['A.5.9', 'A.5.10', 'A.5.11'], array_keys(AssetsignEvidence::CONTROL_TYPES));
        self::assertTrue(AssetsignEvidence::isRelevantControl('A.5.11'));
        self::assertFalse(AssetsignEvidence::isRelevantControl('A.5.12'));
        self::assertFalse(AssetsignEvidence::isRelevantControl(''));
    }

    public function testEveryMappedControlExistsInTheAnnexACatalog(): void
    {
        foreach (array_keys(AssetsignEvidence::CONTROL_TYPES) as $code) {
            self::assertArrayHasKey($code, ControlCatalogDefaults::CONTROLS);
        }
    }

    public function testReturnsProveA511AndHandoversProveA59AndA510(): void
    {
        self::assertSame([AssetsignEvidence::TYPE_HANDOVER], AssetsignEvidence::CONTROL_TYPES['A.5.9']);
        self::assertSame([AssetsignEvidence::TYPE_HANDOVER], AssetsignEvidence::CONTROL_TYPES['A.5.10']);
        self::assertSame([AssetsignEvidence::TYPE_RETURN], AssetsignEvidence::CONTROL_TYPES['A.5.11']);
    }

    public function testStatusBuckets(): void
    {
        self::assertSame('signed', AssetsignEvidence::bucketForStatus(4));
        foreach ([1, 2, 3, 9] as $pending) {
            self::assertSame('pending', AssetsignEvidence::bucketForStatus($pending), "status $pending");
        }
        self::assertSame('expired', AssetsignEvidence::bucketForStatus(6));
        // Brouillon, annulée, terminée sans signature (don/vente externe) et numéros inconnus.
        foreach ([0, 7, 8, 5, 42] as $ignored) {
            self::assertNull(AssetsignEvidence::bucketForStatus($ignored), "status $ignored");
        }
    }

    public function testSummarizeAggregatesPerTypeAndIgnoresOutOfScopeRows(): void
    {
        $summary = AssetsignEvidence::summarize([
            ['type' => 0, 'status' => 4, 'cpt' => 340],
            ['type' => '0', 'status' => '4', 'cpt' => '2'],
            ['type' => 0, 'status' => 2, 'cpt' => 5],
            ['type' => 0, 'status' => 9, 'cpt' => 1],
            ['type' => 0, 'status' => 7, 'cpt' => 99],
            ['type' => 1, 'status' => 3, 'cpt' => 12],
            ['type' => 1, 'status' => 6, 'cpt' => 3],
            ['type' => 3, 'status' => 4, 'cpt' => 50],
        ]);

        self::assertSame([
            AssetsignEvidence::TYPE_HANDOVER => ['signed' => 342, 'pending' => 6, 'expired' => 0],
            AssetsignEvidence::TYPE_RETURN   => ['signed' => 0, 'pending' => 12, 'expired' => 3],
        ], $summary);
    }

    public function testSummarizeOfNoRowsGivesZeroCountersForBothTypes(): void
    {
        $zero = ['signed' => 0, 'pending' => 0, 'expired' => 0];

        self::assertSame([0 => $zero, 1 => $zero], AssetsignEvidence::summarize([]));
    }

    public function testCharterProvesA510AndA63(): void
    {
        self::assertTrue(AssetsignEvidence::isCharterControl('A.5.10'));
        self::assertTrue(AssetsignEvidence::isCharterControl('A.6.3'));
        self::assertFalse(AssetsignEvidence::isCharterControl('A.5.9'));
        foreach (AssetsignEvidence::CHARTER_CONTROLS as $code) {
            self::assertArrayHasKey($code, ControlCatalogDefaults::CONTROLS);
        }
    }

    public function testCharterRateKeepsOnlyTheLatestCampaignOfEachEntity(): void
    {
        $row = static fn (int $entity, string $name, string $date, int $total, int|string|null $signed): array => [
            'entities_id' => $entity, 'name' => $name, 'date_creation' => $date, 'total' => $total, 'signed' => $signed,
        ];
        $rows = [
            $row(1, 'Charte 2026', '2026-10-01 08:00:00', 40, '30'),
            $row(2, 'Charte B', '2026-09-01 08:00:00', 0, null),
            $row(1, 'Charte 2025', '2025-10-01 08:00:00', 40, 40),
        ];

        $summary = AssetsignEvidence::summarizeCharter($rows);

        self::assertCount(2, $summary);
        self::assertSame(
            [
                'entities_id' => 1, 'campaign' => 'Charte 2026', 'date' => '2026-10-01 08:00:00',
                'signed' => 30, 'total' => 40, 'rate' => 75,
            ],
            $summary[0]
        );
        self::assertSame(0, $summary[1]['rate'], 'Campagne sans personne : taux nul, sans division par zéro.');
        self::assertSame([], AssetsignEvidence::summarizeCharter([]));
    }
}
