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

use GlpiPlugin\Grcmanager\Services\Exercise\RecoveryTracker;
use GlpiPlugin\Grcmanager\Services\Impact\ImpactGraph;
use PluginGrcmanagerCrisisExercise as Cell;
use PluginGrcmanagerSecurityIncident as Incident;

/**
 * Issue #185 : cellule de crise sur un incident réel (reprise face au RTO, journal, rapport).
 */
final class CrisisCellTest extends GrcmanagerIntegrationTestCase
{
    public function testCrisisCellTracksRecoveryAgainstRtoAndArchivesReport(): void
    {
        global $DB;

        $server = (int) (new \Computer())->add(['name' => 'PHPUnit serveur de fichiers', 'entities_id' => 0]);
        $app = (int) (new \Computer())->add(['name' => 'PHPUnit application paie', 'entities_id' => 0]);
        $other = (int) (new \Computer())->add(['name' => 'PHPUnit poste isolé', 'entities_id' => 0]);
        // L'application dépend du serveur (analyse d'impact native de GLPI).
        $DB->insert('glpi_impactrelations', [
            'itemtype_source'   => \Computer::class,
            'items_id_source'   => $server,
            'itemtype_impacted' => \Computer::class,
            'items_id_impacted' => $app,
        ]);
        $DB->insert(\PluginGrcmanagerAssetClassification::getTable(), [
            'itemtype'  => \Computer::class,
            'items_id'  => $server,
            'rto_hours' => 4,
        ]);
        $incidentId = (int) (new Incident())->add([
            'name'        => 'PHPUnit chiffrement du serveur de fichiers',
            'entities_id' => 0,
            'content'     => 'Fichiers chiffrés.',
        ]);
        (new \PluginGrcmanagerSecurityIncident_Item())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype'                               => \Computer::class,
            'items_id'                               => $server,
        ]);
        $incident = new Incident();
        $incident->getFromDB($incidentId);

        $this->assertSame(0, Cell::activate($incident, 'inconnu'));
        $cellId = Cell::activate($incident, 'ransomware');
        $this->assertGreaterThan(0, $cellId);
        $this->assertSame(0, Cell::activate($incident, 'outage'), 'Une seule cellule par incident.');
        $cell = Cell::forIncident($incidentId);
        $this->assertNotNull($cell);
        $this->assertFalse($cell->isDrill());

        $data = $cell->collect();
        $this->assertFalse($data['drill']);
        $keys = array_column($data['recovery'], 'key');
        $serverKey = ImpactGraph::key(\Computer::class, $server);
        $this->assertSame([$serverKey, ImpactGraph::key(\Computer::class, $app)], $keys, 'Touché puis dépendant.');
        $this->assertSame(RecoveryTracker::RUNNING, $data['recovery'][0]['state']);
        $this->assertSame(RecoveryTracker::NO_RTO, $data['recovery'][1]['state']);

        $this->assertFalse($cell->markRestored(ImpactGraph::key(\Computer::class, $other)), 'Hors périmètre.');
        $this->assertTrue($cell->markRestored($serverKey));
        $this->assertFalse($cell->markRestored($serverKey), 'Déjà rétabli.');
        $this->assertTrue($cell->addJournalEntry('Décision : bascule sur le site de secours.'));
        $this->assertFalse($cell->addJournalEntry('   '));
        (new \ITILFollowup())->add([
            'itemtype'   => Incident::class,
            'items_id'   => $incidentId,
            'is_private' => 1,
            'content'    => 'Note privée de la cellule.',
        ]);

        $data = $cell->collect();
        $this->assertSame(RecoveryTracker::RESTORED_ON_TIME, $data['recovery'][0]['state']);
        $texts = array_column($data['journal'], 'text');
        $this->assertContains('Décision : bascule sur le site de secours.', $texts);

        $documentsId = $cell->finish();
        try {
            $this->assertGreaterThan(0, $documentsId);
            $controls = array_column(iterator_to_array($DB->request([
                'SELECT'     => ['glpi_plugin_grcmanager_controls.code'],
                'FROM'       => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_plugin_grcmanager_controls' => [
                        'FKEY' => ['glpi_documents_items' => 'items_id', 'glpi_plugin_grcmanager_controls' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'glpi_documents_items.documents_id' => $documentsId,
                    'glpi_documents_items.itemtype'     => \PluginGrcmanagerControl::class,
                ],
            ])), 'code');
            // Revue de sécurité 2.11.0 : le rapport d'une crise réelle n'est lié à aucune mesure
            // (globale, lisible de toutes les entités), et il n'est pas récursif.
            $this->assertSame([], $controls);
            $document = new \Document();
            $this->assertTrue($document->getFromDB($documentsId));
            $this->assertSame(0, (int) $document->fields['is_recursive']);
            $this->assertNotNull(\PluginGrcmanagerRestrictedDocument::sourceOf($documentsId));
            $this->assertNotContains(
                $cellId,
                array_column(Cell::history(), 'id'),
                'Les crises réelles ne figurent pas dans l\'historique des exercices.'
            );
            $this->assertFalse($cell->addJournalEntry('Après la fermeture.'));
        } finally {
            (new \Document())->delete(['id' => $documentsId], true);
        }
    }

    public function testExerciseIncidentCannotGetASecondCell(): void
    {
        $exerciseId = Cell::launch('outage');
        $exercise = new Cell();
        $exercise->getFromDB($exerciseId);
        $incident = $exercise->getIncident();
        $this->assertNotNull($incident);
        $this->assertTrue($exercise->isDrill());
        $this->assertSame(0, Cell::activate($incident, 'ransomware'));
    }
}
