<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules as Rules;
use PHPUnit\Framework\TestCase;

final class FrameworkAssessmentRulesTest extends TestCase
{
    public function testEvidenceStatusDependsOnTheDocumentsValidity(): void
    {
        $today = '2026-11-08';
        $this->assertSame(Rules::EVIDENCE_NONE, Rules::evidenceStatus([], $today));
        $this->assertSame(Rules::EVIDENCE_PROVEN, Rules::evidenceStatus([null], $today), 'Sans date : sans limite.');
        $this->assertSame(Rules::EVIDENCE_PROVEN, Rules::evidenceStatus(['2026-11-08'], $today), 'Date incluse.');
        $this->assertSame(Rules::EVIDENCE_EXPIRED, Rules::evidenceStatus(['2026-11-07', '2025-01-01'], $today));
        $this->assertSame(Rules::EVIDENCE_PROVEN, Rules::evidenceStatus(['2026-11-07', '2027-01-01'], $today));
    }

    public function testScoreCountsOnlyControlsBothImplementedAndProven(): void
    {
        $row = static fn (int $id, string $applicability, string $status, string $evidence): array => [
            'id'                    => $id,
            'applicability'         => $applicability,
            'implementation_status' => $status,
            'evidence'              => $evidence,
        ];
        $controls = [
            'A.5.1'  => $row(1, 'yes', 'implemented', 'proven'),
            'A.5.2'  => $row(2, 'yes', 'verified', 'expired'),
            'A.5.3'  => $row(3, 'yes', 'not_started', 'proven'),
            'A.5.4'  => $row(4, 'yes', 'in_progress', 'none'),
            'A.5.5'  => $row(5, 'no', 'not_started', 'none'),
            'A.7.12' => $row(6, 'yes', 'not_started', 'none'),
        ];
        $result = Rules::assess($controls, static fn (string $code): bool => str_starts_with($code, 'A.5.'));

        $this->assertSame(4, $result['applicable'], 'A.5.5 non applicable, A.7.12 hors référentiel.');
        $this->assertSame(2, $result['done']);
        $this->assertSame(2, $result['proven']);
        $this->assertSame(1, $result['compliant']);
        $this->assertSame(62.5, $result['implementation_rate'], '(2 + 0,5) / 4');
        $this->assertSame(50.0, $result['evidence_rate']);
        $this->assertSame(25.0, $result['score']);
        $this->assertSame(['A.5.2', 'A.5.3', 'A.5.4'], array_column($result['gaps'], 'code'));
        $this->assertSame(2, $result['gaps'][0]['id']);
    }

    /**
     * Issue #789 : mesures propres d'un référentiel. Sans preuve suivie (auto-évaluation), une
     * mesure faite compte dans le score et le taux de preuve reste vide ; les champs d'affichage
     * suivent dans les écarts.
     */
    public function testOwnMeasuresWithoutTrackedEvidenceCountWhenDone(): void
    {
        $measure = static fn (string $code, string $status): array => [
            'code'  => $code,
            'id'    => 1,
            'title' => 'Exigence ' . $code,
        ] + Rules::fromCustomStatus($status) + ['evidence' => null];
        $result = Rules::assessMeasures([
            $measure('REQ-1', 'compliant'),
            $measure('REQ-2', 'partial'),
            $measure('REQ-3', 'non_compliant'),
            $measure('REQ-4', ''),
            $measure('REQ-5', 'not_applicable'),
        ]);

        $this->assertSame(4, $result['applicable'], 'REQ-5 non applicable.');
        $this->assertSame(25.0, $result['score']);
        $this->assertSame(37.5, $result['implementation_rate'], '(1 + 0,5) / 4');
        $this->assertNull($result['evidence_rate'], 'Aucune preuve suivie.');
        $this->assertSame(['REQ-2', 'REQ-3', 'REQ-4'], array_column($result['gaps'], 'code'));
        $this->assertSame('Exigence REQ-2', $result['gaps'][0]['title']);
        $this->assertArrayNotHasKey('applicable', $result['gaps'][0]);
    }

    public function testOwnMeasuresWithTrackedEvidenceNeedAProof(): void
    {
        $result = Rules::assessMeasures([
            ['code' => 'P1', 'id' => 1, 'applicable' => true, 'implementation_status' => 'verified',
                'evidence' => Rules::EVIDENCE_PROVEN],
            ['code' => 'P2', 'id' => 2, 'applicable' => true, 'implementation_status' => 'implemented',
                'evidence' => Rules::EVIDENCE_NONE],
        ]);
        $this->assertSame(50.0, $result['score']);
        $this->assertSame(50.0, $result['evidence_rate']);
        $this->assertSame(['P2'], array_column($result['gaps'], 'code'));
    }

    public function testMappedMeasureTakesTheStatusOfItsAnnexAControls(): void
    {
        $control = static fn (string $applicability, string $status, string $evidence): array => [
            'applicability' => $applicability, 'implementation_status' => $status, 'evidence' => $evidence,
        ];
        $this->assertSame(
            ['applicable' => true, 'implementation_status' => 'implemented', 'evidence' => 'proven', 'mapped' => true],
            Rules::fromMappedControls([
                $control('yes', 'implemented', 'proven'),
                $control('yes', 'verified', 'proven'),
                $control('no', 'not_started', 'none'),
            ]),
            'La mesure non applicable ne compte pas.'
        );
        $this->assertSame(
            ['applicable' => true, 'implementation_status' => 'in_progress', 'evidence' => 'expired', 'mapped' => true],
            Rules::fromMappedControls([
                $control('yes', 'implemented', 'expired'),
                $control('yes', 'not_started', 'proven'),
            ])
        );
        $this->assertSame(
            ['applicable' => false, 'implementation_status' => 'not_started', 'evidence' => 'none', 'mapped' => true],
            Rules::fromMappedControls([$control('no', 'implemented', 'proven')])
        );
        $this->assertSame(
            ['applicable' => true, 'implementation_status' => 'not_started', 'evidence' => 'none', 'mapped' => false],
            Rules::fromMappedControls([]),
            'Sans mesure reliée : un écart, jamais un succès.'
        );
    }

    public function testUnknownCustomStatusIsNotAssessedYet(): void
    {
        $this->assertSame(
            ['applicable' => true, 'implementation_status' => 'not_started'],
            Rules::fromCustomStatus('anything')
        );
    }

    public function testFrameworkWithoutApplicableControlsHasNoScore(): void
    {
        $result = Rules::assess([], static fn (string $code): bool => true);
        $this->assertSame(0, $result['applicable']);
        $this->assertNull($result['score']);
        $this->assertNull($result['implementation_rate']);
        $this->assertSame([], $result['gaps']);
    }
}
