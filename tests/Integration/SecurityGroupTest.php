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

use Group;
use Group_User;
use GlpiPlugin\Grcmanager\IncidentSecurityGroup;
use GlpiPlugin\Grcmanager\SecurityIncidentTeam;
use PluginGrcmanagerSecurityIncident;
use User;

/**
 * Issue #371 : groupe sécurité optionnel du module des incidents.
 */
final class SecurityGroupTest extends GrcmanagerIntegrationTestCase
{
    public function testOnlyGroupMembersUseTheModuleAndAdministratorsAreFlagged(): void
    {
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit groupe sécurité', 'entities_id' => 0]);
        $memberId = (int) (new User())->add(['name' => 'phpunit-sg-' . bin2hex(random_bytes(4))]);
        $outsiderId = (int) (new User())->add(['name' => 'phpunit-sg-' . bin2hex(random_bytes(4))]);
        (new Group_User())->add(['groups_id' => $groupId, 'users_id' => $memberId]);

        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add(['name' => 'PHPUnit groupe sécurité', 'entities_id' => 0]);

        $savedId = $_SESSION['glpiID'];
        $savedConfig = $_SESSION['glpiactiveprofile']['config'] ?? 0;
        IncidentSecurityGroup::save($groupId);
        try {
            // Membre du groupe : les droits de son profil s'appliquent.
            $_SESSION['glpiID'] = $memberId;
            IncidentSecurityGroup::reset();
            $this->assertTrue(PluginGrcmanagerSecurityIncident::canView());
            $this->assertTrue($incident->getFromDB($incidentId) && $incident->canViewItem());

            // Hors du groupe, sans droit de configuration : module fermé malgré le profil.
            $_SESSION['glpiID'] = $outsiderId;
            $_SESSION['glpiactiveprofile']['config'] = 0;
            IncidentSecurityGroup::reset();
            $this->assertFalse(PluginGrcmanagerSecurityIncident::canView());
            $this->assertFalse(PluginGrcmanagerSecurityIncident::canCreate());
            $this->assertFalse($incident->canViewItem());

            // Administrateur GLPI hors du groupe : jamais bloqué, mais signalé.
            $_SESSION['glpiactiveprofile']['config'] = $savedConfig ?: (READ | UPDATE);
            IncidentSecurityGroup::reset();
            $this->assertTrue(PluginGrcmanagerSecurityIncident::canView());
            $this->assertTrue(IncidentSecurityGroup::isAdministratorBypass());
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpiactiveprofile']['config'] = $savedConfig;
            IncidentSecurityGroup::save(0);
        }
        $this->assertFalse(IncidentSecurityGroup::isAdministratorBypass());
    }

    /**
     * La recherche suit la règle d'ouverture : aucune ligne pour un incident que l'utilisateur ne
     * peut pas ouvrir (hors du groupe sécurité, ou simple rédacteur sans être acteur).
     */
    public function testSearchListsOnlyIncidentsTheUserCanOpen(): void
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add(['name' => 'PHPUnit recherche cloisonnée', 'entities_id' => 0]);
        $userId = (int) (new User())->add(['name' => 'phpunit-sg-' . bin2hex(random_bytes(4))]);
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit groupe recherche', 'entities_id' => 0]);
        $visible = static fn (): int => count($DB->request([
            'SELECT' => ["$table.id"],
            'FROM'   => $table,
            'WHERE'  => SecurityIncidentTeam::restrictSearch([["$table.id" => $incidentId]]),
        ]));

        $right = PluginGrcmanagerSecurityIncident::$rightname;
        $savedId = $_SESSION['glpiID'];
        $savedRight = $_SESSION['glpiactiveprofile'][$right];
        $savedConfig = $_SESSION['glpiactiveprofile']['config'] ?? 0;
        $savedGroups = $_SESSION['glpigroups'] ?? [];
        try {
            $this->assertSame(1, $visible(), 'Voir tout, sans groupe sécurité.');

            // « Voir tout » mais hors du groupe sécurité.
            $_SESSION['glpiID'] = $userId;
            $_SESSION['glpiactiveprofile']['config'] = 0;
            IncidentSecurityGroup::save($groupId);
            IncidentSecurityGroup::reset();
            $this->assertSame(0, $visible(), 'Hors du groupe : aucune ligne.');
            IncidentSecurityGroup::save(0);
            IncidentSecurityGroup::reset();

            // Simple rédacteur : l'incident ne s'ouvre pas, il n'est pas listé non plus.
            $DB->update($table, ['users_id_recipient' => $userId], ['id' => $incidentId]);
            $_SESSION['glpiactiveprofile'][$right] = PluginGrcmanagerSecurityIncident::READMY;
            $_SESSION['glpigroups'] = [];
            $this->assertTrue($incident->getFromDB($incidentId));
            $this->assertFalse($incident->canViewItem());
            $this->assertSame(0, $visible(), 'Rédacteur sans être acteur : aucune ligne.');
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpiactiveprofile'][$right] = $savedRight;
            $_SESSION['glpiactiveprofile']['config'] = $savedConfig;
            $_SESSION['glpigroups'] = $savedGroups;
            IncidentSecurityGroup::save(0);
        }
    }
}
