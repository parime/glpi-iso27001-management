<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Privacy;

use GlpiPlugin\Grcmanager\Services\Privacy\ProcessingActivityRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #375 : registre des activités de traitement (RGPD, article 30). Données fictives.
 */
final class ProcessingActivityRulesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function complete(): array
    {
        return [
            'status'               => 'active',
            'role'                 => 'controller',
            'purpose'              => 'Gestion de la paie',
            'legal_basis'          => 'legal_obligation',
            'data_subjects'        => '["employees"]',
            'data_categories'      => '["identity","financial"]',
            'sensitive_data'       => '[]',
            'retention_period'     => '5 ans après le départ',
            'security_measures'    => 'Accès restreint au service RH',
            'transfers_outside_eu' => 0,
            'dpia_status'          => 'not_required',
            'last_review_date'     => '2026-06-01',
        ];
    }

    public function testUnknownValuesFallBackToDefaults(): void
    {
        self::assertSame('controller', Rules::normalizeRole('chef'));
        self::assertSame('processor', Rules::normalizeRole('processor'));
        self::assertSame('draft', Rules::normalizeStatus(['active']));
        self::assertSame('', Rules::normalizeLegalBasis('favour'));
        self::assertSame('consent', Rules::normalizeLegalBasis('consent'));
        self::assertSame('', Rules::normalizeSafeguard('trust'));
        self::assertSame('not_assessed', Rules::normalizeDpiaStatus(null));
    }

    public function testListsKeepOnlyKnownKeysInCatalogOrder(): void
    {
        $json = Rules::encodeList(['health', '<script>', 'biometric', 'health'], Rules::SENSITIVE_DATA);
        self::assertSame('["biometric","health"]', $json);
        self::assertSame(['biometric', 'health'], Rules::decodeList($json, Rules::SENSITIVE_DATA));
        self::assertSame('[]', Rules::encodeList('health', Rules::SENSITIVE_DATA));
        self::assertSame([], Rules::decodeList('not json', Rules::SENSITIVE_DATA));
        self::assertSame([], Rules::decodeList(null, Rules::SENSITIVE_DATA));
    }

    public function testCompleteActivityHasNoGap(): void
    {
        self::assertSame([], Rules::checks(self::complete(), '2026-11-03'));
    }

    public function testEmptyActivityListsEveryArticle30Gap(): void
    {
        $checks = Rules::checks(['status' => 'draft'], '2026-11-03');
        self::assertSame([
            Rules::CHECK_NO_PURPOSE,
            Rules::CHECK_NO_LEGAL_BASIS,
            Rules::CHECK_NO_DATA_SUBJECTS,
            Rules::CHECK_NO_DATA_CATEGORIES,
            Rules::CHECK_NO_RETENTION,
            Rules::CHECK_NO_SECURITY,
        ], $checks);
        self::assertTrue(Rules::isBlocking(Rules::CHECK_NO_PURPOSE));
        self::assertFalse(Rules::isBlocking(Rules::CHECK_NO_SECURITY));
    }

    public function testLinkedControlsCountAsSecurityMeasures(): void
    {
        $fields = ['security_measures' => ''] + self::complete();
        self::assertSame([Rules::CHECK_NO_SECURITY], Rules::checks($fields, '2026-11-03'));
        self::assertSame([], Rules::checks($fields, '2026-11-03', true));
    }

    public function testProcessorMustNameTheController(): void
    {
        $fields = ['role' => 'processor'] + self::complete();
        self::assertSame([Rules::CHECK_NO_CONTROLLER], Rules::checks($fields, '2026-11-03'));
        $fields['controller_name'] = 'Client Exemple SA';
        self::assertSame([], Rules::checks($fields, '2026-11-03'));
    }

    public function testTransferOutsideEuNeedsASafeguard(): void
    {
        $fields = ['transfers_outside_eu' => 1] + self::complete();
        self::assertSame([Rules::CHECK_TRANSFER_WITHOUT_SAFEGUARD], Rules::checks($fields, '2026-11-03'));
        $fields['transfer_safeguards'] = 'scc';
        self::assertSame([], Rules::checks($fields, '2026-11-03'));
    }

    public function testSensitiveDataCallsForADpiaDecision(): void
    {
        $fields = ['sensitive_data' => '["health"]', 'data_categories' => '[]', 'dpia_status' => 'not_assessed']
            + self::complete();
        self::assertSame([Rules::CHECK_SENSITIVE_WITHOUT_DPIA], Rules::checks($fields, '2026-11-03'));
        $fields['dpia_status'] = 'required';
        self::assertSame([Rules::CHECK_DPIA_PENDING], Rules::checks($fields, '2026-11-03'));
        $fields['dpia_status'] = 'done';
        self::assertSame([], Rules::checks($fields, '2026-11-03'));
    }

    public function testActiveActivityIsReviewedEveryYear(): void
    {
        self::assertFalse(Rules::isReviewOverdue('2025-11-03', '2026-11-03'));
        self::assertTrue(Rules::isReviewOverdue('2025-11-02', '2026-11-03'));
        self::assertTrue(Rules::isReviewOverdue(null, '2026-11-03'));
        $fields = ['last_review_date' => null] + self::complete();
        self::assertSame([Rules::CHECK_REVIEW_OVERDUE], Rules::checks($fields, '2026-11-03'));
        // Un brouillon n'est pas encore soumis à la revue ; une fiche archivée n'est plus contrôlée.
        self::assertSame([], Rules::checks(['status' => 'draft'] + $fields, '2026-11-03'));
        self::assertSame([], Rules::checks(['status' => 'archived'], '2026-11-03'));
    }

    public function testCsvCellNeutralisesFormulas(): void
    {
        $formula = '=HYPERLINK("http://example.invalid")';
        self::assertSame("'" . $formula, Rules::csvCell($formula));
        self::assertSame("'+33 1 00 00 00 00", Rules::csvCell('+33 1 00 00 00 00'));
        self::assertSame("'-1", Rules::csvCell('-1'));
        self::assertSame("'@SUM(A1)", Rules::csvCell('@SUM(A1)'));
        self::assertSame('Gestion de la paie', Rules::csvCell('Gestion de la paie'));
        self::assertSame('', Rules::csvCell(null));
    }
}
