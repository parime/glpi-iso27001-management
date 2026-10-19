<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\SupplierQuestionnaire;
use PHPUnit\Framework\TestCase;

/**
 * Issue #127 : score du questionnaire de sécurité fournisseur.
 */
final class SupplierQuestionnaireTest extends TestCase
{
    public function testScoreCountsPartialAsHalfAndIgnoresNotApplicable(): void
    {
        $answers = [
            'certification'   => SupplierQuestionnaire::ANSWER_YES,
            'security_policy' => SupplierQuestionnaire::ANSWER_PARTIAL,
            'data_location'   => SupplierQuestionnaire::ANSWER_NO,
            'encryption'      => SupplierQuestionnaire::ANSWER_YES,
            'mfa'             => SupplierQuestionnaire::ANSWER_NA,
        ];

        // (1 + 0,5 + 0 + 1) / 4 = 62,5 % -> 63.
        self::assertSame(63, SupplierQuestionnaire::score($answers));
        self::assertSame('fair', SupplierQuestionnaire::level(63));
    }

    public function testNoScoreWithoutApplicableAnswer(): void
    {
        self::assertNull(SupplierQuestionnaire::score([]));
        self::assertNull(SupplierQuestionnaire::score(['mfa' => SupplierQuestionnaire::ANSWER_NA]));
        self::assertNull(SupplierQuestionnaire::level(null));
    }

    public function testUnknownQuestionsAndAnswersAreDropped(): void
    {
        $clean = SupplierQuestionnaire::sanitize([
            'certification' => 'yes',
            'mfa'           => 'peut-être',
            'inconnue'      => 'yes',
        ]);

        self::assertSame(['certification' => 'yes'], $clean);
    }

    public function testLevels(): void
    {
        self::assertSame('good', SupplierQuestionnaire::level(80));
        self::assertSame('fair', SupplierQuestionnaire::level(50));
        self::assertSame('poor', SupplierQuestionnaire::level(49));
    }
}
