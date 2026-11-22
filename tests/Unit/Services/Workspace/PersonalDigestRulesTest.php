<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Workspace;

use GlpiPlugin\Grcmanager\Services\Workspace\PersonalDigestRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #877 : règles du récapitulatif personnel par e-mail.
 */
final class PersonalDigestRulesTest extends TestCase
{
    private const TODAY = '2026-11-18';

    /**
     * @return array{source: string, id: int, name: string, due: ?string, url: string, detail: string,
     *     urgency: string, days_late: int}
     */
    private static function item(string $source, int $id, ?string $due, string $name = 'x'): array
    {
        return [
            'source' => $source, 'id' => $id, 'name' => $name, 'due' => $due, 'url' => '/item/' . $id,
            'detail' => '', 'urgency' => 'soon', 'days_late' => 0,
        ];
    }

    public function testUnknownOrEmptyFrequencyFallsBackToWeekly(): void
    {
        $this->assertSame(Rules::FREQUENCY_WEEKLY, Rules::normalizeFrequency(''));
        $this->assertSame(Rules::FREQUENCY_WEEKLY, Rules::normalizeFrequency(null));
        $this->assertSame(Rules::FREQUENCY_WEEKLY, Rules::normalizeFrequency('hourly'));
        $this->assertSame(Rules::FREQUENCY_DAILY, Rules::normalizeFrequency(' daily '));
        $this->assertSame(Rules::FREQUENCY_NEVER, Rules::normalizeFrequency('never'));
    }

    public function testDailySummaryIsDueOncePerDay(): void
    {
        $this->assertTrue(Rules::isDue('daily', null, self::TODAY));
        $this->assertTrue(Rules::isDue('daily', '2026-11-17 07:00:00', self::TODAY));
        $this->assertFalse(Rules::isDue('daily', '2026-11-18 07:00:00', self::TODAY));
    }

    public function testWeeklySummaryIsDueSevenDaysAfterTheLastOne(): void
    {
        $this->assertTrue(Rules::isDue('weekly', null, self::TODAY));
        $this->assertTrue(Rules::isDue('', '2026-11-11 07:00:00', self::TODAY), 'Par défaut : chaque semaine.');
        $this->assertFalse(Rules::isDue('weekly', '2026-11-12 07:00:00', self::TODAY));
    }

    public function testNeverMeansNoSummary(): void
    {
        $this->assertFalse(Rules::isDue('never', null, self::TODAY));
        $this->assertFalse(Rules::isDue('never', '2025-01-01', self::TODAY));
    }

    public function testItemsSeenWithSeveralProfilesAreListedOnceAndSorted(): void
    {
        $merged = Rules::merge([
            [self::item('risk_review', 1, '2026-11-20'), self::item('nonconformity', 2, null)],
            [self::item('risk_review', 1, '2026-11-20'), self::item('evidence', 3, '2026-11-10')],
        ]);
        $this->assertSame([3, 1, 2], array_column($merged, 'id'), 'Échéance croissante, sans échéance à la fin.');
        $this->assertSame([], Rules::merge([]));
    }

    public function testLinksBecomeAbsoluteForTheEmail(): void
    {
        $this->assertSame(
            'https://glpi.example.org/plugins/grcmanager/front/myspace.php',
            Rules::absoluteUrl('/glpi/plugins/grcmanager/front/myspace.php', '/glpi', 'https://glpi.example.org/')
        );
        $this->assertSame(
            'https://glpi.example.org/glpi/plugins/grcmanager/front/risk.form.php?id=4',
            Rules::absoluteUrl('/plugins/grcmanager/front/risk.form.php?id=4', '', 'https://glpi.example.org/glpi')
        );
        $other = 'https://other.example.org/x';
        $this->assertSame($other, Rules::absoluteUrl($other, '', 'https://a'));
        $this->assertSame('', Rules::absoluteUrl('', '/glpi', 'https://a'));
    }
}
