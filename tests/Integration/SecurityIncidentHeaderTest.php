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

use GlpiPlugin\Grcmanager\SecurityIncidentTicket;
use PluginGrcmanagerSecurityIncident;
use Ticket;

/**
 * Issue #372 : en-tête de la fiche incident (compteurs vers les onglets, bandeaux d'échéance avec
 * lien vers la section réglementaire), sections à bandeau, et « Créer un ticket lié ».
 */
final class SecurityIncidentHeaderTest extends GrcmanagerIntegrationTestCase
{
    private function incident(array $extra = []): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add($extra + [
            'name'        => 'PHPUnit hameçonnage SITE_A',
            'content'     => 'Description confidentielle de l\'incident',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    private function header(PluginGrcmanagerSecurityIncident $incident): string
    {
        ob_start();
        plugin_grcmanager_pre_itil_info_section(['item' => $incident]);

        return (string) ob_get_clean();
    }

    private function ticketsTab(PluginGrcmanagerSecurityIncident $incident): string
    {
        ob_start();
        SecurityIncidentTicket::displayTabContentForItem($incident);

        return (string) ob_get_clean();
    }

    public function testLinkedTicketTakesOnlyReferenceAndTitle(): void
    {
        $incident = $this->incident();
        $ticketsId = SecurityIncidentTicket::createLinkedTicket($incident);
        $this->assertGreaterThan(0, $ticketsId);

        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB($ticketsId));
        $this->assertSame(
            '[' . $incident->fields['reference'] . '] PHPUnit hameçonnage SITE_A',
            $ticket->fields['name']
        );
        $this->assertSame(0, (int) $ticket->fields['entities_id'], 'Entité de l\'incident.');
        $this->assertStringContainsString((string) $incident->fields['reference'], (string) $ticket->fields['content']);
        $this->assertStringNotContainsString(
            'confidentielle',
            (string) $ticket->fields['content'],
            'Jamais la description.'
        );
        $this->assertSame([$ticketsId], SecurityIncidentTicket::getTicketIdsForIncident((int) $incident->getID()));
    }

    public function testLinkedTicketIsRefusedWithoutTheTicketRight(): void
    {
        $incident = $this->incident();
        $saved = $_SESSION['glpiactiveprofile']['ticket'];
        $_SESSION['glpiactiveprofile']['ticket'] = READ;
        try {
            $this->assertSame(0, SecurityIncidentTicket::createLinkedTicket($incident));
            $this->assertStringNotContainsString('grcmanager-create-ticket-', $this->ticketsTab($incident));
        } finally {
            $_SESSION['glpiactiveprofile']['ticket'] = $saved;
        }
        $this->assertSame([], SecurityIncidentTicket::getTicketIdsForIncident((int) $incident->getID()));
    }

    public function testCreateButtonNeedsTheRightToUpdateTheIncident(): void
    {
        $incident = $this->incident();
        $tab = $this->ticketsTab($incident);
        $this->assertStringContainsString('grcmanager-create-ticket-' . $incident->getID(), $tab);
        $this->assertStringContainsString('_glpi_csrf_token', $tab);

        $saved = $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::RIGHTNAME];
        $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::RIGHTNAME] = READ;
        try {
            $this->assertStringNotContainsString('grcmanager-create-ticket-', $this->ticketsTab($incident));
        } finally {
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::RIGHTNAME] = $saved;
        }
    }

    public function testHeaderCountersLinkToTheirTabs(): void
    {
        $incident = $this->incident();
        SecurityIncidentTicket::createLinkedTicket($incident);

        $counters = array_column($incident->getHeaderCounters(), null, 'url');
        $ticketsTab = PluginGrcmanagerSecurityIncident::getFormURLWithID((int) $incident->getID())
            . '&forcetab=' . urlencode(SecurityIncidentTicket::class . '$1');
        $this->assertArrayHasKey($ticketsTab, $counters);
        $this->assertSame(1, $counters[$ticketsTab]['count']);
        $this->assertCount(4, $counters, 'Journal des actions, IOC, éléments et tickets liés.');

        $html = $this->header($incident);
        $this->assertStringContainsString(htmlescape($ticketsTab), $html);
        $this->assertStringContainsString((string) $incident->fields['reference'], $html);
    }

    public function testDeadlineBannerLinksToTheRegulatorySection(): void
    {
        // Détecté il y a 20 h : l'alerte précoce NIS2 (24 h) est proche, en orange.
        $incident = $this->incident([
            'date'            => date('Y-m-d H:i:s', time() - 20 * 3600),
            'nis2_applicable' => 1,
        ]);

        $html = $this->header($incident);
        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('href="#grcmanager-regulatory-heading"', $html);

        $html = $this->header($this->incident());
        $this->assertStringNotContainsString('grcmanager-banner-link', $html, 'Sans échéance, pas de bandeau.');
    }

    public function testClassificationIsSplitIntoBandedSections(): void
    {
        $incident = $this->incident();
        ob_start();
        plugin_grcmanager_post_itil_info_section(['item' => $incident]);
        $html = (string) ob_get_clean();

        foreach (['ti-tag', 'ti-shield-half', 'ti-bulb'] as $icon) {
            $this->assertStringContainsString('ti ' . $icon, $html);
        }
    }
}
