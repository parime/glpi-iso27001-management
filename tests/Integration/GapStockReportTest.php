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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\GapStockReport;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Services\Capa\GapStockRules;

/**
 * Issue #870 : rapport du stock d'écarts, date de clôture des non-conformités, CSV, PDF et preuve.
 */
final class GapStockReportTest extends GrcmanagerIntegrationTestCase
{
    public function testBacklogClosureDateCsvAndEvidence(): void
    {
        global $DB;

        $this->assertTrue($DB->fieldExists(Nonconformity::getTable(), 'date_closed'));
        $nc = new Nonconformity();
        $openId = (int) $nc->add([
            'title'        => 'PHPUnit gap backlog open',
            'finding_type' => 'nonconformity',
            'severity'     => 'critical',
            'status'       => 'open',
            'due_date'     => date('Y-m-d', strtotime('-10 days')),
            'entities_id'  => 0,
        ]);
        $closedId = (int) $nc->add([
            'title'             => 'PHPUnit gap backlog closed',
            'finding_type'      => 'nonconformity',
            'severity'          => 'minor',
            'status'            => 'open',
            'corrective_action' => 'Fix applied',
            'entities_id'       => 0,
        ]);
        $this->assertGreaterThan(0, $openId);
        $this->assertGreaterThan(0, $closedId);

        try {
            // La clôture pose la date, la réouverture l'efface ; le statut n'est jamais changé.
            $this->assertTrue((new Nonconformity())->update(['id' => $closedId, 'status' => 'closed']));
            $closed = new Nonconformity();
            $closed->getFromDB($closedId);
            $this->assertNotEmpty($closed->fields['date_closed']);
            $this->assertSame('closed', $closed->fields['status']);
            $this->assertTrue((new Nonconformity())->update(['id' => $closedId, 'status' => 'in_progress']));
            $closed->getFromDB($closedId);
            $this->assertEmpty($closed->fields['date_closed']);
            $this->assertTrue((new Nonconformity())->update(['id' => $closedId, 'status' => 'closed']));

            $filters = GapStockReport::filters(['severity' => 'critical', 'from' => 'x']);
            $this->assertSame('critical', $filters['severity']);
            $report = GapStockReport::report($filters);
            $titles = array_column($report['open_rows'], 'title');
            $this->assertContains('PHPUnit gap backlog open', $titles);
            $this->assertNotContains('PHPUnit gap backlog closed', $titles);
            $row = $report['open_rows'][array_search('PHPUnit gap backlog open', $titles, true)];
            $this->assertSame(10, $row['overdue_days']);
            $this->assertGreaterThanOrEqual(1, $report['summary']['open']['overdue']);

            $all = GapStockReport::report(GapStockReport::filters([]));
            $this->assertGreaterThanOrEqual(1, $all['summary']['closed_in_period']);
            $this->assertNotNull($all['summary']['mean_closure']);

            $csv = GapStockReport::csvRows(GapStockReport::filters([]));
            $this->assertContains('PHPUnit gap backlog closed', array_merge(...$csv));

            $html = GapStockReport::renderPdf(GapStockReport::filters([]));
            $this->assertStringContainsString('PHPUnit gap backlog open', $html);

            $this->assertNull(GapStockReport::evidence('A.8.5'));
            $evidence = GapStockReport::evidence('10.2');
            $this->assertNotNull($evidence);
            $this->assertGreaterThanOrEqual(1, $evidence['overdue']);
            $titles = array_column(FeatureRegistry::controlEvidence('10.1'), 'title');
            $this->assertContains(GapStockReport::getTypeName(), $titles);

            ob_start();
            GapStockReport::showPage(GapStockReport::filters([]));
            $page = (string) ob_get_clean();
            $this->assertStringContainsString('grc-gap-open-list', $page);
            $this->assertStringContainsString('PHPUnit gap backlog open', $page);

            $card = GapStockReport::dashboardCard();
            $this->assertIsInt($card['number']);
            $this->assertSame(GapStockRules::ORIGIN_OTHER, $row['origin']);
        } finally {
            $DB->delete(Nonconformity::getTable(), ['id' => [$openId, $closedId]]);
        }
    }
}
