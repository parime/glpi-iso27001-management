<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Computer;
use CronTask;
use Item_Ticket;
use ITILFollowup;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncidentTicket;
use Ticket;

/**
 * Issue #128 : qualification d'un ticket en incident de sécurité et délais de notification
 * réglementaires (NIS2, RGPD).
 */
final class SecurityIncidentRegulatoryTest extends GrcmanagerIntegrationTestCase
{
    private function followups(string $itemtype, int $id): int
    {
        return countElementsInTable(ITILFollowup::getTable(), ['itemtype' => $itemtype, 'items_id' => $id]);
    }

    public function testATicketIsQualifiedOnceWithItsItemsAndKeepsItsStatus(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Qualification Entity');
        $ticket = new Ticket();
        $ticketsId = (int) $ticket->add(['name' => 'PHPUnit poste infecté', 'content' => 'Rançongiciel détecté', 'entities_id' => $entityId]);
        $computerId = (int) (new Computer())->add(['name' => 'PHPUNIT-PC-INFECTE', 'entities_id' => $entityId]);
        (new Item_Ticket())->add(['tickets_id' => $ticketsId, 'itemtype' => Computer::class, 'items_id' => $computerId]);
        $ticket->getFromDB($ticketsId);
        $status = (int) $ticket->fields['status'];

        $incidentsId = PluginGrcmanagerSecurityIncidentTicket::qualify($ticket);

        $this->assertGreaterThan(0, $incidentsId);
        $incident = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($incident->getFromDB($incidentsId));
        $this->assertSame('PHPUnit poste infecté', $incident->fields['name']);
        $this->assertSame([$incidentsId], PluginGrcmanagerSecurityIncidentTicket::getIncidentIdsForTicket($ticketsId));
        $this->assertSame([$ticketsId], PluginGrcmanagerSecurityIncidentTicket::getTicketIdsForIncident($incidentsId));
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentsId, 'itemtype' => Computer::class, 'items_id' => $computerId,
        ]));
        $this->assertSame(1, $this->followups(Ticket::class, $ticketsId), 'suivi privé sur le ticket');
        $ticket->getFromDB($ticketsId);
        $this->assertSame($status, (int) $ticket->fields['status'], 'statut du ticket inchangé');

        $this->assertSame($incidentsId, PluginGrcmanagerSecurityIncidentTicket::qualify($ticket), 'pas de second incident');
    }

    public function testPurgingTheTicketRemovesTheLink(): void
    {
        $ticket = new Ticket();
        $ticketsId = (int) $ticket->add(['name' => 'PHPUnit à purger', 'content' => 'x', 'entities_id' => 0]);
        $ticket->getFromDB($ticketsId);
        $incidentsId = PluginGrcmanagerSecurityIncidentTicket::qualify($ticket);

        $ticket->delete(['id' => $ticketsId], true);

        $this->assertSame([], PluginGrcmanagerSecurityIncidentTicket::getTicketIdsForIncident($incidentsId));
    }

    public function testNotificationDatesAreSavedFromTheBrowserFormat(): void
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add(['name' => 'PHPUnit NIS2', 'content' => 'x', 'entities_id' => 0, 'nis2_applicable' => 'on']);
        $incident->update(['id' => $id, 'nis2_early_warning_at' => '2026-10-18T20:15', 'gdpr_notification_at' => '']);

        $incident->getFromDB($id);
        $this->assertSame(1, (int) $incident->fields['nis2_applicable']);
        $this->assertSame('2026-10-18 20:15:00', $incident->fields['nis2_early_warning_at']);
        $this->assertNull($incident->fields['gdpr_notification_at']);
    }

    public function testTheCronAddsOneFollowupPerAlertLevel(): void
    {
        global $DB;
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add(['name' => 'PHPUnit délai', 'content' => 'x', 'entities_id' => 0, 'nis2_applicable' => 1]);
        // Détecté il y a 20 h : l'alerte précoce NIS2 (24 h) est proche, les autres non.
        $DB->update(PluginGrcmanagerSecurityIncident::getTable(), ['date' => date('Y-m-d H:i:s', strtotime('-20 hours'))], ['id' => $id]);
        $before = $this->followups(PluginGrcmanagerSecurityIncident::class, $id);

        $task = new CronTask();
        PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines($task);
        PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines($task);

        $this->assertSame($before + 1, $this->followups(PluginGrcmanagerSecurityIncident::class, $id));
        $incident->getFromDB($id);
        $this->assertSame(['nis2_early_warning:due_soon'], json_decode((string) $incident->fields['regulatory_alerts'], true));
    }
}
