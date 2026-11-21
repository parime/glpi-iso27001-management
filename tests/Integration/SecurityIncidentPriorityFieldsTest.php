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

use CommonITILObject;
use PluginGrcmanagerSecurityIncident;
use Profile;

/**
 * Issue #951 : statut, urgence, impact et priorité d'un incident de sécurité réglables par un
 * super-admin, dans le formulaire comme dans les champs prédéfinis d'un gabarit. Le cœur lit ces
 * réglages au travers de constantes de classe (masques, matrice de priorité, cycle de vie du
 * profil) : vides, les listes Urgence et Impact n'avaient aucune valeur et le statut restait
 * bloqué sur « Nouveau ».
 */
final class SecurityIncidentPriorityFieldsTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Comme une vraie session : le cycle de vie des tickets du profil Super-Admin (id 4),
        // décodé par GLPI à la connexion.
        $profile = new Profile();
        $this->assertTrue($profile->getFromDB(4));
        $_SESSION['glpiactiveprofile']['ticket_status'] = importArrayFromDB($profile->fields['ticket_status'] ?? '');
    }

    public function testUrgencyAndImpactListsHaveValues(): void
    {
        foreach (['dropdownUrgency' => 'urgency', 'dropdownImpact' => 'impact'] as $method => $name) {
            $html = PluginGrcmanagerSecurityIncident::$method(['name' => $name, 'display' => false]);
            preg_match_all("/<option value='(-?\\d+)'/", $html, $matches);
            $this->assertContains('3', $matches[1], $name . ': medium value missing');
            $this->assertGreaterThanOrEqual(3, count($matches[1]), $name . ': list is (almost) empty');
        }
    }

    public function testSuperAdminCanMoveIncidentOutOfNewStatus(): void
    {
        $allowed = PluginGrcmanagerSecurityIncident::getAllowedStatusArray(CommonITILObject::INCOMING);
        foreach ([CommonITILObject::ASSIGNED, CommonITILObject::WAITING, CommonITILObject::SOLVED, CommonITILObject::CLOSED] as $status) {
            $this->assertArrayHasKey($status, $allowed, 'status ' . $status . ' not allowed from New');
        }
    }

    public function testPriorityFollowsTheMatrix(): void
    {
        global $CFG_GLPI;

        $expected = (int) ($CFG_GLPI['priority_matrix'][5][5] ?? 5);
        $this->assertSame($expected, (int) PluginGrcmanagerSecurityIncident::computePriority(5, 5));
        $this->assertSame(
            (int) \Ticket::computePriority(1, 5),
            (int) PluginGrcmanagerSecurityIncident::computePriority(1, 5)
        );
    }

    public function testIncidentStatusAndPriorityCanBeUpdated(): void
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add([
            'name'        => 'PHPUnit incident 951',
            'content'     => 'PHPUnit incident 951',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $id);

        // Comme le formulaire : la priorité envoyée est celle calculée par la matrice.
        $priority = (int) PluginGrcmanagerSecurityIncident::computePriority(5, 4);
        $this->assertTrue($incident->update([
            'id'       => $id,
            'status'   => CommonITILObject::WAITING,
            'urgency'  => 5,
            'impact'   => 4,
            'priority' => $priority,
        ]));

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($reloaded->getFromDB($id));
        $this->assertSame(CommonITILObject::WAITING, (int) $reloaded->fields['status']);
        $this->assertSame(5, (int) $reloaded->fields['urgency']);
        $this->assertSame(4, (int) $reloaded->fields['impact']);
        $this->assertSame($priority, (int) $reloaded->fields['priority']);
    }
}
