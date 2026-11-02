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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\SecurityIncidentEvidence;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #371 : registre des preuves (empreintes SHA-256 des documents joints à un incident).
 */
final class SecurityIncidentEvidenceTest extends GrcmanagerIntegrationTestCase
{
    public function testAttachedDocumentIsSealedTamperingIsDetectedAndRemovalKept(): void
    {
        global $DB;

        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add(['name' => 'PHPUnit preuves', 'entities_id' => 0]);

        // Document fictif écrit directement dans le dossier des documents de GLPI.
        $relative = 'TXT/phpunit-' . bin2hex(random_bytes(6)) . '.txt';
        @mkdir(GLPI_DOC_DIR . '/TXT', 0775, true);
        file_put_contents(GLPI_DOC_DIR . '/' . $relative, 'journal du pare-feu');
        $document = new Document();
        $documentId = (int) $document->add([
            'name' => 'PHPUnit preuve', 'filename' => 'parefeu.txt', 'filepath' => $relative, 'entities_id' => 0,
        ]);
        // add() ne garde le chemin que d'un vrai téléversement : écrit tel que GLPI l'aurait rempli.
        $DB->update(Document::getTable(), ['filepath' => $relative], ['id' => $documentId]);
        $link = new Document_Item();
        $linkId = (int) $link->add([
            'documents_id' => $documentId,
            'itemtype'     => PluginGrcmanagerSecurityIncident::class,
            'items_id'     => $incidentId,
        ]);

        $rows = SecurityIncidentEvidence::getForIncident($incidentId);
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', 'journal du pare-feu'), $rows[0]['sha256']);
        $this->assertSame(['ok'], array_values(SecurityIncidentEvidence::verifyIncident($incidentId)));

        // Fichier modifié sur le disque : détecté.
        file_put_contents(GLPI_DOC_DIR . '/' . $relative, 'journal du pare-feu falsifié');
        $this->assertSame(['modified'], array_values(SecurityIncidentEvidence::verifyIncident($incidentId)));

        // Document retiré de l'incident : il reste au registre, marqué retiré.
        $link->delete(['id' => $linkId], true);
        $rows = SecurityIncidentEvidence::getForIncident($incidentId);
        $this->assertCount(1, $rows);
        $this->assertSame('removed', $rows[0]['status']);
        $this->assertNotNull($rows[0]['date_removed']);

        @unlink(GLPI_DOC_DIR . '/' . $relative);
    }
}
