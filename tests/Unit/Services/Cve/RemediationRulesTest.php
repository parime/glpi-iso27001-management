<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\RemediationRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #601 : délais de correction des vulnérabilités du parc et indicateurs de respect.
 */
final class RemediationRulesTest extends TestCase
{
    /**
     * @return array{enabled: bool, days: array<string, int>, critical_percent: int}
     */
    private static function settings(): array
    {
        return Rules::settings(['enabled' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $start, string $level, ?string $fixed = null, bool $critical = false): array
    {
        return [
            'date_first_seen' => $start,
            'date_start'      => $start,
            'date_last_seen'  => $start,
            'date_fixed'      => $fixed,
            'level'           => $level,
            'is_critical'     => $critical,
            'date_level'      => $start,
            'previous_due'    => null,
        ];
    }

    public function testSettingsAreDisabledByDefaultWithProposedDeadlines(): void
    {
        $settings = Rules::settings([]);
        $this->assertFalse($settings['enabled']);
        $this->assertSame(
            ['kev' => 7, 'critical' => 15, 'high' => 30, 'medium' => 90, 'low' => 180],
            $settings['days']
        );
        $this->assertSame(50, $settings['critical_percent']);

        $custom = Rules::settings([
            'enabled'          => '1',
            'days'             => ['kev' => '3', 'critical' => 0, 'high' => 'abc', 'medium' => 99999],
            'critical_percent' => 1,
        ]);
        $this->assertTrue($custom['enabled']);
        $this->assertSame(
            ['kev' => 3, 'critical' => 15, 'high' => 30, 'medium' => Rules::MAX_DAYS, 'low' => 180],
            $custom['days'],
            'Valeur nulle ou illisible : délai proposé ; trop grande : bornée.'
        );
        $this->assertSame(Rules::MIN_CRITICAL_PERCENT, $custom['critical_percent']);
    }

    /**
     * @return iterable<string, array{?float, bool, string}>
     */
    public static function levels(): iterable
    {
        yield 'exploitée' => [3.1, true, Rules::KEV];
        yield 'critique' => [9.0, false, Rules::CRITICAL];
        yield 'élevée' => [7.0, false, Rules::HIGH];
        yield 'moyenne' => [4.0, false, Rules::MEDIUM];
        yield 'faible' => [3.9, false, Rules::LOW];
        yield 'score inconnu' => [null, false, Rules::MEDIUM];
    }

    #[DataProvider('levels')]
    public function testLevel(?float $cvss, bool $kev, string $expected): void
    {
        $this->assertSame($expected, Rules::level($cvss, $kev));
    }

    public function testCriticalAssetsGetAShorterDeadline(): void
    {
        $settings = self::settings();
        $this->assertSame(30, Rules::deadline(Rules::HIGH, false, $settings));
        $this->assertSame(15, Rules::deadline(Rules::HIGH, true, $settings));
        $this->assertSame(4, Rules::deadline(Rules::KEV, true, $settings), 'Arrondi au jour supérieur.');
        $settings['critical_percent'] = 100;
        $this->assertSame(30, Rules::deadline(Rules::HIGH, true, $settings), '100 % : pas de réduction.');
    }

    public function testStatesOnTimeDueSoonOverdue(): void
    {
        $settings = self::settings();
        $record = self::record('2026-10-01', Rules::HIGH);

        $state = Rules::state($record, null, '2026-10-10', $settings);
        $this->assertSame(Rules::STATUS_ON_TIME, $state['status']);
        $this->assertSame('2026-10-31', $state['due']);
        $this->assertSame(21, $state['days']);

        $this->assertSame(Rules::STATUS_DUE_SOON, Rules::state($record, null, '2026-10-25', $settings)['status']);
        $this->assertSame(
            Rules::STATUS_DUE_SOON,
            Rules::state($record, null, '2026-10-31', $settings)['status'],
            'Le jour de l\'échéance compte encore.'
        );

        $overdue = Rules::state($record, null, '2026-11-05', $settings);
        $this->assertSame(Rules::STATUS_OVERDUE, $overdue['status']);
        $this->assertSame(-5, $overdue['days']);
    }

    public function testFirstDetectionIsKeptAndLevelChangesNeverPostponeTheDueDate(): void
    {
        $settings = self::settings();
        $record = Rules::observe(null, Rules::HIGH, false, '2026-10-01', $settings);
        $this->assertSame('2026-10-01', $record['date_first_seen']);

        // Même niveau le lendemain : rien ne change sauf la dernière détection.
        $again = Rules::observe($record, Rules::HIGH, false, '2026-10-02', $settings);
        $this->assertSame('2026-10-01', $again['date_first_seen']);
        $this->assertSame('2026-10-02', $again['date_last_seen']);
        $this->assertSame('2026-10-31', Rules::dueDate($again, $settings));

        // Entrée au catalogue KEV le 20 : 7 jours à partir du 20.
        $kev = Rules::observe($again, Rules::KEV, false, '2026-10-20', $settings);
        $this->assertSame('2026-10-01', $kev['date_first_seen']);
        $this->assertSame('2026-10-27', Rules::dueDate($kev, $settings));

        // Entrée au catalogue le 28 : l'échéance du niveau élevé (31) n'est jamais reculée.
        $late = Rules::observe($again, Rules::KEV, false, '2026-10-28', $settings);
        $this->assertSame('2026-10-31', Rules::dueDate($late, $settings));

        // Score révisé à la baisse : l'échéance connue reste.
        $lower = Rules::observe($again, Rules::LOW, false, '2026-10-05', $settings);
        $this->assertSame('2026-10-31', Rules::dueDate($lower, $settings));
    }

    public function testReappearanceAfterAFix(): void
    {
        $settings = self::settings();
        $fixed = self::record('2026-06-01', Rules::HIGH, '2026-06-20');

        // Retour dans les 30 jours : disparition passagère, la correction est annulée.
        $back = Rules::observe($fixed, Rules::HIGH, false, '2026-07-10', $settings);
        $this->assertNull($back['date_fixed']);
        $this->assertSame('2026-06-01', $back['date_start']);
        $this->assertSame(Rules::STATUS_OVERDUE, Rules::state($back, null, '2026-07-10', $settings)['status']);

        // Retour bien plus tard : nouvelle exposition, la première détection reste.
        $again = Rules::observe($fixed, Rules::HIGH, false, '2026-09-01', $settings);
        $this->assertNull($again['date_fixed']);
        $this->assertSame('2026-06-01', $again['date_first_seen']);
        $this->assertSame('2026-09-01', $again['date_start']);
        $this->assertSame('2026-10-01', Rules::dueDate($again, $settings));
    }

    public function testWaiverInProgressThenExpired(): void
    {
        $settings = self::settings();
        $record = self::record('2026-08-01', Rules::CRITICAL);

        // Échéance du 16 août passée, mais dérogation validée jusqu'au 30 novembre.
        $waived = Rules::state($record, '2026-11-30', '2026-11-14', $settings);
        $this->assertSame(Rules::STATUS_WAIVED, $waived['status']);
        $this->assertSame('2026-11-30', $waived['due']);
        $this->assertSame('2026-08-16', $waived['sla_due']);

        // Dérogation échue : de nouveau en retard.
        $expired = Rules::state($record, '2026-11-30', '2026-12-01', $settings);
        $this->assertSame(Rules::STATUS_OVERDUE, $expired['status']);
        $this->assertSame('2026-08-16', $expired['due']);

        // Corrigée avant la fin de la dérogation : dans les délais.
        $fixedRecord = self::record('2026-08-01', Rules::CRITICAL, '2026-11-20');
        $fixed = Rules::state($fixedRecord, '2026-11-30', '2026-12-01', $settings);
        $this->assertSame(Rules::STATUS_FIXED, $fixed['status']);
        $this->assertTrue($fixed['on_time']);
        $this->assertSame(111, $fixed['days']);

        $late = Rules::state(self::record('2026-08-01', Rules::CRITICAL, '2026-11-20'), null, '2026-12-01', $settings);
        $this->assertFalse($late['on_time']);
    }

    public function testIndicatorsOnAFictitiousDataSet(): void
    {
        $settings = self::settings();
        $today = '2026-11-14';
        $records = [
            // Ouvertes : une KEV en retard de 10 jours, une élevée dans les délais, une critique
            // couverte par une dérogation en cours, une moyenne en retard de 4 jours.
            [self::record('2026-10-28', Rules::KEV), null],
            [self::record('2026-11-01', Rules::HIGH), null],
            [self::record('2026-09-01', Rules::CRITICAL), '2026-12-31'],
            [self::record('2026-08-12', Rules::MEDIUM), null],
            // Corrigées sur la période : deux dans les délais (5 et 20 jours), une en retard (40 jours).
            [self::record('2026-10-01', Rules::KEV, '2026-10-06'), null],
            [self::record('2026-09-10', Rules::HIGH, '2026-09-30'), null],
            [self::record('2026-08-01', Rules::HIGH, '2026-09-10'), null],
            // Corrigée sur la période précédente, en retard.
            [self::record('2026-05-01', Rules::HIGH, '2026-07-01'), null],
        ];
        $states = [];
        foreach ($records as [$record, $waiverEnd]) {
            $states[] = Rules::state($record, $waiverEnd, $today, $settings) + ['date_fixed' => $record['date_fixed']];
        }
        $indicators = Rules::indicators($states, $today, $settings);

        $this->assertSame(4, $indicators['open']);
        $this->assertSame(2, $indicators['overdue']);
        $this->assertSame(1, $indicators['waived']);
        $this->assertSame(3, $indicators['fixed']);
        $this->assertSame(2, $indicators['fixed_on_time']);
        $this->assertSame(67, $indicators['rate']);
        $this->assertSame(0, $indicators['previous_rate']);
        $this->assertSame(21.7, $indicators['mttr'], '(5 + 20 + 40) / 3, arrondi au dixième.');
        $this->assertSame(10, $indicators['oldest_overdue']);
        $this->assertSame(7, $indicators['average_overdue']);
        // (4 ouvertes - 2 en retard + 2 corrigées à temps) / (4 + 3).
        $this->assertSame(57, $indicators['compliance']);

        $this->assertSame(1, $indicators['by_level'][Rules::KEV]['overdue']);
        $this->assertSame(100, $indicators['by_level'][Rules::KEV]['rate']);
        $this->assertSame(50, $indicators['by_level'][Rules::HIGH]['rate']);
        $this->assertNull($indicators['by_level'][Rules::LOW]['rate'], 'Rien de corrigé : pas de taux.');
        $this->assertSame(30, $indicators['by_level'][Rules::HIGH]['days']);

        $this->assertSame(
            ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
            array_column($indicators['trend'], 'month')
        );
        $this->assertSame([0, 0, 0, 1, 1, 0], array_column($indicators['trend'], 'on_time'));
        $this->assertSame([0, 1, 0, 1, 0, 0], array_column($indicators['trend'], 'late'));
    }

    public function testIndicatorsWithoutData(): void
    {
        $indicators = Rules::indicators([], '2026-11-14', self::settings());
        $this->assertSame(0, $indicators['open']);
        $this->assertNull($indicators['rate']);
        $this->assertNull($indicators['compliance']);
        $this->assertNull($indicators['mttr']);
        $this->assertNull($indicators['oldest_overdue']);
    }

    public function testParseCveIds(): void
    {
        $this->assertSame(
            ['CVE-2099-10001', 'CVE-2099-200002'],
            Rules::parseCveIds("cve-2099-10001, CVE-2099-200002\nCVE-2099-10001 <script> CVE-99-1")
        );
        $this->assertSame([], Rules::parseCveIds(''));
    }
}
