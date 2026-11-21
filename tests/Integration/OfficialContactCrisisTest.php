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

use Document_Item;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\CrisisCommunication;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisMessageRecipient;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\OfficialContactNotification;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as Rules;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as CommunicationRules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #771, lot 3 : contacts officiels proposés selon le type d'incident, communication de crise
 * reprise comme prise de contact, registre archivé sur A.5.5 / A.5.6, contacts dans le plan de
 * continuité et la salle de crise. Données fictives.
 */
final class OfficialContactCrisisTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function contact(array $input = []): ContextItem
    {
        $item = new ContextItem();
        $id = (int) $item->add($input + [
            'kind'                  => ContextItemRules::KIND_PARTY,
            'name'                  => 'PHPUnit CSIRT fictif',
            'party_type'            => 'authority',
            'is_official_contact'   => 1,
            'contact_category'      => 'csirt',
            'contact_details'       => 'alerte@csirt.example',
            'contact_delay_hours'   => 24,
            'on_security_incident'  => 1,
            'contact_verified_date' => date('Y-m-d'),
            'is_recursive'          => 1,
            'entities_id'           => 0,
        ]);
        $this->assertGreaterThan(0, $id, 'Contact fictif enregistré.');
        $item->getFromDB($id);

        return $item;
    }

    private function incident(string $category, string $date = '2026-03-10 09:30:00'): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add([
            'name'        => 'PHPUnit incident fictif ' . $category,
            'content'     => 'Incident fictif',
            'entities_id' => 0,
            'category'    => $category,
            'date'        => $date,
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    /**
     * @param list<ContextItem> $contacts
     * @return list<int> identifiants proposés parmi ces contacts
     */
    private static function proposed(PluginGrcmanagerSecurityIncident $incident, array $contacts): array
    {
        $ids = array_map(static fn (ContextItem $c): int => (int) $c->getID(), $contacts);
        $found = array_map('intval', array_column(OfficialContact::concernedBy($incident), 'id'));

        return array_values(array_intersect($ids, $found));
    }

    public function testIncidentTypesAreStoredFromTheForm(): void
    {
        $contact = $this->contact(['contact_incident_categories' => ['malware', '<script>', 'availability']]);
        $this->assertSame('["malware","availability"]', $contact->fields['contact_incident_categories']);

        // Liste vidée : absente du formulaire, seul le marqueur est envoyé.
        $this->assertTrue($contact->update([
            'id' => $contact->getID(), ContextItem::INCIDENT_CATEGORIES_MARKER => 1,
        ]));
        $contact->getFromDB($contact->getID());
        $this->assertSame('', (string) $contact->fields['contact_incident_categories']);
    }

    public function testContactsAreProposedByTypeOfIncident(): void
    {
        $malwareOnly = $this->contact([
            'name' => 'PHPUnit CERT sectoriel fictif', 'contact_incident_categories' => ['malware'],
        ]);
        $everyIncident = $this->contact(['name' => 'PHPUnit police fictive']);
        $breachOnly = $this->contact([
            'name' => 'PHPUnit autorité fictive', 'contact_category' => 'supervisory_authority',
            'on_security_incident' => 0, 'on_data_breach' => 1,
        ]);
        $all = [$malwareOnly, $everyIncident, $breachOnly];

        $this->assertSame(
            [$malwareOnly->getID(), $everyIncident->getID()],
            self::proposed($this->incident('malware'), $all)
        );
        $this->assertSame([$everyIncident->getID()], self::proposed($this->incident('availability'), $all));
        $this->assertSame(
            [$everyIncident->getID(), $breachOnly->getID()],
            self::proposed($this->incident('data_breach'), $all),
            'Un incident « violation de données » propose aussi les contacts des violations.'
        );

        $row = OfficialContact::present($malwareOnly->fields, date('Y-m-d'));
        $this->assertSame([PluginGrcmanagerSecurityIncident::getCategories()['malware']], $row['incident_types']);
    }

    public function testCrisisCommunicationToAnOfficialContactIsRecordedAsAContact(): void
    {
        $contact = $this->contact();
        $template = new CrisisMessageTemplate();
        $templateId = (int) $template->add([
            'name'      => 'PHPUnit modèle fictif',
            'situation' => CommunicationRules::SITUATION_RANSOMWARE,
            'stage'     => 'first',
            'audience'  => 'external',
            'subject'   => 'Signalement',
            'body'      => 'Texte',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $templateId);
        $this->assertTrue(
            CrisisMessageRecipient::addTo($templateId, CommunicationRules::RECIPIENT_CONTACT, $contact->getID())
        );
        $incident = $this->incident('malware');
        $input = [
            CrisisMessageRecipient::TEMPLATE_FK => $templateId,
            'subject'            => 'PHPUnit signalement au CSIRT',
            'body'               => 'Texte envoyé à la main.',
            'recipients'         => [
                CommunicationRules::recipientKey(CommunicationRules::RECIPIENT_CONTACT, $contact->getID()),
            ],
            'date_communication' => '2026-03-10T15:00',
        ];

        // Case décochée : la communication seule.
        $first = CrisisCommunication::record($incident, $input);
        $this->assertIsInt($first);
        $this->assertSame(0, OfficialContactNotification::countForCommunication($first));

        $second = CrisisCommunication::record($incident, $input + ['record_official_contacts' => 1]);
        $this->assertIsInt($second);
        $this->assertSame(1, OfficialContactNotification::countForCommunication($second));
        $traces = OfficialContactNotification::tracesFor($incident);
        $this->assertCount(1, $traces);
        $this->assertSame(Rules::DECISION_NOTIFIED, $traces[0]['decision']);
        $this->assertSame('2026-03-10 15:00:00', $traces[0]['date']);
        $this->assertTrue($traces[0]['from_communication']);
        $this->assertStringContainsString('PHPUnit signalement au CSIRT', $traces[0]['comment']);
        $notices = OfficialContactNotification::noticesFor($incident, [$contact->fields], '2026-11-20 10:00:00');
        $this->assertSame(Rules::NOTICE_ON_TIME, $notices[$contact->getID()]['state']);

        // Une communication ne trace qu'une fois chaque contact ; date avant l'incident : rien.
        $this->assertSame(0, OfficialContactNotification::recordFromCommunication(
            $incident,
            $second,
            [$contact->getID()],
            '2026-03-10 15:00:00',
            'Doublon'
        ));
        $this->assertSame(0, OfficialContactNotification::recordFromCommunication(
            $incident,
            $second + 1000000,
            [$contact->getID()],
            '2026-03-01 08:00:00',
            'Avant le début'
        ));
    }

    public function testArchiveAttachesTheRegisterToControlsA55AndA56(): void
    {
        $this->contact();
        $controlIds = OfficialContact::controlIds();
        $this->assertNotSame([], $controlIds, 'Mesures A.5.5 et A.5.6 de la SoA suivie.');

        $documentsId = OfficialContact::archive();
        $this->assertGreaterThan(0, $documentsId);
        foreach ($controlIds as $controlId) {
            $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
                'documents_id' => $documentsId, 'itemtype' => Control::class, 'items_id' => $controlId,
            ]));
        }
        $this->assertContains(
            $documentsId,
            array_map(
                static fn (array $a): int => (int) substr($a['url'], (int) strrpos($a['url'], '=') + 1),
                OfficialContact::recentArchives()
            )
        );
    }

    public function testArchiveRequiresTheRightToUpdateTheContext(): void
    {
        $rights = $_SESSION['glpiactiveprofile'][ContextItem::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][ContextItem::$rightname] = READ;
        try {
            $this->assertFalse(OfficialContact::canArchive());
            $this->assertSame(0, OfficialContact::archive());
        } finally {
            $_SESSION['glpiactiveprofile'][ContextItem::$rightname] = $rights;
        }
    }

    public function testContinuityPlanListsTheOfficialContacts(): void
    {
        $this->contact(['name' => 'PHPUnit assureur cyber fictif', 'contact_category' => 'insurer']);
        $html = BusinessProcess::renderContinuityReport();
        $this->assertStringContainsString('PHPUnit assureur cyber fictif', $html);
        $this->assertStringContainsString('(A.5.5, A.5.6)', $html);
    }

    public function testCrisisRoomShowsTheContactsToInform(): void
    {
        $contact = $this->contact(['name' => 'PHPUnit CSIRT de la salle de crise']);
        $exerciseId = CrisisExercise::launch('ransomware');
        $this->assertGreaterThan(0, $exerciseId);
        $exercise = new CrisisExercise();
        $exercise->getFromDB($exerciseId);
        $data = $exercise->collect();
        $this->assertContains($contact->getID(), array_column($data['official_contacts']['contacts'], 'id'));
        $this->assertStringContainsString('forcetab=', $data['official_contacts']['url']);
    }
}
