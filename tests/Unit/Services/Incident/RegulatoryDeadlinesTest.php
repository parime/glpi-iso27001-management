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
        $incident = self::incident(['nis2_applicable' => 0, 'personal_data_breach' => 1]);
        $rows = self::byKey(RegulatoryDeadlines::compute($incident));

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
        $early = static function (int $now, array $extra = []): string {
            $rows = self::byKey(RegulatoryDeadlines::compute(self::incident($extra), $now));

            return $rows['nis2_early_warning']['status'];
        };

        self::assertSame(RegulatoryDeadlines::STATUS_PENDING, $early($start + 3600));
        self::assertSame(
            RegulatoryDeadlines::STATUS_DUE_SOON,
            $early($start + 20 * 3600),
            'moins d\'un quart du délai'
        );
        self::assertSame(RegulatoryDeadlines::STATUS_OVERDUE, $early($start + 25 * 3600));
        self::assertSame(
            RegulatoryDeadlines::STATUS_DONE,
            $early($start + 25 * 3600, ['nis2_early_warning_at' => '2026-10-18 20:00:00'])
        );
    }

    /**
     * Issue #372 : bandeaux en tête de l'incident, la plus urgente d'abord, sans les échéances
     * déjà notifiées, avec les heures restantes.
     */
    public function testBannersListPendingDeadlinesMostUrgentFirst(): void
    {
        $now = (int) strtotime(self::DETECTED) + 6 * 3600;
        $incident = self::incident(['personal_data_breach' => 1, 'nis2_early_warning_at' => '2026-10-18 10:00:00']);

        $rows = RegulatoryDeadlines::banners($incident, $now);

        self::assertSame(
            ['nis2_notification', 'gdpr_notification', 'nis2_final_report'],
            array_column($rows, 'key'),
            'l\'alerte précoce déjà notifiée ne s\'affiche plus'
        );
        self::assertSame(66, $rows[0]['hours_left']);
        self::assertSame(RegulatoryDeadlines::STATUS_PENDING, $rows[0]['status']);

        $late = RegulatoryDeadlines::banners(self::incident(), $now + 30 * 3600);
        self::assertSame('nis2_early_warning', $late[0]['key']);
        self::assertSame(RegulatoryDeadlines::STATUS_OVERDUE, $late[0]['status']);
        self::assertSame(0, $late[0]['hours_left'], 'jamais négatif une fois dépassée');
    }

    public function testEachAlertLevelIsRaisedOnlyOnce(): void
    {
        $now = (int) strtotime(self::DETECTED) + 20 * 3600;

        $first = RegulatoryDeadlines::toAlert(self::incident(), [], $now);
        self::assertSame(['nis2_early_warning'], array_column($first, 'key'));
        self::assertSame([], RegulatoryDeadlines::toAlert(self::incident(), ['nis2_early_warning:due_soon'], $now));

        $later = RegulatoryDeadlines::toAlert(self::incident(), ['nis2_early_warning:due_soon'], $now + 6 * 3600);
        $keys = array_map(static fn (array $r): string => $r['key'] . ':' . $r['status'], $later);
        self::assertSame(['nis2_early_warning:overdue'], $keys);
    }

    /**
     * Issue #620 : la décision motivée de ne pas notifier, inscrite au registre des violations, retire
     * l'échéance RGPD des bandeaux et des alertes, sans toucher aux échéances NIS2.
     */
    public function testRecordedDecisionNotToNotifyStopsTheGdprReminder(): void
    {
        $late = (int) strtotime('2026-11-30 00:00:00');
        $incident = self::incident(['personal_data_breach' => 1, RegulatoryDeadlines::GDPR_NOT_NOTIFIED_FLAG => true]);
        $rows = self::byKey(RegulatoryDeadlines::compute($incident, $late));

        self::assertSame(RegulatoryDeadlines::STATUS_NOT_REQUIRED, $rows['gdpr_notification']['status']);
        self::assertSame(RegulatoryDeadlines::STATUS_OVERDUE, $rows['nis2_notification']['status']);
        $banners = array_column(RegulatoryDeadlines::banners($incident, $late), 'key');
        self::assertNotContains('gdpr_notification', $banners);
        $alerts = array_column(RegulatoryDeadlines::toAlert($incident, [], $late), 'key');
        self::assertNotContains('gdpr_notification', $alerts);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, array{key: string, due: ?string, done_at: ?string, status: string, reason: ?string}>
     */
    private static function dora(array $extra = [], ?int $now = null): array
    {
        return self::byKey(RegulatoryDeadlines::compute(
            $extra + ['date' => self::DETECTED, 'dora_major' => 1],
            $now ?? (int) strtotime(self::DETECTED)
        ));
    }

    /**
     * Issue #600 : DORA, sans classification datée, la notification initiale tombe au plus tard
     * 24 h après la prise de connaissance ; les rapports suivants s'enchaînent sur l'échéance
     * précédente tant qu'elle n'est pas faite.
     */
    public function testDoraWithoutClassificationUsesThe24HourCeiling(): void
    {
        $rows = self::dora();

        self::assertSame(['dora_initial', 'dora_intermediate', 'dora_final'], array_keys($rows));
        self::assertSame('2026-10-19 08:00:00', $rows['dora_initial']['due']);
        self::assertSame('2026-10-22 08:00:00', $rows['dora_intermediate']['due']);
        self::assertSame('2026-11-22 08:00:00', $rows['dora_final']['due']);
    }

    public function testDoraInitialNotificationIsDueFourHoursAfterClassification(): void
    {
        $rows = self::dora(['dora_classified_at' => '2026-10-18 10:30:00']);

        self::assertSame('2026-10-18 14:30:00', $rows['dora_initial']['due']);
    }

    public function testDoraFourHoursNeverGoBeyondTheAwarenessCeiling(): void
    {
        // Classifié 22 h après la détection : 4 h mèneraient à 26 h, le plafond de 24 h l'emporte.
        $rows = self::dora(['dora_classified_at' => '2026-10-19 06:00:00']);

        self::assertSame('2026-10-19 08:00:00', $rows['dora_initial']['due']);
    }

    /**
     * Règlement délégué (UE) 2025/301, article 5.2 : classifié comme majeur plus de 24 h après la
     * prise de connaissance, l'incident est notifié dans les 4 h suivant la classification.
     */
    public function testLateDoraClassificationGivesFourHoursFromClassification(): void
    {
        $now = (int) strtotime('2026-10-20 09:00:00');
        $rows = self::dora(['dora_classified_at' => '2026-10-20 08:00:00'], $now);

        self::assertSame('2026-10-20 12:00:00', $rows['dora_initial']['due']);
        self::assertSame(RegulatoryDeadlines::STATUS_PENDING, $rows['dora_initial']['status']);
        self::assertSame('2026-10-23 12:00:00', $rows['dora_intermediate']['due']);
    }

    public function testDoraClassificationBeforeDetectionIsBroughtBackToDetection(): void
    {
        $rows = self::dora(['dora_classified_at' => '2026-10-17 08:00:00']);

        self::assertSame('2026-10-18 12:00:00', $rows['dora_initial']['due']);
    }

    public function testDoraReportsChainOnTheActualSubmissionDates(): void
    {
        $rows = self::dora([
            'dora_classified_at'   => '2026-10-18 09:00:00',
            'dora_initial_at'      => '2026-10-18 11:00:00',
            'dora_intermediate_at' => '2026-10-20 15:00:00',
        ]);

        self::assertSame(RegulatoryDeadlines::STATUS_DONE, $rows['dora_initial']['status']);
        self::assertSame('2026-10-21 11:00:00', $rows['dora_intermediate']['due'], '72 h après la notification initiale');
        self::assertSame('2026-11-20 15:00:00', $rows['dora_final']['due'], 'un mois après le rapport intermédiaire');
    }

    public function testDoraAlertsLikeNis2(): void
    {
        $classified = ['dora_classified_at' => '2026-10-18 08:00:00'];
        $soon = (int) strtotime('2026-10-18 11:30:00');
        self::assertSame(RegulatoryDeadlines::STATUS_DUE_SOON, self::dora($classified, $soon)['dora_initial']['status']);
        $late = (int) strtotime('2026-10-18 12:01:00');
        self::assertSame(RegulatoryDeadlines::STATUS_OVERDUE, self::dora($classified, $late)['dora_initial']['status']);
        self::assertSame(
            ['dora_initial:overdue'],
            array_map(
                static fn (array $r): string => $r['key'] . ':' . $r['status'],
                RegulatoryDeadlines::toAlert(['date' => self::DETECTED, 'dora_major' => 1] + $classified, [], $late)
            )
        );
    }

    public function testDoraIsNotApplicableBeforeItsApplicationDate(): void
    {
        $old = ['date' => '2024-12-01 08:00:00', 'dora_major' => 1];
        $rows = self::byKey(RegulatoryDeadlines::compute($old));

        self::assertSame(RegulatoryDeadlines::STATUS_NOT_APPLICABLE, $rows['dora_initial']['status']);
        self::assertSame(RegulatoryDeadlines::REASON_BEFORE_APPLICATION, $rows['dora_initial']['reason']);
        self::assertNull($rows['dora_initial']['due']);
        self::assertSame([], RegulatoryDeadlines::banners($old));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, array{key: string, due: ?string, done_at: ?string, status: string, reason: ?string}>
     */
    private static function cra(string $type, array $extra = [], ?int $now = null): array
    {
        return self::byKey(RegulatoryDeadlines::compute(
            $extra + ['date' => self::DETECTED, 'cra_report_type' => $type],
            $now ?? (int) strtotime(self::DETECTED)
        ));
    }

    /**
     * Issue #600 : CRA, vulnérabilité activement exploitée : 24 h, 72 h, puis rapport final 14 jours
     * après la mise à disposition de la mesure corrective, en attente tant qu'il n'y en a pas.
     */
    public function testCraVulnerabilityFinalReportWaitsForTheCorrectiveMeasure(): void
    {
        $rows = self::cra(RegulatoryDeadlines::CRA_VULNERABILITY);

        self::assertSame(['cra_early_warning', 'cra_notification', 'cra_final_report'], array_keys($rows));
        self::assertSame('2026-10-19 08:00:00', $rows['cra_early_warning']['due']);
        self::assertSame('2026-10-21 08:00:00', $rows['cra_notification']['due']);
        self::assertNull($rows['cra_final_report']['due']);
        self::assertSame(RegulatoryDeadlines::STATUS_WAITING, $rows['cra_final_report']['status']);
        self::assertSame(RegulatoryDeadlines::REASON_AWAITING_FIX, $rows['cra_final_report']['reason']);
        $late = (int) strtotime('2026-12-31 00:00:00');
        $vulnerability = ['date' => self::DETECTED, 'cra_report_type' => RegulatoryDeadlines::CRA_VULNERABILITY];
        self::assertNotContains(
            'cra_final_report',
            array_column(RegulatoryDeadlines::toAlert($vulnerability, [], $late), 'key'),
            'pas d\'alerte sans mesure corrective'
        );
    }

    public function testCraVulnerabilityFinalReportIs14DaysAfterTheFix(): void
    {
        $rows = self::cra(RegulatoryDeadlines::CRA_VULNERABILITY, ['cra_fix_available_at' => '2026-10-25 17:00:00']);

        self::assertSame('2026-11-08 17:00:00', $rows['cra_final_report']['due']);
        self::assertSame(RegulatoryDeadlines::STATUS_PENDING, $rows['cra_final_report']['status']);
    }

    public function testCraSevereIncidentFinalReportIsOneMonthAfterTheNotification(): void
    {
        $rows = self::cra(RegulatoryDeadlines::CRA_INCIDENT, ['cra_fix_available_at' => '2026-10-25 17:00:00']);
        self::assertSame('2026-11-21 08:00:00', $rows['cra_final_report']['due'], 'mesure corrective ignorée');

        $done = self::cra(RegulatoryDeadlines::CRA_INCIDENT, ['cra_notification_at' => '2026-10-19 18:00:00']);
        self::assertSame('2026-11-19 18:00:00', $done['cra_final_report']['due']);
    }

    public function testCraIsNotApplicableBefore11September2026(): void
    {
        $before = ['date' => '2026-09-10 23:00:00', 'cra_report_type' => RegulatoryDeadlines::CRA_INCIDENT];
        $rows = RegulatoryDeadlines::compute($before);

        self::assertSame(
            [RegulatoryDeadlines::STATUS_NOT_APPLICABLE],
            array_values(array_unique(array_column($rows, 'status')))
        );
        $from = self::byKey(RegulatoryDeadlines::compute(['date' => '2026-09-11 00:00:00'] + $before));
        self::assertNotNull($from['cra_early_warning']['due']);
    }

    public function testUnknownCraTypeAndUncheckedBoxesChangeNothing(): void
    {
        $base = ['date' => self::DETECTED, 'nis2_applicable' => 1];
        $expected = RegulatoryDeadlines::compute($base);

        self::assertSame(
            $expected,
            RegulatoryDeadlines::compute($base + ['cra_report_type' => 'other', 'dora_major' => 0])
        );
        self::assertSame(
            ['nis2_early_warning', 'nis2_notification', 'nis2_final_report'],
            array_column($expected, 'key')
        );
    }

    public function testDisabledRegimesAreLeftOut(): void
    {
        $fields = [
            'date'            => self::DETECTED,
            'dora_major'      => 1,
            'cra_report_type' => RegulatoryDeadlines::CRA_INCIDENT,
            'nis2_applicable' => 1,
        ];
        $keys = static fn (bool $dora, bool $cra): array => array_column(
            RegulatoryDeadlines::compute(RegulatoryDeadlines::restrictRegimes($fields, $dora, $cra)),
            'key'
        );

        self::assertSame(['nis2_early_warning', 'nis2_notification', 'nis2_final_report'], $keys(false, false));
        self::assertContains('dora_initial', $keys(true, false));
        self::assertNotContains('cra_early_warning', $keys(true, false));
        self::assertContains('cra_early_warning', $keys(false, true));
        self::assertNotContains('dora_initial', $keys(false, true));
    }
}
