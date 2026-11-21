<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\SupplierPortalRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #865, lot 2 : lien sécurisé du fournisseur (jeton, état du lien, réponses reçues, débit).
 */
final class SupplierPortalRulesTest extends TestCase
{
    private const NOW = '2026-03-10 12:00:00';

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            ['id' => 11, 'code' => 'Q01', 'question' => 'MFA?', 'answer' => '', 'answer_comment' => ''],
            ['id' => 12, 'code' => 'Q02', 'question' => 'Backup?', 'answer' => 'no', 'answer_comment' => 'old'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function link(array $fields = []): array
    {
        return $fields + [
            'is_revoked' => 0, 'uses' => 0, 'max_uses' => 1, 'date_expiration' => '2026-03-11 12:00:00',
        ];
    }

    public function testTokenIsLongRandomAndOnlyItsHashIsKept(): void
    {
        $token = Rules::generateToken();
        $this->assertSame(64, strlen($token));
        $this->assertTrue(Rules::isWellFormed($token));
        $this->assertNotSame($token, Rules::generateToken());
        $this->assertSame(hash('sha256', $token), Rules::hashToken($token));
        $this->assertNotSame($token, Rules::hashToken($token));
    }

    public function testMalformedTokensAreRefused(): void
    {
        $values = ['', 'abc', str_repeat('0', 63), str_repeat('G', 64), str_repeat('0', 64) . "\n", ['x'], null, 12];
        foreach ($values as $bad) {
            $this->assertFalse(Rules::isWellFormed($bad));
        }
        $this->assertTrue(Rules::isWellFormed(str_repeat('0', 64)));
    }

    public function testStatusOfALink(): void
    {
        $this->assertSame(Rules::STATUS_ACTIVE, Rules::status(self::link(), self::NOW, false));
        $expired = self::link(['date_expiration' => self::NOW]);
        $this->assertSame(Rules::STATUS_EXPIRED, Rules::status($expired, self::NOW, false));
        $never = self::link(['date_expiration' => '']);
        $this->assertSame(Rules::STATUS_EXPIRED, Rules::status($never, self::NOW, false));
        $this->assertSame(Rules::STATUS_USED, Rules::status(self::link(['uses' => 1]), self::NOW, false));
        $revoked = self::link(['is_revoked' => 1, 'uses' => 1]);
        $this->assertSame(Rules::STATUS_REVOKED, Rules::status($revoked, self::NOW, false));
        $this->assertSame(Rules::STATUS_CLOSED, Rules::status(self::link(), self::NOW, true));
    }

    public function testBoundsOfDaysAndUses(): void
    {
        $this->assertSame(Rules::DEFAULT_DAYS, Rules::normalizeDays('abc'));
        $this->assertSame(1, Rules::normalizeDays(-5));
        $this->assertSame(Rules::MAX_DAYS, Rules::normalizeDays(9999));
        $this->assertSame(Rules::DEFAULT_USES, Rules::normalizeUses(null));
        $this->assertSame(Rules::MAX_USES, Rules::normalizeUses(100));
    }

    public function testOnlyQuestionsOfTheEvaluationAndKnownAnswersAreKept(): void
    {
        $result = Rules::collect(
            self::rows(),
            [11 => 'yes', 12 => 'maybe', 99 => 'yes', 'x' => 'no'],
            [11 => "<script>alert(1)</script>\x07", 99 => 'other evaluation']
        );
        $this->assertSame([11 => ['answer' => 'yes', 'comment' => '<script>alert(1)</script>']], $result['answers']);
        $this->assertSame(['Q02'], $result['errors']);
        $this->assertSame(['answers' => [], 'errors' => []], Rules::collect(self::rows(), 'yes', null));
        $this->assertSame([], Rules::collect(self::rows(), [11 => ['yes']], [])['answers']);
    }

    public function testDraftBecomesAPreviewAgainstCurrentAnswers(): void
    {
        $parsed = Rules::changes(
            self::rows(),
            [
                11 => ['answer' => 'yes', 'comment' => ''],
                12 => ['answer' => 'no', 'comment' => 'old'],
                99 => ['answer' => 'yes'],
            ],
            [12 => ['name' => 'Q02-Backup.PDF']]
        );
        $this->assertNull($parsed['error']);
        $this->assertCount(2, $parsed['changes']);
        $this->assertSame('yes', $parsed['changes'][0]['answer']);
        $this->assertNull($parsed['changes'][1]['answer']);
        $this->assertNull($parsed['changes'][1]['comment']);
        $this->assertSame(['q02-backup.pdf' => [12]], $parsed['evidence']);
        $this->assertSame([], Rules::changes(self::rows(), [11 => ['answer' => 'bogus']], [])['changes']);
    }

    public function testEvidenceNameIsPrefixedByTheQuestionCode(): void
    {
        $this->assertSame('Q01-certificate.pdf', Rules::evidenceName('Q01', 'certificate.pdf'));
        $this->assertSame('A_B-x.pdf', Rules::evidenceName('A/B', 'x.pdf'));
    }

    public function testThrottleFixedWindow(): void
    {
        $first = Rules::throttle(null, self::NOW, 2, 600);
        $this->assertSame(['hits' => 1, 'window_start' => self::NOW, 'allowed' => true], $first);
        $second = Rules::throttle($first, '2026-03-10 12:01:00', 2, 600);
        $this->assertTrue($second['allowed']);
        $third = Rules::throttle($second, '2026-03-10 12:02:00', 2, 600);
        $this->assertFalse($third['allowed']);
        $this->assertSame(self::NOW, $third['window_start']);
        $later = Rules::throttle($third, '2026-03-10 12:10:00', 2, 600);
        $this->assertSame(['hits' => 1, 'window_start' => '2026-03-10 12:10:00', 'allowed' => true], $later);
    }
}
