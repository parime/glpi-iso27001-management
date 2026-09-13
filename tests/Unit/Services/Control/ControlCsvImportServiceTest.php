<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCsvImportService;
use PHPUnit\Framework\TestCase;

final class ControlCsvImportServiceTest extends TestCase
{
    private const EXISTING_CODES = ['A.5.1', 'A.5.2', 'A.8.9'];

    private const APPLICABILITY_LABELS = [
        'yes'     => 'Applicable',
        'no'      => 'Non applicable',
        'partial' => 'Partiellement applicable',
    ];

    private const STATUS_LABELS = [
        'not_started' => 'Non démarré',
        'in_progress' => 'En cours',
        'implemented' => 'Mis en œuvre',
        'verified'    => 'Vérifié',
    ];

    public function testAcceptsAFullyValidRow(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.5.1',
                'applicabilite' => 'Applicable',
                'etat_mise_en_oeuvre' => 'Mis en œuvre',
                'justification' => '',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame([], $result['rejected']);
        self::assertSame([[
            'code' => 'A.5.1',
            'applicability' => 'yes',
            'implementation_status' => 'implemented',
            'justification' => '',
        ]], $result['valid']);
    }

    public function testAcceptsANonApplicableRowWithAJustification(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.5.2',
                'applicabilite' => 'Non applicable',
                'etat_mise_en_oeuvre' => 'Non démarré',
                'justification' => 'Aucun fournisseur concerné.',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertCount(1, $result['valid']);
        self::assertSame([], $result['rejected']);
    }

    public function testRejectsAnUnknownCodeWithoutCreatingAnything(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.99.9',
                'applicabilite' => 'Applicable',
                'etat_mise_en_oeuvre' => 'Vérifié',
                'justification' => '',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame([], $result['valid']);
        self::assertSame(
            [['code' => 'A.99.9', 'reason' => ControlCsvImportService::REASON_UNKNOWN_CODE]],
            $result['rejected']
        );
    }

    public function testRejectsAnUnrecognizedApplicabilityLabel(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.5.1',
                'applicabilite' => 'Oui',
                'etat_mise_en_oeuvre' => 'Vérifié',
                'justification' => '',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame([], $result['valid']);
        self::assertSame(
            ControlCsvImportService::REASON_UNKNOWN_APPLICABILITY,
            $result['rejected'][0]['reason']
        );
    }

    public function testRejectsAnUnrecognizedStatusLabel(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.5.1',
                'applicabilite' => 'Applicable',
                'etat_mise_en_oeuvre' => 'Termine',
                'justification' => '',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame(ControlCsvImportService::REASON_UNKNOWN_STATUS, $result['rejected'][0]['reason']);
    }

    public function testRejectsAPartiallyApplicableRowMissingJustification(): void
    {
        $result = ControlCsvImportService::process(
            [[
                'code' => 'A.8.9',
                'applicabilite' => 'Partiellement applicable',
                'etat_mise_en_oeuvre' => 'En cours',
                'justification' => '   ',
            ]],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame([], $result['valid']);
        self::assertSame(
            ControlCsvImportService::REASON_MISSING_JUSTIFICATION,
            $result['rejected'][0]['reason']
        );
    }

    public function testProcessesEachRowIndependently(): void
    {
        $result = ControlCsvImportService::process(
            [
                [
                    'code' => 'A.5.1',
                    'applicabilite' => 'Applicable',
                    'etat_mise_en_oeuvre' => 'Vérifié',
                    'justification' => '',
                ],
                [
                    'code' => 'A.99.9',
                    'applicabilite' => 'Applicable',
                    'etat_mise_en_oeuvre' => 'Vérifié',
                    'justification' => '',
                ],
                [
                    'code' => 'A.5.2',
                    'applicabilite' => 'Applicable',
                    'etat_mise_en_oeuvre' => 'Vérifié',
                    'justification' => '',
                ],
            ],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertCount(2, $result['valid']);
        self::assertCount(1, $result['rejected']);
    }

    public function testMissingColumnsAreTreatedAsEmptyStringsNotErrors(): void
    {
        $result = ControlCsvImportService::process(
            [['code' => 'A.5.1']],
            self::EXISTING_CODES,
            self::APPLICABILITY_LABELS,
            self::STATUS_LABELS
        );

        self::assertSame([], $result['valid']);
        self::assertSame(
            ControlCsvImportService::REASON_UNKNOWN_APPLICABILITY,
            $result['rejected'][0]['reason']
        );
    }
}
