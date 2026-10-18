<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use PHPUnit\Framework\TestCase;

/**
 * Issue #128 : délais de notification NIS2 (24 h, 72 h, 1 mois) et RGPD (72 h).
 */
final class RegulatoryDeadlinesTest extends TestCase
{
    private const DETECTED = '2026-10-18 08:00:00';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function incident(array $extra = []): array
    {
        return $extra + ['date' => self::DETECTED, 'nis2_applicable' => 1, 'personal_data_breach' => 0];
    }

    /**
     * @param list<array{key: string, due: string, done_at: ?string, status: string}> $rows
     * @return array<string, array{key: string, due: string, done_at: ?string, status: string}>
     */
    private static function byKey(array $rows): array
    {
        return array_column($rows, null, 'key');
    }

    public function testNis2DeadlinesAreComputedFromDetection(): void
    {
        $rows = self::byKey(RegulatoryDeadlines::compute(self::incident(), (int) strtotime(self::DETECTED)));

        self::assertSame(['nis2_early_warning', 'nis2_notification', 'nis2_final_report'], array_keys($rows));
        self::assertSame('2026-10-19 08:00:00', $rows['nis2_early_warning']['due']);
        self::assertSame('2026-10-21 08:00:00', $rows['nis2_notification']['due']);
        self::assertSame('2026-11-18 08:00:00', $rows['nis2_final_report']['due']);
    }

    public function testGdprDeadlineOnlyForPersonalDataBreaches(): void
    {
        $rows = self::byKey(RegulatoryDeadlines::compute(self::incident(['nis2_applicable' => 0, 'personal_data_breach' => 1])));

        self::assertSame(['gdpr_notification'], array_keys($rows));
        self::assertSame('2026-10-21 08:00:00', $rows['gdpr_notification']['due']);
    }

    public function testNothingWhenNoRegulationApplies(): void
    {
        self::assertSame([], RegulatoryDeadlines::compute(self::incident(['nis2_applicable' => 0])));
    }

    public function testStatusFollowsTimeAndRecordedNotification(): void
    {
        $start = (int) strtotime(self::DETECTED);
        $early = static fn (int $now, array $extra = []): string => self::byKey(RegulatoryDeadlines::compute(self::incident($extra), $now))['nis2_early_warning']['status'];

        self::assertSame(RegulatoryDeadlines::STATUS_PENDING, $early($start + 3600));
        self::assertSame(RegulatoryDeadlines::STATUS_DUE_SOON, $early($start + 20 * 3600), 'moins d\'un quart du délai');
        self::assertSame(RegulatoryDeadlines::STATUS_OVERDUE, $early($start + 25 * 3600));
        self::assertSame(RegulatoryDeadlines::STATUS_DONE, $early($start + 25 * 3600, ['nis2_early_warning_at' => '2026-10-18 20:00:00']));
    }

    public function testEachAlertLevelIsRaisedOnlyOnce(): void
    {
        $now = (int) strtotime(self::DETECTED) + 20 * 3600;

        $first = RegulatoryDeadlines::toAlert(self::incident(), [], $now);
        self::assertSame(['nis2_early_warning'], array_column($first, 'key'));
        self::assertSame([], RegulatoryDeadlines::toAlert(self::incident(), ['nis2_early_warning:due_soon'], $now));

        $later = RegulatoryDeadlines::toAlert(self::incident(), ['nis2_early_warning:due_soon'], $now + 6 * 3600);
        self::assertSame(['nis2_early_warning:overdue'], array_map(static fn (array $r): string => $r['key'] . ':' . $r['status'], $later));
    }
}
