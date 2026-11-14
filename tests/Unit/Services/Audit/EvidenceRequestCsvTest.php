<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Audit;

use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestCsv;
use PHPUnit\Framework\TestCase;

/**
 * Issue #538 : import de la liste des pièces demandées par l'auditeur (CSV d'un tableur).
 */
final class EvidenceRequestCsvTest extends TestCase
{
    public function testFrenchHeadersSemicolonsAndSpreadsheetDates(): void
    {
        $csv = "\u{FEFF}Référence;Mesure;Description;Échéance;Responsable\r\n"
            . "REQ-01;a.5.15;\"Revue des droits, dernier trimestre\";30/11/2026;normal\r\n"
            . "\r\n"
            . "REQ-02;A.8.13;\"Rapport de sauvegarde\nsigné\";2026-12-05;\r\n";
        $result = EvidenceRequestCsv::parse($csv);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);
        self::assertSame([
            'line' => 2, 'reference' => 'REQ-01', 'control' => 'A.5.15',
            'description' => 'Revue des droits, dernier trimestre', 'due_date' => '2026-11-30', 'assignee' => 'normal',
        ], $result['rows'][0]);
        self::assertSame("Rapport de sauvegarde\nsigné", $result['rows'][1]['description'], 'cellule sur deux lignes');
        self::assertSame('', $result['rows'][1]['assignee']);
    }

    public function testEnglishHeadersInAnyOrderWithCommas(): void
    {
        $csv = "Description,Due date,Reference\nAccess review evidence,,R1\n";
        $result = EvidenceRequestCsv::parse($csv);

        self::assertSame([], $result['errors']);
        self::assertSame('R1', $result['rows'][0]['reference']);
        self::assertNull($result['rows'][0]['due_date']);
        self::assertSame('', $result['rows'][0]['control'], 'colonne absente');
    }

    public function testInvalidLinesAreReportedWithTheirNumber(): void
    {
        $csv = "reference;description;due date\n;;2026-12-01\nR2;Texte;31/02/2026\nR3;Texte;\n";
        $result = EvidenceRequestCsv::parse($csv);

        self::assertSame([
            ['line' => 2, 'error' => EvidenceRequestCsv::ERROR_MISSING_TEXT],
            ['line' => 3, 'error' => EvidenceRequestCsv::ERROR_BAD_DATE],
        ], $result['errors']);
        self::assertSame(['R3'], array_column($result['rows'], 'reference'));
    }

    public function testAFileWithoutAKnownHeaderIsRefused(): void
    {
        self::assertSame(
            [['line' => 1, 'error' => EvidenceRequestCsv::ERROR_NO_HEADER]],
            EvidenceRequestCsv::parse("foo;bar\n1;2\n")['errors']
        );
        self::assertSame(
            [['line' => 0, 'error' => EvidenceRequestCsv::ERROR_NO_HEADER]],
            EvidenceRequestCsv::parse("\n\n")['errors']
        );
    }

    public function testWindows1252IsConverted(): void
    {
        $csv = (string) mb_convert_encoding("Référence;Description\nR1;Procédure d'accès\n", 'Windows-1252', 'UTF-8');
        $result = EvidenceRequestCsv::parse($csv);

        self::assertSame("Procédure d'accès", $result['rows'][0]['description']);
    }

    public function testTheNumberOfRowsIsBounded(): void
    {
        $csv = "reference\n" . str_repeat("R\n", EvidenceRequestCsv::MAX_ROWS + 3);
        $result = EvidenceRequestCsv::parse($csv);

        self::assertCount(EvidenceRequestCsv::MAX_ROWS, $result['rows']);
        self::assertSame(EvidenceRequestCsv::ERROR_TOO_MANY, $result['errors'][0]['error']);
    }
}
