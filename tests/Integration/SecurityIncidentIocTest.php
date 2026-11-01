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

use GlpiPlugin\Grcmanager\SecurityIncidentIoc;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #369 : IOC ajoutés en masse, dédoublonnés, et retrouvés dans les autres incidents.
 */
final class SecurityIncidentIocTest extends GrcmanagerIntegrationTestCase
{
    public function testBulkAddDeduplicatesAndFlagsOtherIncidents(): void
    {
        $first = $this->createIncident('PHPUnit IOC 1');
        $second = $this->createIncident('PHPUnit IOC 2');

        $raw = "198.51.100.9\nhxxp://phish[.]example/login\nnot an ioc";
        $result = SecurityIncidentIoc::addFromText($first, $raw, 'malicious');
        $this->assertSame(2, $result['added']);
        $this->assertSame(['not an ioc'], $result['rejected']);

        // Même IP, désamorcée autrement : pas de doublon, dernière observation mise à jour.
        $again = SecurityIncidentIoc::addFromText($first, '198[.]51[.]100[.]9');
        $this->assertSame(['added' => 0, 'updated' => 1, 'rejected' => []], $again);
        $this->assertSame(2, countElementsInTable(SecurityIncidentIoc::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $first,
        ]));

        $row = new SecurityIncidentIoc();
        $this->assertTrue($row->getFromDBByCrit([
            'plugin_grcmanager_securityincidents_id' => $first,
            'type' => 'url',
        ]));
        $this->assertSame('http://phish.example/login', $row->fields['value']);
        $this->assertSame('malicious', $row->fields['verdict']);

        SecurityIncidentIoc::addFromText($second, '198.51.100.9');
        $hash = SecurityIncidentIoc::hashOf('ipv4', '198.51.100.9');
        $seen = SecurityIncidentIoc::findInOtherIncidents($second, [$hash]);
        $this->assertSame([$first], array_column(reset($seen) ?: [], 'id'));
    }

    public function testUnknownVerdictIsRejected(): void
    {
        $incident = $this->createIncident('PHPUnit IOC verdict');
        SecurityIncidentIoc::addFromText($incident, 'bad.example');
        $row = new SecurityIncidentIoc();
        $this->assertTrue($row->getFromDBByCrit(['plugin_grcmanager_securityincidents_id' => $incident]));

        $row->update(['id' => $row->getID(), 'verdict' => 'nonsense']);
        $row->getFromDB($row->getID());
        $this->assertSame('', $row->fields['verdict']);

        $row->update(['id' => $row->getID(), 'verdict' => 'benign', 'value' => 'other.example']);
        $row->getFromDB($row->getID());
        $this->assertSame('benign', $row->fields['verdict']);
        $this->assertSame('bad.example', $row->fields['value']);
    }

    private function createIncident(string $name): int
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return (int) $incident->add(['name' => $name, 'entities_id' => 0]);
    }
}
