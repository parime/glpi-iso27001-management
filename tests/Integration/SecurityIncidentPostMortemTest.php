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

use GlpiPlugin\Grcmanager\Services\Incident\PostMortem;
use PluginGrcmanagerSecurityIncident as Incident;
use PluginGrcmanagerSecurityIncidentPostMortem as Report;

/**
 * Issue #169 : rapport post-incident d'un incident fictif.
 */
final class SecurityIncidentPostMortemTest extends GrcmanagerIntegrationTestCase
{
    public function testReportCompilesTimelineDeadlinesAndActionsThenIsArchived(): void
    {
        global $DB;

        $incidentId = (int) (new Incident())->add([
            'name'                 => 'PHPUnit hameçonnage du service paie',
            'entities_id'          => 0,
            'content'              => 'Un collaborateur a saisi ses identifiants sur une fausse page.',
            'date'                 => '2099-01-01 08:00:00',
            'personal_data_breach' => 1,
            'gdpr_notification_at' => '2099-01-02 09:00:00',
            'root_cause'           => 'Absence d\'authentification multifacteur sur la messagerie.',
            'lessons_learned'      => 'Généraliser la MFA et former au signalement.',
        ]);
        (new \ITILFollowup())->add([
            'itemtype' => Incident::class,
            'items_id' => $incidentId,
            'content'  => '<p>Compte du collaborateur bloqué.</p>',
            'date'     => '2099-01-01 09:00:00',
        ]);
        $DB->insert('glpi_plugin_grcmanager_securityincidentcosts', [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'name'          => 'Analyse',
            'actiontime'    => 3600,
            'cost_time'     => 80,
            'cost_fixed'    => 20,
            'cost_material' => 0,
        ]);
        $ncId = (int) (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title'                                  => 'PHPUnit déployer la MFA',
            'plugin_grcmanager_securityincidents_id' => $incidentId,
        ]);

        $incident = new Incident();
        $incident->getFromDB($incidentId);
        $report = Report::collect($incident);
        $this->assertFalse($report['incident']['closed']);
        $this->assertGreaterThanOrEqual(2, count($report['timeline']));
        $this->assertSame('2099-01-01 08:00:00', $report['timeline'][0]['date'], 'Déclaration en premier.');
        $this->assertContains('Compte du collaborateur bloqué.', array_column($report['timeline'], 'text'));
        $this->assertSame(100.0, $report['cost']);
        $this->assertCount(1, $report['deadlines'], 'Seule l\'échéance RGPD s\'applique.');
        $this->assertSame(PostMortem::DEADLINE_MET, $report['deadlines'][0]['outcome']);
        $this->assertSame(['PHPUnit déployer la MFA'], array_column($report['actions'], 'title'));

        ob_start();
        Report::displayTabContentForItem($incident);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="generate"', $html);

        $documentsId = Report::archive($incident);
        try {
            $this->assertGreaterThan(0, $documentsId);
            $this->assertSame(1, countElementsInTable('glpi_documents_items', [
                'documents_id' => $documentsId,
                'itemtype'     => Incident::class,
                'items_id'     => $incidentId,
            ]));
            // Revue de sécurité 2.11.0 : pas de lien vers la mesure A.5.27 (globale), pas de
            // récursivité, lecture réservée à qui lit l'incident.
            $this->assertSame(0, countElementsInTable('glpi_documents_items', [
                'documents_id' => $documentsId,
                'itemtype'     => \PluginGrcmanagerControl::class,
            ]));
            $document = new \Document();
            $document->getFromDB($documentsId);
            $this->assertSame('application/pdf', $document->fields['mime']);
            $this->assertSame(0, (int) $document->fields['is_recursive']);
            $this->assertSame(
                ['itemtype' => Incident::class, 'items_id' => $incidentId],
                \PluginGrcmanagerRestrictedDocument::sourceOf($documentsId)
            );
        } finally {
            (new \Document())->delete(['id' => $documentsId], true);
            (new \GlpiPlugin\Grcmanager\Nonconformity())->delete(['id' => $ncId], true);
        }
    }
}
