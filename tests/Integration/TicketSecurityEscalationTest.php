<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use MassiveAction;
use GlpiPlugin\Grcmanager\SecurityIncidentTicket;

/**
 * Issue #165 : escalade d'un ticket en incident de sécurité depuis le panneau principal du ticket
 * et en action groupée, sans changer le ticket.
 */
final class TicketSecurityEscalationTest extends GrcmanagerIntegrationTestCase
{
    private function ticket(string $name): \Ticket
    {
        $ticket = new \Ticket();
        $ticket->getFromDB((int) $ticket->add([
            'name' => $name, 'content' => 'Lien suspect cliqué', 'entities_id' => 0,
        ]));

        return $ticket;
    }

    private function section(\Ticket $ticket): string
    {
        ob_start();
        plugin_grcmanager_post_itil_info_section(['item' => $ticket]);

        return (string) ob_get_clean();
    }

    public function testTheTicketPanelOffersEscalationThenShowsTheIncident(): void
    {
        $ticket = $this->ticket('PHPUnit lien suspect');
        $html = $this->section($ticket);
        $this->assertStringContainsString('grcmanager-qualify-' . $ticket->getID(), $html, 'Bouton d\'escalade.');

        $incidentsId = \GlpiPlugin\Grcmanager\SecurityIncidentTicket::qualify($ticket);
        $html = $this->section($ticket);
        $this->assertStringNotContainsString('grcmanager-qualify-' . $ticket->getID(), $html);
        $this->assertStringContainsString('(' . $incidentsId . ')', $html, 'Lien vers l\'incident.');

        $this->assertSame('', $this->section(new \Ticket()), 'Rien sur un ticket en cours de création.');
    }

    public function testMassiveActionQualifiesEachTicketOnceAndLeavesItsStatus(): void
    {
        $first = $this->ticket('PHPUnit masse 1');
        $second = $this->ticket('PHPUnit masse 2');
        $statusBefore = (int) $first->fields['status'];
        $ma = new class extends MassiveAction {
            /** @var array<int, int> */
            public array $log = [];

            public function __construct()
            {
            }

            public function getAction()
            {
                return 'qualify';
            }

            public function itemDone($itemtype, $id, $result)
            {
                $this->log[(int) $id] = $result;
            }
        };

        \GlpiPlugin\Grcmanager\SecurityIncidentTicket::processMassiveActionsForOneItemtype(
            $ma,
            new \Ticket(),
            [$first->getID(), $second->getID(), 999999999]
        );

        $this->assertSame(MassiveAction::ACTION_OK, $ma->log[$first->getID()]);
        $this->assertSame(MassiveAction::ACTION_OK, $ma->log[$second->getID()]);
        $this->assertSame(MassiveAction::ACTION_NORIGHT, $ma->log[999999999], 'Ticket inexistant.');
        $this->assertCount(1, \GlpiPlugin\Grcmanager\SecurityIncidentTicket::getIncidentIdsForTicket($first->getID()));

        \GlpiPlugin\Grcmanager\SecurityIncidentTicket::processMassiveActionsForOneItemtype(
            $ma,
            new \Ticket(),
            [$first->getID()]
        );
        $incidents = \GlpiPlugin\Grcmanager\SecurityIncidentTicket::getIncidentIdsForTicket($first->getID());
        $this->assertCount(1, $incidents, 'Pas de doublon.');
        $first->getFromDB($first->getID());
        $this->assertSame($statusBefore, (int) $first->fields['status'], 'Le ticket garde son statut.');
    }

    public function testTheListActionIsOfferedOnTicketsOnly(): void
    {
        $actions = plugin_grcmanager_MassiveActions(\Ticket::class);
        $this->assertArrayHasKey(
            \GlpiPlugin\Grcmanager\SecurityIncidentTicket::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'qualify',
            $actions
        );
        $this->assertSame([], plugin_grcmanager_MassiveActions(\Computer::class));
    }
}
