<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\SupplierAnswerFile as AnswerFile;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1159 : échelle de conformité des questions fournisseurs (Conforme = 1, Partiellement
 * conforme = 0,5, Non conforme = 0, Non applicable exclu), sans changer les questions Oui / Non.
 * Aucun libellé traduit attendu : la CI tourne en anglais, l'instance de test en français.
 */
final class ComplianceScaleTest extends TestCase
{
    private const LABELS = ['yes' => 'L-yes', 'partial' => 'L-partial', 'no' => 'L-no', 'na' => 'L-na'];

    private const SCALE = ['yes' => 'S-ok', 'partial' => 'S-part', 'no' => 'S-ko', 'na' => 'S-na'];

    private const HEADER = ['c', 's', 'q', 'g', 'e', 'r', 'a', 'x', 'm', 'f'];

    public function testTheScaleIsScoredOneHalfZeroAndNotApplicableIsLeftOut(): void
    {
        $scale = ['answer_type' => Rules::TYPE_COMPLIANCE, 'weight' => 2];

        self::assertSame(100, Rules::score([$scale + ['answer' => 'yes']]));
        self::assertSame(50, Rules::score([$scale + ['answer' => 'partial']]));
        self::assertSame(0, Rules::score([$scale + ['answer' => 'no']]));
        self::assertNull(Rules::score([$scale + ['answer' => 'na']]));
        // (2 x 1 + 2 x 0,5 + 2 x 0) / 6 = 50 %, la question non applicable ne compte pas.
        self::assertSame(50, Rules::score([
            $scale + ['answer' => 'yes'],
            $scale + ['answer' => 'partial'],
            $scale + ['answer' => 'no'],
            $scale + ['answer' => 'na'],
        ]));
    }

    public function testTheScaleAlwaysExpectsCompliantEvenIfTheStoredExpectedAnswerIsNo(): void
    {
        $row = ['answer_type' => Rules::TYPE_COMPLIANCE, 'expected_answer' => 'no', 'weight' => 1];

        self::assertSame('yes', Rules::expectedFor($row));
        self::assertSame(100, Rules::score([$row + ['answer' => 'yes']]));
        $summary = Rules::summary([$row + ['answer' => 'no'], $row + ['answer' => 'na']]);
        self::assertSame(1, $summary['gaps']);
        self::assertSame(1, $summary['not_applicable']);
        self::assertSame(0, $summary['score']);
    }

    public function testYesNoQuestionsAreUnchanged(): void
    {
        $legacy = ['expected_answer' => 'no', 'weight' => 1, 'answer' => 'no'];

        self::assertSame(Rules::TYPE_YES_NO, Rules::normalizeAnswerType(null));
        self::assertSame(Rules::TYPE_YES_NO, Rules::normalizeAnswerType('<script>'));
        self::assertSame('no', Rules::expectedFor($legacy));
        self::assertSame(100, Rules::score([$legacy]));
        self::assertSame(
            Rules::fingerprint([['code' => 'Q01', 'question' => 'x']]),
            Rules::fingerprint([['code' => 'Q01', 'question' => 'x', 'answer_type' => Rules::TYPE_YES_NO]]),
            'Les fichiers et liens déjà envoyés pour des questions Oui / Non restent reconnus.'
        );
        self::assertNotSame(
            Rules::fingerprint([['code' => 'Q01', 'question' => 'x']]),
            Rules::fingerprint([['code' => 'Q01', 'question' => 'x', 'answer_type' => Rules::TYPE_COMPLIANCE]])
        );
    }

    public function testTheExportUsesTheScaleLabelsAndTheImportRecognisesThem(): void
    {
        $answers = [
            ['id' => 1, 'code' => 'Q01', 'question' => 'q1', 'expected_answer' => 'yes', 'weight' => 1,
                'answer_type' => Rules::TYPE_COMPLIANCE, 'answer' => 'partial', 'answer_comment' => ''],
            ['id' => 2, 'code' => 'Q02', 'question' => 'q2', 'expected_answer' => 'no', 'weight' => 1,
                'answer_type' => Rules::TYPE_YES_NO, 'answer' => 'no', 'answer_comment' => ''],
        ];
        $content = AnswerFile::build(9, $answers, self::HEADER, self::LABELS, self::SCALE);

        self::assertStringContainsString('Q01;;q1;;S-ok;;"S-ok / S-part / S-ko / S-na";S-part', $content);
        self::assertStringContainsString('Q02;;q2;;L-no;;"L-yes / L-partial / L-no / L-na";L-no', $content);
        $parsed = AnswerFile::parse($content, 9, $answers, self::LABELS, self::SCALE);
        self::assertNull($parsed['error']);
        self::assertSame([], $parsed['changes']);

        $returned = str_replace(';S-part;', ';S-na;', $content);
        $parsed = AnswerFile::parse($returned, 9, $answers, self::LABELS, self::SCALE);
        self::assertSame('na', $parsed['changes'][0]['answer']);
        self::assertSame(Rules::TYPE_COMPLIANCE, $parsed['changes'][0]['answer_type']);
    }

    public function testUsualScaleWordsAreRecognised(): void
    {
        self::assertSame('yes', AnswerFile::matchAnswer('Compliant', []));
        self::assertSame('partial', AnswerFile::matchAnswer('Partially compliant', []));
        self::assertSame('no', AnswerFile::matchAnswer('Non-compliant', []));
        self::assertSame('na', AnswerFile::matchAnswer('Not applicable', []));
    }
}
