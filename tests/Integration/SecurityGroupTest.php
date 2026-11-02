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
use GlpiPlugin\Grcmanager\Services\Incident\SecurityGroup;
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
        SecurityGroup::save($groupId);
        try {
            // Membre du groupe : les droits de son profil s'appliquent.
            $_SESSION['glpiID'] = $memberId;
            SecurityGroup::reset();
            $this->assertTrue(PluginGrcmanagerSecurityIncident::canView());
            $this->assertTrue($incident->getFromDB($incidentId) && $incident->canViewItem());

            // Hors du groupe, sans droit de configuration : module fermé malgré le profil.
            $_SESSION['glpiID'] = $outsiderId;
            $_SESSION['glpiactiveprofile']['config'] = 0;
            SecurityGroup::reset();
            $this->assertFalse(PluginGrcmanagerSecurityIncident::canView());
            $this->assertFalse(PluginGrcmanagerSecurityIncident::canCreate());
            $this->assertFalse($incident->canViewItem());

            // Administrateur GLPI hors du groupe : jamais bloqué, mais signalé.
            $_SESSION['glpiactiveprofile']['config'] = $savedConfig ?: (READ | UPDATE);
            SecurityGroup::reset();
            $this->assertTrue(PluginGrcmanagerSecurityIncident::canView());
            $this->assertTrue(SecurityGroup::isAdministratorBypass());
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpiactiveprofile']['config'] = $savedConfig;
            SecurityGroup::save(0);
        }
        $this->assertFalse(SecurityGroup::isAdministratorBypass());
    }
}
