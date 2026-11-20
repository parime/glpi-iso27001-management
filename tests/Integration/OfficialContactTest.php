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

use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #771 : registre des contacts officiels (A.5.5, A.5.6), porté par les parties intéressées
 * du contexte de l'organisation. Données fictives.
 */
final class OfficialContactTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function contact(array $input = []): ContextItem
    {
        $item = new ContextItem();
        $id = (int) $item->add($input + [
            'kind'                 => ContextItemRules::KIND_PARTY,
            'name'                 => 'PHPUnit autorité de contrôle fictive',
            'party_type'           => 'authority',
            'is_official_contact'  => 1,
            'contact_category'     => 'supervisory_authority',
            'contact_details'      => "Téléphone : 01 00 00 00 00\nFormulaire en ligne",
            'when_to_contact'      => 'Violation de données à caractère personnel',
            'contact_delay'        => '72 heures',
            'on_data_breach'       => 1,
            'contact_verified_date' => date('Y-m-d'),
            'entities_id'          => 0,
        ]);
        $this->assertGreaterThan(0, $id, 'Contact fictif enregistré.');
        $item->getFromDB($id);

        return $item;
    }

    private function incident(int $entityId = 0): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add([
            'name'        => 'PHPUnit rançongiciel fictif',
            'content'     => 'Incident fictif',
            'entities_id' => $entityId,
            'date'        => '2026-03-10 09:30:00',
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    /** @return list<string> */
    private static function names(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function testContactIsStoredOnThePartyAndNormalized(): void
    {
        $contact = $this->contact([
            'contact_category' => '<b>x</b>', 'on_security_incident' => 'yes', 'users_id_contactverifier' => 999,
            'contact_reminder_date' => '2026-01-01 00:00:00',
        ]);
        $this->assertSame(1, (int) $contact->fields['is_official_contact']);
        $this->assertSame('other', $contact->fields['contact_category']);
        $this->assertSame(1, (int) $contact->fields['on_security_incident']);
        $verifier = (int) $contact->fields['users_id_contactverifier'];
        $this->assertSame(2, $verifier, 'Vérificateur : la personne connectée.');
        $this->assertNull($contact->fields['contact_reminder_date'], 'Date de relance jamais reprise de la saisie.');

        // Un enjeu n'est jamais un contact officiel.
        $issue = new ContextItem();
        $issueId = (int) $issue->add([
            'kind' => ContextItemRules::KIND_ISSUE, 'name' => 'PHPUnit enjeu', 'is_official_contact' => 1,
            'entities_id' => 0,
        ]);
        $issue->getFromDB($issueId);
        $this->assertSame(0, (int) $issue->fields['is_official_contact']);

        // Date future : refusée (jamais une vérification à venir).
        $this->assertTrue($contact->update(['id' => $contact->getID(), 'contact_verified_date' => '2999-01-01']));
        $contact->getFromDB($contact->getID());
        $this->assertNull($contact->fields['contact_verified_date']);
    }

    public function testVerificationButtonRecordsTodayAndTheUser(): void
    {
        $contact = $this->contact(['contact_verified_date' => '2024-01-01']);
        global $DB;
        $DB->update(ContextItem::getTable(), ['users_id_contactverifier' => 0], ['id' => $contact->getID()]);

        $this->assertTrue($contact->update([
            'id' => $contact->getID(), ContextItem::CONTACT_VERIFIED_MARKER => 1,
            'contact_verified_date' => '2020-01-01',
        ]));
        $contact->getFromDB($contact->getID());
        $verified = $contact->fields['contact_verified_date'];
        $this->assertSame(date('Y-m-d'), $verified, 'Le bouton l\'emporte sur la saisie.');
        $this->assertSame(2, (int) $contact->fields['users_id_contactverifier']);
    }

    public function testRegisterRespectsTheSessionEntities(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale contacts');
        $this->contact(['name' => 'PHPUnit CSIRT siège', 'contact_category' => 'csirt']);
        $this->contact([
            'name' => 'PHPUnit police filiale', 'contact_category' => 'law_enforcement', 'entities_id' => $childId,
        ]);
        $this->contact(['name' => 'PHPUnit partie ordinaire', 'is_official_contact' => 0]);

        $all = self::names(OfficialContact::visibleContacts());
        $this->assertContains('PHPUnit CSIRT siège', $all);
        $this->assertContains('PHPUnit police filiale', $all);
        $this->assertNotContains('PHPUnit partie ordinaire', $all, 'Partie non cochée : pas un contact officiel.');

        $saved = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        try {
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $this->assertNotContains('PHPUnit police filiale', self::names(OfficialContact::visibleContacts()));
            $this->assertStringNotContainsString('PHPUnit police filiale', OfficialContact::reportHtml());
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $saved;
        }
    }

    public function testNothingWithoutTheRightOfTheContext(): void
    {
        $this->contact();
        $saved = $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = 0;
            $this->assertSame([], OfficialContact::visibleContacts());
            $this->assertNull(OfficialContact::evidence('A.5.5'));
            $incident = $this->incident();
            $this->assertSame('', (new OfficialContact())->getTabNameForItem($incident));
            ob_start();
            $shown = OfficialContact::displayTabContentForItem($incident);
            $html = (string) ob_get_clean();
            $this->assertFalse($shown);
            $this->assertSame('', $html);
        } finally {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = $saved;
        }
    }

    public function testIncidentAndBreachShowTheirContactsFromTheirEntityAndParents(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale incident');
        $siblingId = $this->createTestEntity(0, 'PHPUnit autre filiale');
        $this->contact([
            'name' => 'PHPUnit ANSSI fictive', 'contact_category' => 'csirt', 'on_security_incident' => 1,
            'on_data_breach' => 0, 'is_recursive' => 1,
        ]);
        $this->contact([
            'name' => 'PHPUnit CNIL fictive', 'on_security_incident' => 0, 'on_data_breach' => 1, 'is_recursive' => 1,
        ]);
        $this->contact([
            'name' => 'PHPUnit gendarmerie autre filiale', 'contact_category' => 'law_enforcement',
            'on_security_incident' => 1, 'entities_id' => $siblingId,
        ]);
        $this->contact([
            'name' => 'PHPUnit siège non récursif', 'on_security_incident' => 1, 'is_recursive' => 0,
        ]);

        $incident = $this->incident($childId);
        $names = self::names(OfficialContact::concernedBy($incident));
        $this->assertContains('PHPUnit ANSSI fictive', $names, 'Contact récursif du siège.');
        $this->assertNotContains('PHPUnit CNIL fictive', $names, 'Contact réservé aux violations.');
        $this->assertNotContains('PHPUnit gendarmerie autre filiale', $names, 'Jamais une autre entité.');
        $this->assertNotContains('PHPUnit siège non récursif', $names);

        ob_start();
        $this->assertTrue(OfficialContact::displayTabContentForItem($incident));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('PHPUnit ANSSI fictive', $html);
        $this->assertStringNotContainsString('PHPUnit gendarmerie autre filiale', $html);

        $breach = new DataBreach();
        $breachId = (int) $breach->add(['entities_id' => $childId, 'date_awareness' => '2026-03-10 09:30']);
        $this->assertGreaterThan(0, $breachId);
        $breach->getFromDB($breachId);
        $breachNames = self::names(OfficialContact::concernedBy($breach));
        $this->assertSame(['PHPUnit CNIL fictive'], $breachNames);
        $this->assertNotSame('', (new OfficialContact())->getTabNameForItem($breach));
    }

    public function testEvidenceOnControlsA55AndA56(): void
    {
        $before = OfficialContact::evidence('A.5.5');
        $this->assertNotNull($before);
        $this->contact(['name' => 'PHPUnit CNIL à vérifier', 'contact_verified_date' => '2020-01-01']);
        $this->contact(['name' => 'PHPUnit CLUSIF fictif', 'contact_category' => 'interest_group']);

        $after = OfficialContact::evidence('A.5.5');
        $this->assertNotNull($after);
        $this->assertSame($before['total'] + 1, $after['total']);
        $this->assertSame($before['to_verify'] + 1, $after['to_verify']);
        $groups = OfficialContact::evidence('A.5.6');
        $this->assertNotNull($groups);
        $this->assertGreaterThanOrEqual(1, $groups['verified']);
        $this->assertNull(OfficialContact::evidence('A.5.7'));
    }

    public function testReminderOncePerMonthForContactsNotVerifiedForTwelveMonths(): void
    {
        $late = $this->contact(['name' => 'PHPUnit préfecture à vérifier', 'contact_verified_date' => '2020-01-01']);
        $fresh = $this->contact(['name' => 'PHPUnit CSIRT vérifié']);

        $first = OfficialContact::sendReminders(date('Y-m-d'));
        $this->assertGreaterThanOrEqual(1, $first['due']);
        $late->getFromDB($late->getID());
        $fresh->getFromDB($fresh->getID());
        $this->assertNotNull($late->fields['contact_reminder_date']);
        $this->assertNull($fresh->fields['contact_reminder_date']);

        // Deuxième passage le même jour : plus de relance pour ce contact.
        $stamp = $late->fields['contact_reminder_date'];
        OfficialContact::sendReminders(date('Y-m-d'));
        $late->getFromDB($late->getID());
        $this->assertSame($stamp, $late->fields['contact_reminder_date']);

        // Vérification enregistrée : la relance repart de zéro.
        $late->update(['id' => $late->getID(), ContextItem::CONTACT_VERIFIED_MARKER => 1]);
        $late->getFromDB($late->getID());
        $this->assertNull($late->fields['contact_reminder_date']);
    }
}
