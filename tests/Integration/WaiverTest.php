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

/**
 * Issue #170 : dérogations de sécurité — saisie, validation, actifs liés, rappel puis ticket de
 * réévaluation, sans jamais changer le statut automatiquement.
 */
final class WaiverTest extends GrcmanagerIntegrationTestCase
{
    public function testSearchListRuns(): void
    {
        // Colonnes par défaut de la liste : chaque jointure doit viser une vraie colonne.
        $data = \Glpi\Search\SearchEngine::getData(
            \PluginGrcmanagerWaiver::class,
            ['criteria' => []],
            \GlpiPlugin\Grcmanager\Services\DefaultSearchColumns::COLUMNS[\PluginGrcmanagerWaiver::class]
        );
        $this->assertArrayHasKey('data', $data);
    }

    public function testDateEndIsRequired(): void
    {
        $this->assertFalse((new \PluginGrcmanagerWaiver())->add(['name' => 'Sans fin']));
    }

    public function testApprovalIsStampedAndAssetsAreLinked(): void
    {
        $computerId = (int) (new \Computer())->add(['name' => 'SRV-DEMO-2012', 'entities_id' => 0]);
        $waiver = new \PluginGrcmanagerWaiver();
        $id = (int) $waiver->add([
            'name'                         => 'Serveur applicatif en fin de support',
            'date_begin'                   => '2026-10-01',
            'date_end'                     => '2027-04-01',
            'users_id'                     => 2,
            '_linked_items_Computer_defined' => 1,
            'linked_items_Computer'        => [$computerId],
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(
            [['itemtype' => 'Computer', 'items_id' => $computerId]],
            \PluginGrcmanagerWaiver::getWaiverItems($id)
        );

        $computer = new \Computer();
        $computer->getFromDB($computerId);
        $this->assertCount(1, \PluginGrcmanagerWaiver::getForItem($computer));

        $this->assertTrue($waiver->update(['id' => $id, 'status' => 'approved']));
        $waiver->getFromDB($id);
        $this->assertSame(2, (int) $waiver->fields['users_id_approver']);
        $this->assertNotEmpty($waiver->fields['date_approval']);

        // Une mise à jour sans les listes d'actifs ne touche pas aux liens.
        $waiver->update(['id' => $id, 'compensating_measures' => 'Isolé dans un VLAN dédié']);
        $this->assertCount(1, \PluginGrcmanagerWaiver::getWaiverItems($id));
    }

    public function testReminderThenReevaluationTicketWithoutChangingStatus(): void
    {
        global $DB;

        $computerId = (int) (new \Computer())->add(['name' => 'SRV-DEMO-ECHUE', 'entities_id' => 0]);
        $waiver = new \PluginGrcmanagerWaiver();
        $id = (int) $waiver->add([
            'name'                           => 'Dérogation de démonstration',
            'status'                         => 'approved',
            'date_end'                       => '2026-11-01',
            'users_id'                       => 2,
            '_linked_items_Computer_defined' => 1,
            'linked_items_Computer'          => [$computerId],
        ]);

        $first = \PluginGrcmanagerWaiver::runReminders('2026-10-25');
        $this->assertGreaterThanOrEqual(1, $first['notified']);
        $waiver->getFromDB($id);
        $notifiedAt = $waiver->fields['date_notified'];
        $this->assertNotEmpty($notifiedAt);
        $DB->update('glpi_plugin_grcmanager_waivers', ['date_notified' => '2026-10-25 06:00:00'], ['id' => $id]);
        \PluginGrcmanagerWaiver::runReminders('2026-10-26');
        $waiver->getFromDB($id);
        $this->assertSame('2026-10-25 06:00:00', $waiver->fields['date_notified'], 'Un seul rappel.');

        \PluginGrcmanagerWaiver::runReminders('2026-11-02');
        $waiver->getFromDB($id);
        $ticketsId = (int) $waiver->fields['tickets_id'];
        $this->assertGreaterThan(0, $ticketsId);
        $this->assertSame('approved', $waiver->fields['status'], 'Jamais close automatiquement.');
        $this->assertSame(1, countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticketsId,
            'itemtype'   => 'Computer',
            'items_id'   => $computerId,
        ]));

        // Prolongée par l'équipe : un nouveau cycle commence.
        $waiver->update(['id' => $id, 'date_end' => '2027-05-01']);
        $waiver->getFromDB($id);
        $this->assertSame(0, (int) $waiver->fields['tickets_id']);
        $this->assertEmpty($waiver->fields['date_notified']);
        $DB->delete('glpi_tickets', ['id' => $ticketsId]);
    }
}
