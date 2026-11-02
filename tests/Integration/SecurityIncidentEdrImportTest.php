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

use Computer;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\SecurityIncidentIoc;
use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;

/**
 * Issue #370 : import d'un export CSV d'EDR / XDR (données fictives).
 */
final class SecurityIncidentEdrImportTest extends GrcmanagerIntegrationTestCase
{
    public function testImportCreatesIncidentsOnceWithIocsTechniquesAndItems(): void
    {
        $computerId = (int) (new Computer())->add(['name' => 'PC-EDR-PHPUNIT', 'entities_id' => 0]);
        $suffix = bin2hex(random_bytes(4));
        $csv = "Alert ID,Detection Time,Hostname,User Name,Severity,Technique,SHA256,Alert Name\n"
            . "EDR-$suffix-1,2031-02-01T08:00:00Z,pc-edr-phpunit.corp.example,CORP\\glpi,Critical,T1486,"
            . str_repeat('a', 64) . ",Chiffrement massif de fichiers\n"
            . "EDR-$suffix-2,2031-02-01T09:00:00Z,SRV-INCONNU,,Low,,,Scan de ports\n";
        $parsed = EdrCsvImport::parse($csv);
        $mapping = EdrCsvImport::guessMapping($parsed['headers']);

        $hash = hash('sha256', $csv);
        $result = SecurityIncidentEdrImport::import($parsed, $mapping, 'detection', 'UTC', 0, 'export.csv', $hash);
        $this->assertCount(2, $result['created']);

        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->getFromDB($result['created'][0]);
        $this->assertSame('Chiffrement massif de fichiers', $incident->fields['name']);
        $this->assertSame('critical', $incident->fields['severity']);
        $this->assertSame('malware', $incident->fields['category']);
        $this->assertSame('edr', $incident->fields['source']);
        $this->assertSame("EDR-$suffix-1", $incident->fields['external_id']);
        $this->assertSame(1, countElementsInTable(SecurityIncidentIoc::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(), 'type' => 'sha256',
        ]));
        $this->assertSame(1, countElementsInTable(SecurityIncidentTechnique::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(), 'technique' => 'T1486',
        ]));
        // Ordinateur reconnu par son nom court, compte par DOMAINE\login.
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(),
            'itemtype' => Computer::class, 'items_id' => $computerId,
        ]));
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(), 'itemtype' => 'User',
        ]));
        // Lignes brutes conservées.
        $this->assertSame(1, countElementsInTable(SecurityIncidentEdrImport::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(),
        ]));

        // Ré-import du même fichier : rien de nouveau.
        $again = SecurityIncidentEdrImport::import($parsed, $mapping, 'detection', 'UTC', 0, 'export.csv', '');
        $this->assertSame([], $again['created']);
        $this->assertSame(2, $again['skipped']);
    }

    public function testLoginOf(): void
    {
        $this->assertSame('jdoe', SecurityIncidentEdrImport::loginOf('CORP\\jdoe'));
        $this->assertSame('jdoe', SecurityIncidentEdrImport::loginOf('jdoe@corp.example'));
        $this->assertSame('jdoe', SecurityIncidentEdrImport::loginOf(' jdoe '));
    }
}
