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

use Config;
use Entity;
use GlpiPlugin\Grcmanager\SecurityCategoryLink;
use GlpiPlugin\Grcmanager\SecurityIncidentTicket;
use ITILCategory;
use PluginGrcmanagerSecurityIncident;
use Ticket;

/**
 * Issue #372 : catégories « Sécurité SI » de Configuration GLPI Auto réutilisées pour les incidents
 * de sécurité et leurs tickets, lues par identifiant ; sans ce plugin, rien ne change.
 */
final class SecurityCategoryLinkTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, int> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Contrat de Configuration GLPI Auto, reproduit avec des catégories fictives.
        $branch = $this->category('PHPUnit Sécurité SI', 0);
        $this->categories = [
            'security'         => $branch,
            'security_malware' => $this->category('PHPUnit Malware', $branch),
            'security_account' => $this->category('PHPUnit Compte compromis', $branch),
        ];
        $values = [];
        foreach ($this->categories as $key => $id) {
            $values[$key . '_itilcategories_id'] = $id;
        }
        Config::setConfigurationValues(SecurityCategoryLink::CGA_CONTEXT, $values);
        SecurityCategoryLink::$forceCgaActive = true;
    }

    protected function tearDown(): void
    {
        SecurityCategoryLink::$forceCgaActive = null;
        Config::deleteConfigurationValues(SecurityCategoryLink::CGA_CONTEXT, array_map(
            static fn (string $key): string => $key . '_itilcategories_id',
            SecurityCategoryLink::KEYS
        ));
        foreach (array_reverse($this->categories) as $id) {
            (new ITILCategory())->delete(['id' => $id], true);
        }
        parent::tearDown();
    }

    private function category(string $name, int $parent, int $entity = 0, int $recursive = 1): int
    {
        $id = (int) (new ITILCategory())->add([
            'name' => $name, 'itilcategories_id' => $parent, 'entities_id' => $entity,
            'is_recursive' => $recursive, 'is_incident' => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function incident(array $extra = []): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add($extra + [
            'name' => 'PHPUnit incident SITE_A', 'content' => 'x', 'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    public function testLinkedTicketIsFiledInTheMatchingCategory(): void
    {
        $ticket = new Ticket();
        $ticket->getFromDB(SecurityIncidentTicket::createLinkedTicket($this->incident(['category' => 'malware'])));
        $this->assertSame($this->categories['security_malware'], (int) $ticket->fields['itilcategories_id']);

        $ticket->getFromDB(SecurityIncidentTicket::createLinkedTicket($this->incident(['category' => 'availability'])));
        $this->assertSame($this->categories['security'], (int) $ticket->fields['itilcategories_id'], 'Branche.');

        // La catégorie choisie sur l'incident prime.
        $own = $this->category('PHPUnit catégorie propre', 0);
        $this->categories['own'] = $own;
        $incident = $this->incident(['category' => 'malware', 'itilcategories_id' => $own]);
        $ticket->getFromDB(SecurityIncidentTicket::createLinkedTicket($incident));
        $this->assertSame($own, (int) $ticket->fields['itilcategories_id']);
    }

    public function testQualifiedTicketGivesItsCategoryAndAnUnambiguousClassification(): void
    {
        $ticket = new Ticket();
        $ticketsId = (int) $ticket->add([
            'name' => 'PHPUnit ransomware', 'content' => 'x', 'entities_id' => 0,
            'itilcategories_id' => $this->categories['security_malware'],
        ]);
        $ticket->getFromDB($ticketsId);
        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->getFromDB(SecurityIncidentTicket::qualify($ticket));
        $this->assertSame('malware', $incident->fields['category']);
        $this->assertSame($this->categories['security_malware'], (int) $incident->fields['itilcategories_id']);

        // Compte compromis : accès non autorisé ou fuite, la classification reste à choisir.
        $ticketsId = (int) $ticket->add([
            'name' => 'PHPUnit compte', 'content' => 'x', 'entities_id' => 0,
            'itilcategories_id' => $this->categories['security_account'],
        ]);
        $ticket->getFromDB($ticketsId);
        $incident->getFromDB(SecurityIncidentTicket::qualify($ticket));
        $this->assertSame('other', $incident->fields['category']);
    }

    public function testNothingChangesWithoutConfigurationGlpiAuto(): void
    {
        SecurityCategoryLink::$forceCgaActive = false;
        $this->assertSame([], SecurityCategoryLink::ids());
        $ticket = new Ticket();
        $ticket->getFromDB(SecurityIncidentTicket::createLinkedTicket($this->incident(['category' => 'malware'])));
        $this->assertSame(0, (int) $ticket->fields['itilcategories_id'], 'Aucune catégorie créée ni devinée.');
    }

    public function testCategoryOfAnotherEntityIsNeverUsed(): void
    {
        $entityId = (int) (new Entity())->add(['name' => 'PHPUnit entité SITE_B', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $entityId);
        // Catégorie « malware » non récursive d'une entité sœur : invisible depuis la racine.
        $hidden = $this->category('PHPUnit Malware SITE_B', 0, $entityId, 0);
        Config::setConfigurationValues(
            SecurityCategoryLink::CGA_CONTEXT,
            ['security_malware_itilcategories_id' => $hidden]
        );

        $this->assertSame(
            $this->categories['security'],
            SecurityCategoryLink::forIncident(0, 'malware', 0),
            'Repli sur la branche visible.'
        );
        (new ITILCategory())->delete(['id' => $hidden], true);
        (new Entity())->delete(['id' => $entityId], true);
    }
}
