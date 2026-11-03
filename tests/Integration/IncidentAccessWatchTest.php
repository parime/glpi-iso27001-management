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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\IncidentAccessWatch;
use PluginGrcmanagerSecurityIncident;
use Profile;
use Profile_User;
use User;

/**
 * Issue #371 : surveillance des accès au module des incidents de sécurité.
 */
final class IncidentAccessWatchTest extends GrcmanagerIntegrationTestCase
{
    public function testRightGrantedAndProfileAssignedAreTracedAndNotified(): void
    {
        global $DB;

        // Référence : premier passage, rien à signaler.
        IncidentAccessWatch::storeSnapshot(['profiles' => [], 'users' => []]);
        $DB->update('glpi_plugin_grcmanager_securityincidentconfig', ['access_snapshot' => null], ['id' => 1]);
        $this->assertSame([], IncidentAccessWatch::check());

        // Un nouveau profil reçoit le droit « Voir (auteur) », puis est attribué à un utilisateur.
        $profileId = (int) (new Profile())->add(['name' => 'PHPUnit accès incidents ' . bin2hex(random_bytes(3))]);
        $DB->updateOrInsert('glpi_profilerights', ['rights' => PluginGrcmanagerSecurityIncident::READMY], [
            'profiles_id' => $profileId,
            'name'        => PluginGrcmanagerSecurityIncident::$rightname,
        ]);
        $userId = (int) (new User())->add(['name' => 'phpunit-acces-' . bin2hex(random_bytes(3))]);
        (new Profile_User())->add(['users_id' => $userId, 'profiles_id' => $profileId, 'entities_id' => 0]);

        $queuedBefore = countElementsInTable('glpi_queuednotifications');
        $changes = IncidentAccessWatch::check();
        $types = array_column($changes, 'type');
        $this->assertContains('right_granted', $types);
        $this->assertContains('profile_assigned', $types);

        $this->assertGreaterThanOrEqual(2, countElementsInTable(AuditLog::getTable(), [
            'action'   => 'rights_change',
            'itemtype' => 'Profile',
            'items_id' => $profileId,
        ]));
        $this->assertNotSame('', IncidentAccessWatch::describe($changes[0]));
        // Notification envoyée à l'administrateur GLPI (cible par défaut) : mise en file d'attente.
        $this->assertGreaterThanOrEqual($queuedBefore, countElementsInTable('glpi_queuednotifications'));

        // Sans nouveau changement : rien.
        $this->assertSame([], IncidentAccessWatch::check());
    }
}
