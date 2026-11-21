<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\SupplierAnswerFile as AnswerFile;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #865 : questionnaire fournisseur hors ligne (export CSV, réimport contrôlé, fichiers piégés).
 */
final class SupplierAnswerFileTest extends TestCase
{
    private const LABELS = ['yes' => 'Yes', 'partial' => 'Partial', 'no' => 'No', 'na' => 'Not applicable'];

    private const HEADER = [
        'Code', 'Section', 'Question', 'Guidance', 'Expected answer', 'Evidence required', 'Allowed answers',
        'Answer', 'Comment', 'Evidence file name',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    private static function answers(): array
    {
        return [
            [
                'id' => 11, 'code' => 'Q01', 'section' => 'Access', 'question' => 'MFA for administrators?',
                'guidance' => '', 'expected_answer' => 'yes', 'weight' => 3, 'requires_evidence' => 1,
                'answer' => '', 'answer_comment' => '',
            ],
            [
                'id' => 12, 'code' => 'Q02', 'section' => 'Backup', 'question' => '=HYPERLINK("http://x")',
                'guidance' => '', 'expected_answer' => 'yes', 'weight' => 1, 'requires_evidence' => 0,
                'answer' => 'no', 'answer_comment' => 'old',
            ],
        ];
    }

    /**
     * @param list<list<string>> $lines
     */
    private static function csv(array $lines, string $separator = ';'): string
    {
        return implode("\n", array_map(static fn (array $cells): string => implode($separator, $cells), $lines));
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function returned(array $rows, int $evaluationId = 7, ?string $fingerprint = null): string
    {
        $fingerprint ??= Rules::fingerprint(self::answers());

        return self::csv(array_merge(
            [[AnswerFile::MARKER, '1', (string) $evaluationId, $fingerprint], self::HEADER],
            $rows
        ));
    }

    /**
     * @return list<string>
     */
    private static function row(string $code, string $answer, string $comment = '', string $evidence = ''): array
    {
        return [$code, '', '', '', '', '', '', $answer, $comment, $evidence];
    }

    public function testTheExportCanBeReimportedUnchanged(): void
    {
        $content = AnswerFile::build(7, self::answers(), self::HEADER, self::LABELS);

        self::assertStringStartsWith("\u{FEFF}" . AnswerFile::MARKER . ';1;7;', $content);
        $parsed = AnswerFile::parse($content, 7, self::answers(), self::LABELS);
        self::assertNull($parsed['error']);
        self::assertSame([], $parsed['changes']);
        self::assertSame([], $parsed['warnings']);
    }

    public function testFormulasAreNeutralisedInTheExport(): void
    {
        $content = AnswerFile::build(7, self::answers(), self::HEADER, self::LABELS);

        self::assertStringContainsString("'=HYPERLINK", $content);
        self::assertSame("'+1", AnswerFile::protectCell('+1'));
        self::assertSame("'@SUM(A1)", AnswerFile::protectCell('@SUM(A1)'));
        self::assertSame('plain', AnswerFile::protectCell('plain'));
    }

    public function testAnswersAreMatchedByCodeAndLabel(): void
    {
        $parsed = AnswerFile::parse(self::returned([
            self::row('Q01', 'Oui', 'Enforced since 2025', 'mfa-policy.pdf'),
            self::row('Q02', 'partial'),
        ]), 7, self::answers(), self::LABELS);

        self::assertNull($parsed['error']);
        self::assertCount(2, $parsed['changes']);
        self::assertSame('yes', $parsed['changes'][0]['answer']);
        self::assertSame('Enforced since 2025', $parsed['changes'][0]['comment']);
        self::assertSame('partial', $parsed['changes'][1]['answer']);
        self::assertNull($parsed['changes'][1]['comment'], 'An empty cell does not change the comment.');
        self::assertSame(['mfa-policy.pdf' => [11]], $parsed['evidence']);
    }

    public function testTranslatedLabelsAndCommasAreAccepted(): void
    {
        $content = self::csv([
            [AnswerFile::MARKER, '1', '7', Rules::fingerprint(self::answers())],
            self::HEADER,
            ['Q01', '', '', '', '', '', '', 'Sans objet', '', ''],
        ], ',');

        $parsed = AnswerFile::parse($content, 7, self::answers(), ['na' => 'Sans objet'] + self::LABELS);

        self::assertSame('na', $parsed['changes'][0]['answer']);
    }

    public function testAFileOfAnotherEvaluationIsRefused(): void
    {
        $parsed = AnswerFile::parse(self::returned([self::row('Q01', 'yes')], 8), 7, self::answers(), self::LABELS);

        self::assertSame(AnswerFile::ERROR_OTHER_EVALUATION, $parsed['error']);
        self::assertSame([], $parsed['changes']);
    }

    public function testAFileMadeOnOtherQuestionsIsRefused(): void
    {
        $parsed = AnswerFile::parse(
            self::returned([self::row('Q01', 'yes')], 7, '0000000000000000'),
            7,
            self::answers(),
            self::LABELS
        );

        self::assertSame(AnswerFile::ERROR_OTHER_QUESTIONS, $parsed['error']);
    }

    public function testTrappedFilesAreRefused(): void
    {
        $answers = self::answers();

        $error = static fn (string $content): ?string
            => AnswerFile::parse($content, 7, $answers, self::LABELS)['error'];

        self::assertSame(AnswerFile::ERROR_EMPTY, $error("\u{FEFF}  \n"));
        self::assertSame(AnswerFile::ERROR_BINARY, $error("PK\x03\x04xl/workbook"));
        self::assertSame(AnswerFile::ERROR_BINARY, $error("%PDF-1.7\n"));
        self::assertSame(AnswerFile::ERROR_BINARY, $error("a;b\0;c"));
        self::assertSame(
            AnswerFile::ERROR_TOO_LARGE,
            AnswerFile::parse(str_repeat('a', AnswerFile::MAX_BYTES + 1), 7, $answers, self::LABELS)['error']
        );
        self::assertSame(
            AnswerFile::ERROR_NOT_QUESTIONNAIRE,
            AnswerFile::parse("Code;Answer\nQ01;yes", 7, $answers, self::LABELS)['error']
        );
        $noHeader = self::csv([[AnswerFile::MARKER, '1', '7', Rules::fingerprint($answers)], ['Code']]);
        self::assertSame(AnswerFile::ERROR_NO_HEADER, AnswerFile::parse($noHeader, 7, $answers, self::LABELS)['error']);
    }

    public function testDoubtfulLinesAreReportedAndIgnored(): void
    {
        $parsed = AnswerFile::parse(self::returned([
            self::row('Q99', 'yes'),
            self::row('Q01', 'maybe'),
            self::row('Q02', 'yes'),
            self::row('Q02', 'no'),
        ]), 7, self::answers(), self::LABELS);

        $warnings = array_column($parsed['warnings'], 'warning');
        self::assertSame([
            AnswerFile::WARNING_UNKNOWN_CODE,
            AnswerFile::WARNING_BAD_ANSWER,
            AnswerFile::WARNING_DUPLICATE_CODE,
        ], $warnings);
        self::assertCount(1, $parsed['changes']);
        self::assertSame('yes', $parsed['changes'][0]['answer']);
    }

    public function testInjectedTextStaysPlainText(): void
    {
        $parsed = AnswerFile::parse(self::returned([
            self::row('Q01', "'=1+1", "<script>alert(1)</script>\x07", '..\\..\\etc\\passwd'),
        ]), 7, self::answers(), self::LABELS);

        // Réponse hors liste (formule) : ligne ignorée.
        self::assertSame(AnswerFile::WARNING_BAD_ANSWER, $parsed['warnings'][0]['warning']);
        // Q02 absente du fichier : signalée, sa réponse ne change pas.
        self::assertSame(AnswerFile::WARNING_MISSING_QUESTION, $parsed['warnings'][1]['warning']);

        $parsed = AnswerFile::parse(self::returned([
            self::row('Q01', 'yes', "'=cmd|' /C calc'!A0\x07<b>x</b>", '../../etc/passwd'),
        ]), 7, self::answers(), self::LABELS);
        // Le commentaire est gardé comme texte (échappé à l'affichage), sans caractère de contrôle ;
        // le nom de pièce est réduit à un nom de fichier.
        self::assertSame("=cmd|' /C calc'!A0<b>x</b>", $parsed['changes'][0]['comment']);
        self::assertSame(['passwd' => [11]], $parsed['evidence']);
    }

    public function testWindows1252IsConverted(): void
    {
        $content = (string) mb_convert_encoding(
            self::returned([self::row('Q01', 'yes', 'Sécurité')]),
            'Windows-1252',
            'UTF-8'
        );

        $parsed = AnswerFile::parse($content, 7, self::answers(), self::LABELS);

        self::assertSame('Sécurité', $parsed['changes'][0]['comment']);
    }

    public function testEvidenceNamesAreReducedToAFileName(): void
    {
        self::assertSame('report.pdf', AnswerFile::evidenceName('C:\\Users\\x\\Report.PDF'));
        self::assertSame('', AnswerFile::evidenceName('..'));
        self::assertSame('', AnswerFile::evidenceName(''));
    }
}
