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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

/**
 * Issue #144, phase 2 : rapport PDF de la matrice des flux.
 */
final class NetworkFlowReportTest extends GrcmanagerIntegrationTestCase
{
    public function testReportListsActiveFlowsWithTheirGapsAndRendersAsPdf(): void
    {
        $flow = new \GlpiPlugin\Grcmanager\NetworkFlow();
        $id = (int) $flow->add([
            'name'              => 'PHPUnit RDP publié',
            'flow_type'         => 'internet_publication',
            'source_label'      => 'Internet',
            'destination_label' => 'Serveur de bureau à distance',
            'protocol'          => 'tcp',
            'ports'             => '3389',
            'is_active'         => 1,
        ]);
        try {
            $this->assertGreaterThan(0, $id);
            $data = \GlpiPlugin\Grcmanager\NetworkFlow::reportData();
            $flows = [];
            foreach ($data['groups'] as $group) {
                foreach ($group['flows'] as $row) {
                    $flows[$row['name']] = $row;
                }
            }
            $this->assertArrayHasKey('PHPUnit RDP publié', $flows);
            $row = $flows['PHPUnit RDP publié'];
            $this->assertSame('TCP 3389', $row['service']);
            $this->assertContains(__('Administration exposed on the Internet', 'grcmanager'), $row['checks']);
            $this->assertContains(__('No justification', 'grcmanager'), $row['checks']);

            $html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/network_flow_report.html.twig', [
                'report_title'       => 'Matrice',
                'generated_at_label' => '',
            ] + $data);
            $this->assertStringContainsString('PHPUnit RDP publié', $html);
            $this->assertStringStartsWith('%PDF', PdfRenderer::renderHtmlToPdf($html));
        } finally {
            $flow->delete(['id' => $id], true);
        }
    }
}
