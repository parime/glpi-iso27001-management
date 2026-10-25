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

/**
 * Issue #140 : constats d'un audit, documents, rapport archivé à la clôture.
 */
final class AuditReportArchiveTest extends GrcmanagerIntegrationTestCase
{
    public function testCompletingTheAuditArchivesItsReportOnce(): void
    {
        global $DB;

        $audit = new \PluginGrcmanagerAudit();
        $auditId = (int) $audit->add([
            'title'        => 'PHPUnit audit des sauvegardes',
            'scope'        => 'Sauvegardes du siège',
            'planned_date' => '2026-10-01',
            'status'       => 'in_progress',
        ]);
        $finding = new \PluginGrcmanagerNonconformity();
        $findingId = (int) $finding->add([
            'title'                       => 'PHPUnit test de restauration absent',
            'plugin_grcmanager_audits_id' => $auditId,
            'finding_type'                => 'observation',
            'severity'                    => 'minor',
        ]);
        $documentIds = [];
        try {
            $this->assertGreaterThan(0, $findingId);
            $audit->getFromDB($auditId);
            $report = \PluginGrcmanagerAudit::renderReport($audit);
            $this->assertStringContainsString('PHPUnit test de restauration absent', $report);
            $tabs = (new \PluginGrcmanagerNonconformity())->getTabNameForItem($audit);
            $this->assertNotSame('', $tabs);

            $this->assertTrue($audit->update(['id' => $auditId, 'status' => 'completed', 'conclusion' => 'Conforme.']));
            $documentIds = $this->documentsOf($auditId);
            $this->assertCount(1, $documentIds, 'Rapport archivé à la clôture.');

            // Une autre modification de l'audit terminé n'archive pas de second rapport.
            $audit->update(['id' => $auditId, 'conclusion' => 'Conforme, avec une observation.']);
            $this->assertCount(1, $this->documentsOf($auditId));
        } finally {
            foreach ($this->documentsOf($auditId) as $documentId) {
                (new \Document())->delete(['id' => $documentId], true);
            }
            $finding->delete(['id' => $findingId], true);
            $audit->delete(['id' => $auditId], true);
            $DB->delete(
                \Document_Item::getTable(),
                ['itemtype' => \PluginGrcmanagerAudit::class, 'items_id' => $auditId]
            );
        }
    }

    /**
     * @return list<int>
     */
    private function documentsOf(int $auditId): array
    {
        global $DB;

        return array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => ['documents_id'],
            'FROM'   => \Document_Item::getTable(),
            'WHERE'  => ['itemtype' => \PluginGrcmanagerAudit::class, 'items_id' => $auditId],
        ]), false), 'documents_id'));
    }
}
