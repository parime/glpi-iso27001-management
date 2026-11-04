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

use Computer;
use GlpiPlugin\Grcmanager\SecurityIncidentLinkedItem;
use GlpiPlugin\Grcmanager\SecurityIncidentTicket;
use Item_Problem;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncident_User;
use Problem;
use Ticket;
use User;

/**
 * Issue #372 : onglet « Incidents de sécurité » des éléments liés et « Déclarer un incident ».
 * Rien ne doit trahir un incident invisible (entité, cloisonnement de l'équipe d'intervention).
 */
final class SecurityIncidentLinkedItemTest extends GrcmanagerIntegrationTestCase
{
    private const RIGHT = PluginGrcmanagerSecurityIncident::RIGHTNAME;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['glpishow_count_on_tabs'] = 1;
    }

    private function computer(int $entity = 0): Computer
    {
        $computer = new Computer();
        $id = (int) $computer->add(['name' => 'PHPUnit PC-COMPTA-01', 'entities_id' => $entity]);
        $this->assertGreaterThan(0, $id);
        $computer->getFromDB($id);

        return $computer;
    }

    private function incidentOn(string $name = 'PHPUnit rançongiciel', int $entity = 0): int
    {
        $id = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name'        => $name,
            'content'     => 'Fictif',
            'entities_id' => $entity,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function link(int $incidentsId, \CommonDBTM $item): void
    {
        $this->assertGreaterThan(0, (int) (new PluginGrcmanagerSecurityIncident_Item())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentsId,
            'itemtype'                               => $item::class,
            'items_id'                               => (int) $item->getID(),
        ]));
    }

    private function tab(\CommonGLPI $item): string
    {
        ob_start();
        SecurityIncidentLinkedItem::displayTabContentForItem($item);

        return (string) ob_get_clean();
    }

    /**
     * Compteur affiché par l'onglet (-1 sans compteur, null sans onglet).
     */
    private function tabCount(\CommonGLPI $provider, \CommonGLPI $item): ?int
    {
        $name = $provider->getTabNameForItem($item);
        if (!is_string($name) || $name === '') {
            return null;
        }

        return preg_match('/(\d+)\s*$/', trim(strip_tags($name)), $m) === 1 ? (int) $m[1] : -1;
    }

    public function testDeclareCreatesAnIncidentLinkedToTheItem(): void
    {
        $computer = $this->computer();
        $tab = $this->tab($computer);
        $this->assertStringContainsString('grcmanager-declare-incident-' . $computer->getID(), $tab);
        $this->assertStringContainsString('_glpi_csrf_token', $tab);

        $incidentsId = SecurityIncidentLinkedItem::declare($computer);
        $this->assertGreaterThan(0, $incidentsId);

        $incident = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($incident->getFromDB($incidentsId));
        $this->assertStringContainsString('PHPUnit PC-COMPTA-01', (string) $incident->fields['name']);
        $this->assertSame(0, (int) $incident->fields['entities_id'], 'Entité de l\'élément.');
        $this->assertSame([$incidentsId], SecurityIncidentLinkedItem::getIncidentIdsForItem($computer));

        $tab = $this->tab($computer);
        $this->assertStringContainsString((string) $incident->fields['reference'], $tab);
        $this->assertStringContainsString(
            htmlescape(PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentsId)),
            $tab
        );
        $this->assertSame(1, $this->tabCount(new SecurityIncidentLinkedItem(), $computer));
    }

    public function testNameIsEscaped(): void
    {
        $computer = $this->computer();
        $id = $this->incidentOn('PHPUnit <script>alert(1)</script>');
        $this->link($id, $computer);

        $tab = $this->tab($computer);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $tab);
        $this->assertStringContainsString('&lt;script&gt;', $tab);
    }

    public function testInvisibleIncidentIsNeitherCountedNorListed(): void
    {
        $computer = $this->computer();
        $visible = $this->incidentOn('PHPUnit incident de mon équipe');
        $hidden = $this->incidentOn('PHPUnit incident cloisonné');
        $this->link($visible, $computer);
        $this->link($hidden, $computer);

        $techId = $this->createTestUser('Camille', 'Fictive');
        $this->assertGreaterThan(0, (int) (new PluginGrcmanagerSecurityIncident_User())->add([
            'plugin_grcmanager_securityincidents_id' => $visible,
            'users_id'                               => $techId,
            'type'                                   => \CommonITILActor::OBSERVER,
        ]));

        $savedId = $_SESSION['glpiID'];
        $savedRight = $_SESSION['glpiactiveprofile'][self::RIGHT];
        $savedGroups = $_SESSION['glpigroups'] ?? [];
        try {
            // « Voir (auteur) » seulement : l'incident où il n'est pas acteur reste invisible.
            $_SESSION['glpiID'] = $techId;
            $_SESSION['glpigroups'] = [];
            $_SESSION['glpiactiveprofile'][self::RIGHT] = PluginGrcmanagerSecurityIncident::READMY;

            $listed = array_map(
                static fn (PluginGrcmanagerSecurityIncident $i): int => (int) $i->getID(),
                SecurityIncidentLinkedItem::getVisibleIncidents($computer)
            );
            $this->assertSame([$visible], $listed);

            $this->assertSame(1, $this->tabCount(new SecurityIncidentLinkedItem(), $computer), 'Jamais 2.');

            $tab = $this->tab($computer);
            $this->assertStringContainsString('PHPUnit incident de mon équipe', $tab);
            $this->assertStringNotContainsString('cloisonné', $tab);
            $this->assertStringNotContainsString('grcmanager-declare-incident-', $tab, 'Pas de droit de création.');
            $this->assertSame(0, SecurityIncidentLinkedItem::declare($computer));
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpigroups'] = $savedGroups;
            $_SESSION['glpiactiveprofile'][self::RIGHT] = $savedRight;
        }
    }

    public function testIncidentOfAnotherEntityIsNotListed(): void
    {
        $entityB = $this->createTestEntity(0, 'PHPUnit entité B ' . random_int(1000, 9999));
        $computer = $this->computer();
        $other = $this->incidentOn('PHPUnit incident entité B', $entityB);
        $this->link($other, $computer);

        $saved = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        try {
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = '0';
            $this->assertSame([], SecurityIncidentLinkedItem::getVisibleIncidents($computer));
            $this->assertStringNotContainsString('entité B', $this->tab($computer));
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $saved;
        }
    }

    public function testNothingWithoutTheRightToReadIncidents(): void
    {
        $computer = $this->computer();
        $this->link($this->incidentOn(), $computer);

        $saved = $_SESSION['glpiactiveprofile'][self::RIGHT];
        try {
            $_SESSION['glpiactiveprofile'][self::RIGHT] = 0;
            $this->assertSame('', (new SecurityIncidentLinkedItem())->getTabNameForItem($computer));
            // Contenu demandé directement (ajax/common.tabs.php ne consulte pas getTabNameForItem()).
            $this->assertSame('', $this->tab($computer));
            $this->assertSame(0, SecurityIncidentLinkedItem::declare($computer));
        } finally {
            $_SESSION['glpiactiveprofile'][self::RIGHT] = $saved;
        }
    }

    public function testDeclareNeedsToReadTheItem(): void
    {
        $computer = $this->computer();
        $saved = $_SESSION['glpiactiveprofile']['computer'];
        try {
            $_SESSION['glpiactiveprofile']['computer'] = 0;
            $this->assertFalse(SecurityIncidentLinkedItem::canDeclareFor($computer));
            $this->assertSame(0, SecurityIncidentLinkedItem::declare($computer));
            $this->assertSame('', $this->tab($computer));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $saved;
        }
        $this->assertSame([], SecurityIncidentLinkedItem::getIncidentIdsForItem($computer));
    }

    public function testDeclareFromAProblemTakesItsContentAndItems(): void
    {
        $computer = $this->computer();
        $problem = new Problem();
        $problemsId = (int) $problem->add([
            'name'        => 'PHPUnit problème récurrent de chiffrement',
            'content'     => 'Description fictive du problème',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $problemsId);
        (new Item_Problem())->add([
            'problems_id' => $problemsId,
            'itemtype'    => Computer::class,
            'items_id'    => (int) $computer->getID(),
        ]);
        $problem->getFromDB($problemsId);

        $incidentsId = SecurityIncidentLinkedItem::declare($problem);
        $this->assertGreaterThan(0, $incidentsId);
        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->getFromDB($incidentsId);
        $this->assertSame('PHPUnit problème récurrent de chiffrement', $incident->fields['name']);

        $this->assertSame([$incidentsId], SecurityIncidentLinkedItem::getIncidentIdsForItem($problem));
        $this->assertSame(
            [$incidentsId],
            SecurityIncidentLinkedItem::getIncidentIdsForItem($computer),
            'Éléments du problème repris.'
        );
    }

    public function testUserTabListsIncidentsWhereTheUserIsAnActor(): void
    {
        $usersId = $this->createTestUser('Dominique', 'Fictif');
        $user = new User();
        $user->getFromDB($usersId);
        $incidentsId = $this->incidentOn('PHPUnit compte compromis');
        (new PluginGrcmanagerSecurityIncident_User())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentsId,
            'users_id'                               => $usersId,
            'type'                                   => \CommonITILActor::REQUESTER,
        ]);

        $this->assertSame([$incidentsId], SecurityIncidentLinkedItem::getIncidentIdsForItem($user));
        $this->assertStringContainsString('PHPUnit compte compromis', $this->tab($user));

        // Déclarer depuis l'utilisateur le lie comme élément, jamais comme acteur (un acteur
        // gagnerait la lecture de l'incident).
        $declared = SecurityIncidentLinkedItem::declare($user);
        $this->assertGreaterThan(0, $declared);
        $this->assertSame(0, countElementsInTable('glpi_plugin_grcmanager_securityincidents_users', [
            'plugin_grcmanager_securityincidents_id' => $declared,
            'users_id'                               => $usersId,
        ]));
        $this->assertContains($declared, SecurityIncidentLinkedItem::getIncidentIdsForItem($user));

        // L'onglet « Éléments » natif de l'incident s'en tient au parc : l'utilisateur lié paraît
        // dans « Autres éléments liés », s'il est lisible.
        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->getFromDB($declared);
        $this->assertSame(1, $this->tabCount(new SecurityIncidentLinkedItem(), $incident));
        $this->assertStringContainsString('Dominique', $this->tab($incident));

        $saved = $_SESSION['glpiactiveprofile']['user'];
        try {
            $_SESSION['glpiactiveprofile']['user'] = 0;
            $this->assertNull($this->tabCount(new SecurityIncidentLinkedItem(), $incident));
            $this->assertStringNotContainsString('Dominique', $this->tab($incident));
        } finally {
            $_SESSION['glpiactiveprofile']['user'] = $saved;
        }

        // Un incident sans tel lien n'a pas cet onglet.
        $plain = new PluginGrcmanagerSecurityIncident();
        $plain->getFromDB($incidentsId);
        $this->assertNull($this->tabCount(new SecurityIncidentLinkedItem(), $plain));
    }

    public function testTicketTabCountsOnlyVisibleIncidents(): void
    {
        $ticket = new Ticket();
        $ticketsId = (int) $ticket->add(['name' => 'PHPUnit poste lent', 'content' => 'Fictif', 'entities_id' => 0]);
        $ticket->getFromDB($ticketsId);
        $incidentsId = SecurityIncidentTicket::qualify($ticket);
        $this->assertGreaterThan(0, $incidentsId);

        $this->assertSame(1, $this->tabCount(new SecurityIncidentTicket(), $ticket));

        $savedId = $_SESSION['glpiID'];
        $savedRight = $_SESSION['glpiactiveprofile'][self::RIGHT];
        try {
            $_SESSION['glpiID'] = $this->createTestUser('Alex', 'Fictif');
            $_SESSION['glpiactiveprofile'][self::RIGHT] = PluginGrcmanagerSecurityIncident::READMY;
            $this->assertSame(-1, $this->tabCount(new SecurityIncidentTicket(), $ticket), 'Aucun compteur.');
        } finally {
            $_SESSION['glpiID'] = $savedId;
            $_SESSION['glpiactiveprofile'][self::RIGHT] = $savedRight;
        }
    }
}
