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

use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Incident\MitreAttack;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #369 : techniques MITRE ATT&CK des incidents et cartes du tableau de bord.
 */
final class SecurityIncidentTechniqueTest extends GrcmanagerIntegrationTestCase
{
    public function testAddFromListFreeInputAndStatistics(): void
    {
        $first = $this->createIncident('PHPUnit ATT&CK 1');
        $second = $this->createIncident('PHPUnit ATT&CK 2');

        // Depuis la liste : la tactique choisie seulement.
        $this->assertSame(1, SecurityIncidentTechnique::addFromInput($first, 'T1566.001|TA0001', 'pièce jointe'));
        // Saisie libre : chacune des tactiques de la technique, une seule fois.
        $validAccounts = MitreAttack::technique('T1078');
        $this->assertNotNull($validAccounts);
        $expected = count($validAccounts['tactics']);
        $this->assertSame($expected, SecurityIncidentTechnique::addFromInput($first, 'valid accounts'));
        $this->assertSame(0, SecurityIncidentTechnique::addFromInput($first, 'T1078'));
        // Refus : technique inconnue, tactique qui n'est pas la sienne.
        $this->assertSame(0, SecurityIncidentTechnique::addFromInput($first, 'T9999'));
        $this->assertSame(0, SecurityIncidentTechnique::addFromInput($first, 'T1566.001|TA0040'));

        SecurityIncidentTechnique::addFromInput($second, 'T1566.001|TA0001');

        $stats = SecurityIncidentTechnique::statistics([$first, $second]);
        $this->assertSame(2, $stats['tactics']['TA0001']);
        $this->assertSame(2, $stats['techniques']['T1566.001']);
        $this->assertSame(1, $stats['techniques']['T1078']);
        $this->assertSame('T1566.001', array_key_first($stats['techniques']));

        $card = SecuredDashboardCards::attackTopTechniques();
        $labels = array_column($card['data'], 'label');
        $this->assertContains('T1566.001 Phishing: Spearphishing Attachment', $labels);
        $tactics = SecuredDashboardCards::attackTactics();
        $this->assertNotSame([], $tactics['data']);
    }

    public function testOnlyTheCommentCanChange(): void
    {
        $incident = $this->createIncident('PHPUnit ATT&CK commentaire');
        SecurityIncidentTechnique::addFromInput($incident, 'T1486|TA0040');
        $row = new SecurityIncidentTechnique();
        $this->assertTrue($row->getFromDBByCrit(['plugin_grcmanager_securityincidents_id' => $incident]));

        $row->update(['id' => $row->getID(), 'comment' => 'chiffrement des partages', 'technique' => 'T1566']);
        $row->getFromDB($row->getID());
        $this->assertSame('chiffrement des partages', $row->fields['comment']);
        $this->assertSame('T1486', $row->fields['technique']);
    }

    private function createIncident(string $name): int
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return (int) $incident->add(['name' => $name, 'entities_id' => 0]);
    }
}
