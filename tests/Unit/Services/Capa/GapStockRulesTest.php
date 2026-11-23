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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Capa;

use GlpiPlugin\Grcmanager\Services\Capa\GapStockRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #870 : calculs du rapport du stock d'écarts.
 */
final class GapStockRulesTest extends TestCase
{
    private const TODAY = '2026-06-15';

    /**
     * @return array<string, mixed>
     */
    private static function row(string $created, ?string $closed, ?string $due = null, array $extra = []): array
    {
        return $extra + [
            'kind' => R::KIND_NONCONFORMITY, 'severity' => 'major', 'origin' => R::ORIGIN_INTERNAL_AUDIT,
            'entities_id' => 0, 'users_id' => 2, 'created' => $created, 'due' => $due, 'closed' => $closed,
            'verified' => false,
        ];
    }

    public function testOrigin(): void
    {
        $this->assertSame(R::ORIGIN_INTERNAL_AUDIT, R::origin(3, 'internal', 0));
        $this->assertSame(R::ORIGIN_INTERNAL_AUDIT, R::origin(3, '', 5));
        $this->assertSame(R::ORIGIN_CERTIFICATION_AUDIT, R::origin(3, 'surveillance_1', 0));
        $this->assertSame(R::ORIGIN_INCIDENT, R::origin(0, '', 5));
        $this->assertSame(R::ORIGIN_OTHER, R::origin(0, '', 0));
        $this->assertSame(R::KIND_FINDING, R::kindOf('observation'));
        $this->assertSame(R::KIND_NONCONFORMITY, R::kindOf('nonconformity'));
    }

    public function testDaysAndOverdue(): void
    {
        $this->assertSame(31, R::days('2026-03-01', '2026-04-01'));
        $this->assertSame(0, R::days('2026-04-01', '2026-03-01'));
        $this->assertSame(5, R::overdueDays(self::row('2026-01-01', null, '2026-06-10'), self::TODAY));
        $this->assertSame(0, R::overdueDays(self::row('2026-01-01', null, '2026-06-20'), self::TODAY));
        $this->assertSame(0, R::overdueDays(self::row('2026-01-01', '2026-06-01', '2026-02-01'), self::TODAY));
        $this->assertSame(0, R::overdueDays(self::row('2026-01-01', null), self::TODAY));
        $this->assertSame('0-30', R::ageBucket(30));
        $this->assertSame('31-90', R::ageBucket(31));
        $this->assertSame('180+', R::ageBucket(400));
    }

    public function testPeriod(): void
    {
        $this->assertSame(['from' => '2025-07', 'to' => '2026-06'], R::period(null, null, self::TODAY));
        $this->assertSame(['from' => '2026-01', 'to' => '2026-03'], R::period('2026-01', '2026-03', self::TODAY));
        // Fin dans le futur ramenée au mois en cours, début après la fin ramené à la fin.
        $this->assertSame(['from' => '2026-06', 'to' => '2026-06'], R::period('2026-09', '2027-01', self::TODAY));
        // Valeurs invalides ignorées, période limitée à MAX_MONTHS.
        $this->assertSame(['from' => '2025-07', 'to' => '2026-06'], R::period('<b>', ['x'], self::TODAY));
        $this->assertSame('2023-07', R::period('2010-01', null, self::TODAY)['from']);
        $this->assertSame(['2025-11', '2025-12', '2026-01'], R::months('2025-11', '2026-01'));
        $this->assertSame('2025-12', R::shift('2026-01', -1));
    }

    public function testSummarize(): void
    {
        $rows = [
            self::row('2026-01-10', null, '2026-03-01'),                                   // ouvert, en retard
            self::row('2026-05-20', null, null, ['severity' => 'minor', 'users_id' => 0]), // ouvert, sans échéance
            self::row('2026-02-01', '2026-03-03', null, ['verified' => true]),             // clos en 30 jours
            self::row('2026-03-01', '2026-04-10', null, ['origin' => R::ORIGIN_INCIDENT]), // clos en 40 jours
            self::row('2025-01-01', '2025-02-01'),                                         // clos avant la période
            self::row('2026-04-01', '2026-05-01', null, ['kind' => R::KIND_REVIEW_ACTION]), // action de revue
        ];
        $s = R::summarize($rows, '2026-01', '2026-06', self::TODAY);

        $this->assertSame(2, $s['open']['total']);
        $this->assertSame(1, $s['open']['overdue']);
        $this->assertSame(1, $s['open']['no_due']);
        $this->assertSame(['open' => 1, 'overdue' => 1], $s['open']['by_severity']['major']);
        $this->assertSame(['open' => 1, 'overdue' => 0], $s['open']['by_owner']['0']);
        $this->assertSame(1, $s['open']['by_age']['0-30']);
        $this->assertSame(1, $s['open']['by_age']['91-180']);
        $this->assertSame(5, $s['opened_in_period']);
        $this->assertSame(3, $s['closed_in_period']);
        $this->assertSame(33, $s['mean_closure']);
        $this->assertSame(30, $s['median_closure']);
        // L'action de revue n'entre pas dans l'efficacité vérifiée.
        $this->assertSame(1, $s['verified']);
        $this->assertSame(1, $s['to_verify']);
        $this->assertSame(50, $s['verified_rate']);

        $trend = array_column($s['trend'], null, 'month');
        $this->assertSame(['month' => '2026-01', 'opened' => 1, 'closed' => 0, 'stock' => 1], $trend['2026-01']);
        $this->assertSame(['month' => '2026-03', 'opened' => 1, 'closed' => 1, 'stock' => 2], $trend['2026-03']);
        $this->assertSame(2, $trend['2026-06']['stock']);
    }

    public function testEmptySummary(): void
    {
        $s = R::summarize([], '2026-01', '2026-02', self::TODAY);
        $this->assertSame(0, $s['open']['total']);
        $this->assertNull($s['mean_closure']);
        $this->assertNull($s['verified_rate']);
        $this->assertCount(2, $s['trend']);
    }

    public function testClosureStamp(): void
    {
        $closed = ['closed', 'verified'];
        $now = '2026-06-15 10:00:00';
        $set = ['set' => true, 'value' => $now];
        $this->assertSame($set, R::closureStamp('open', 'closed', null, $closed, $now));
        $this->assertSame($set, R::closureStamp('', 'verified', null, $closed, $now));
        // Clos puis vérifié : la date de clôture est gardée.
        $this->assertSame(
            ['set' => false, 'value' => null],
            R::closureStamp('closed', 'verified', '2026-06-01 09:00:00', $closed, $now)
        );
        $none = ['set' => false, 'value' => null];
        $reopened = R::closureStamp('closed', 'open', '2026-06-01', $closed, $now);
        $this->assertSame(['set' => true, 'value' => null], $reopened);
        $this->assertSame($none, R::closureStamp('open', 'in_progress', null, $closed, $now));
    }

    public function testCsvCell(): void
    {
        $this->assertSame("'=HYPERLINK(1)", R::csvCell('=HYPERLINK(1)'));
        $this->assertSame("'-1", R::csvCell('-1'));
        $this->assertSame('Texte libre', R::csvCell('Texte libre'));
        $this->assertSame('', R::csvCell(''));
    }
}
