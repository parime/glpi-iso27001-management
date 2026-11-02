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

use GlpiPlugin\Grcmanager\SecurityIncidentTeam;
use PluginGrcmanagerSecurityIncident;
use User;

/**
 * Issue #371 : équipe d'intervention et cloisonnement des incidents (droit « Voir (auteur) »).
 */
final class SecurityIncidentTeamTest extends GrcmanagerIntegrationTestCase
{
    public function testTeamMembershipGivesScopedAccessAndObserverIsReadOnly(): void
    {
        global $DB;

        $incident = new PluginGrcmanagerSecurityIncident();
        $inTeam = (int) $incident->add(['name' => 'PHPUnit cloisonnement équipe', 'entities_id' => 0]);
        $outside = (int) $incident->add(['name' => 'PHPUnit cloisonnement hors équipe', 'entities_id' => 0]);
        $techId = (int) (new User())->add(['name' => 'phpunit-tech-' . bin2hex(random_bytes(4))]);

        // L'administrateur (pilote) compose l'équipe.
        $member = new SecurityIncidentTeam();
        $memberId = (int) $member->add([
            'plugin_grcmanager_securityincidents_id' => $inTeam,
            'users_id' => $techId,
            'role' => SecurityIncidentTeam::RESPONDER,
        ]);
        $this->assertGreaterThan(0, $memberId);

        $savedId = $_SESSION['glpiID'];
        $savedRight = $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname];
        $savedGroups = $_SESSION['glpigroups'] ?? [];
        try {
            // Le technicien : « Voir (auteur) » et modification, sans « Voir tout ».
            $_SESSION['glpiID'] = $techId;
            $_SESSION['glpigroups'] = [];
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname]
                = PluginGrcmanagerSecurityIncident::READMY | UPDATE | CREATE | DELETE | PURGE;

            $visible = new PluginGrcmanagerSecurityIncident();
            $visible->getFromDB($inTeam);
            $hidden = new PluginGrcmanagerSecurityIncident();
            $hidden->getFromDB($outside);

            $this->assertTrue($visible->canViewItem());
            $this->assertTrue($visible->canUpdateItem());
            $this->assertFalse($visible->canDeleteItem(), 'Seuls les pilotes mettent à la corbeille.');
            $this->assertFalse($hidden->canViewItem());
            $this->assertFalse($hidden->canUpdateItem(), 'Modifier exige de voir l\'incident.');

            // Liste et recherche : seul l'incident de l'équipe apparaît.
            $ids = array_column(iterator_to_array($DB->request([
                'SELECT' => [PluginGrcmanagerSecurityIncident::getTable() . '.id'],
                'FROM'   => PluginGrcmanagerSecurityIncident::getTable(),
                'WHERE'  => SecurityIncidentTeam::restrictSearch([]) + [
                    PluginGrcmanagerSecurityIncident::getTable() . '.id' => [$inTeam, $outside],
                ],
            ]), false), 'id');
            $this->assertSame([$inTeam], array_map('intval', $ids));

            // Le technicien ne compose pas l'équipe.
            $this->assertFalse((new SecurityIncidentTeam())->add([
                'plugin_grcmanager_securityincidents_id' => $inTeam,
                'users_id' => (int) $savedId,
            ]));
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpigroups'] = $savedGroups;
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname] = $savedRight;
        }

        // Observateur : lecture seule.
        $member->update(['id' => $memberId, 'role' => SecurityIncidentTeam::OBSERVER]);
        try {
            $_SESSION['glpiID'] = $techId;
            $_SESSION['glpigroups'] = [];
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname]
                = PluginGrcmanagerSecurityIncident::READMY | UPDATE;
            $visible = new PluginGrcmanagerSecurityIncident();
            $visible->getFromDB($inTeam);
            $this->assertTrue($visible->canViewItem());
            $this->assertFalse($visible->canUpdateItem());
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpigroups'] = $savedGroups;
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname] = $savedRight;
        }

        // Retiré de l'équipe : plus d'accès.
        $member->delete(['id' => $memberId], true);
        $this->assertNull(SecurityIncidentTeam::roleOf($inTeam, $techId));
    }
}
