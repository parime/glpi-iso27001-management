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
use GlpiPlugin\Grcmanager\CrisisCommunication;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisMessageRecipient;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\SecurityIncidentPostMortem;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as Rules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #616 : communication de crise (modèles, listes de diffusion, communications tracées depuis
 * un incident ou une violation). Données fictives.
 */
final class CrisisCommunicationTest extends GrcmanagerIntegrationTestCase
{
    use ForcesEnglishLanguage;

    /**
     * @param array<string, mixed> $input
     */
    private function template(array $input = []): CrisisMessageTemplate
    {
        $template = new CrisisMessageTemplate();
        $id = (int) $template->add($input + [
            'name'      => 'PHPUnit modèle fictif',
            'situation' => Rules::SITUATION_RANSOMWARE,
            'stage'     => 'first',
            'audience'  => 'internal',
            'subject'   => '[{reference}] Attaque en cours : {title}',
            'body'      => "Systèmes touchés : {affected_systems}\nDétecté le {detection_date}\n"
                . 'Prochain point : {next_update}',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $id);
        $template->getFromDB($id);

        return $template;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function contact(array $input = []): int
    {
        $id = (int) (new ContextItem())->add($input + [
            'kind'                => ContextItemRules::KIND_PARTY,
            'name'                => 'PHPUnit assureur cyber fictif',
            'party_type'          => 'other',
            'is_official_contact' => 1,
            'contact_category'    => 'insurer',
            'contact_details'     => "sinistres@assureur.example\n01 00 00 00 00",
            'entities_id'         => 0,
            'is_recursive'        => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function incident(
        int $entityId = 0,
        string $name = 'PHPUnit chiffrement du serveur'
    ): PluginGrcmanagerSecurityIncident {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add([
            'name'        => $name,
            'content'     => 'Incident fictif',
            'entities_id' => $entityId,
            'category'    => 'malware',
            'severity'    => 'critical',
            'date'        => '2026-03-10 09:30:00',
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimalInput(int $templateId): array
    {
        return [
            CrisisMessageRecipient::TEMPLATE_FK => $templateId,
            'subject'                           => 'PHPUnit objet',
            'body'                              => 'PHPUnit texte',
            'other_recipients'                  => 'Direction fictive',
        ];
    }

    public function testDefaultTemplatesCoverTheFourSituations(): void
    {
        $situations = array_column(CrisisMessageTemplate::activeTemplates(), 'situation');
        foreach (
            [
                Rules::SITUATION_MAJOR_INCIDENT, Rules::SITUATION_DATA_BREACH, Rules::SITUATION_UNAVAILABILITY,
                Rules::SITUATION_RANSOMWARE,
            ] as $situation
        ) {
            $this->assertContains($situation, $situations, 'Modèle fourni : ' . $situation);
        }
        $this->assertSame(0, CrisisMessageTemplate::seedDefaults(), 'Jamais une seconde fois.');
    }

    public function testTemplateInputIsNormalized(): void
    {
        $template = $this->template([
            'situation' => '<b>x</b>', 'stage' => 'later', 'subject' => "Objet\nBcc: x@example.com",
        ]);
        $this->assertSame(Rules::SITUATION_MAJOR_INCIDENT, $template->fields['situation']);
        $this->assertSame('first', $template->fields['stage']);
        $this->assertSame('Objet Bcc: x@example.com', $template->fields['subject']);
        $this->assertFalse((new CrisisMessageTemplate())->add(['name' => '  ']));
    }

    public function testDistributionListMixesInternalAndOfficialContactsAndFlagsMissingAddresses(): void
    {
        $template = $this->template();
        $userId = $this->createTestUser('Alice', 'Fictive');
        $groupId = (int) (new \Group())->add([
            'name' => 'PHPUnit cellule de crise', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $withDetails = $this->contact();
        $withoutDetails = $this->contact(['name' => 'PHPUnit avocat sans coordonnées', 'contact_details' => '']);
        $notOfficial = $this->contact(['name' => 'PHPUnit partie ordinaire', 'is_official_contact' => 0]);
        $id = $template->getID();

        $this->assertTrue(CrisisMessageRecipient::addTo($id, 'User', $userId));
        $this->assertTrue(CrisisMessageRecipient::addTo($id, 'Group', $groupId));
        $this->assertTrue(CrisisMessageRecipient::addTo($id, Rules::RECIPIENT_CONTACT, $withDetails));
        $this->assertTrue(CrisisMessageRecipient::addTo($id, Rules::RECIPIENT_CONTACT, $withoutDetails));
        $this->assertTrue(CrisisMessageRecipient::addTo($id, 'User', $userId), 'Doublon sans effet.');
        $this->assertFalse(CrisisMessageRecipient::addTo($id, Rules::RECIPIENT_CONTACT, $notOfficial));
        $this->assertFalse(CrisisMessageRecipient::addTo($id, 'Computer', 1), 'Type refusé.');
        $this->assertCount(4, CrisisMessageRecipient::rowsFor($id));

        $list = CrisisMessageRecipient::listFor($id, null);
        $byName = array_column($list['recipients'], null, 'name');
        $this->assertFalse($byName['PHPUnit assureur cyber fictif']['internal']);
        $this->assertFalse($byName['PHPUnit assureur cyber fictif']['missing_address']);
        $this->assertTrue($byName['PHPUnit avocat sans coordonnées']['missing_address'], 'Contact sans adresse.');
        $this->assertTrue($byName['PHPUnit cellule de crise']['missing_address'], 'Groupe sans membre.');
        $users = array_values(array_filter($list['recipients'], static fn (array $r): bool => $r['type'] === 'User'));
        $this->assertTrue($users[0]['missing_address'], 'Utilisateur sans courriel signalé.');

        ob_start();
        $this->assertTrue(CrisisMessageRecipient::displayTabContentForItem($template));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('PHPUnit avocat sans coordonnées', $html);
    }

    public function testRecipientsFollowTheEntityOfTheIncidentAndTheRightOfTheContext(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale crise');
        $siblingId = $this->createTestEntity(0, 'PHPUnit autre filiale crise');
        $template = $this->template();
        $root = $this->contact(['name' => 'PHPUnit CSIRT siège']);
        $sibling = $this->contact(['name' => 'PHPUnit presse autre filiale', 'entities_id' => $siblingId]);
        $this->assertTrue(CrisisMessageRecipient::addTo($template->getID(), Rules::RECIPIENT_CONTACT, $root));
        $this->assertTrue(CrisisMessageRecipient::addTo($template->getID(), Rules::RECIPIENT_CONTACT, $sibling));

        $incident = $this->incident($childId);
        $list = CrisisMessageRecipient::listFor($template->getID(), $childId);
        $names = array_column($list['recipients'], 'name');
        $this->assertContains('PHPUnit CSIRT siège', $names, 'Contact récursif du siège.');
        $this->assertNotContains('PHPUnit presse autre filiale', $names, 'Jamais une autre entité.');
        $this->assertSame(1, $list['hidden']);

        // Sans le droit du contexte : aucun contact externe, seulement compté.
        $saved = $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = 0;
            $list = CrisisMessageRecipient::listFor($template->getID(), $childId);
            $this->assertSame([], $list['recipients']);
            $this->assertSame(2, $list['hidden']);
            ob_start();
            CrisisCommunication::displayTabContentForItem($incident);
            $this->assertStringNotContainsString('PHPUnit CSIRT siège', (string) ob_get_clean());
        } finally {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = $saved;
        }
    }

    public function testMessageIsFilledFromTheIncidentAndEscaped(): void
    {
        $template = $this->template();
        $incident = $this->incident(0, 'PHPUnit <script>alert("x")</script> serveur');
        $computerId = (int) (new \Computer())->add(['name' => 'PHPUnit SRV-FICHIERS', 'entities_id' => 0]);
        (new \PluginGrcmanagerSecurityIncident_Item())->add([
            'plugin_grcmanager_securityincidents_id' => $incident->getID(),
            'itemtype'                               => \Computer::class,
            'items_id'                               => $computerId,
        ]);
        $incident->getFromDB($incident->getID());

        $messages = CrisisCommunication::prepared($incident);
        $this->assertTrue($messages[0]['recommended'], 'Modèle de la situation (rançongiciel) en premier.');
        $mine = array_values(array_filter(
            $messages,
            static fn (array $m): bool => $m['id'] === $template->getID()
        ))[0];
        $reference = (string) $incident->fields['reference'];
        $this->assertNotSame('', $reference);
        $this->assertStringStartsWith('[' . $reference . '] Attaque en cours : PHPUnit <script>', $mine['subject']);
        $this->assertStringContainsString('Systèmes touchés : PHPUnit SRV-FICHIERS', $mine['body']);
        $this->assertStringContainsString('[to be completed]', $mine['body']);

        ob_start();
        $this->assertTrue(CrisisCommunication::displayTabContentForItem($incident));
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('<script>alert("x")', $html, 'Variable échappée.');
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRecordingKeepsOnlyOfferedRecipientsWritesTheJournalAndSendsNothing(): void
    {
        global $DB;

        $template = $this->template();
        $contact = $this->contact();
        $this->assertTrue(CrisisMessageRecipient::addTo($template->getID(), Rules::RECIPIENT_CONTACT, $contact));
        $incident = $this->incident();
        $queued = countElementsInTable('glpi_queuednotifications');

        $id = CrisisCommunication::record($incident, [
            CrisisMessageRecipient::TEMPLATE_FK => $template->getID(),
            'subject'            => 'PHPUnit premier message',
            'body'               => 'Texte envoyé à la main.',
            'recipients'         => [Rules::recipientKey(Rules::RECIPIENT_CONTACT, $contact), 'User:999999'],
            'other_recipients'   => 'Presse régionale fictive',
            'date_communication' => '2026-03-10T10:15',
        ]);
        $this->assertIsInt($id);
        $row = $DB->request(['FROM' => CrisisCommunication::getTable(), 'WHERE' => ['id' => $id]])->current();
        $this->assertNotNull($row);
        $this->assertSame('2026-03-10 10:15:00', $row['date_communication']);
        $this->assertSame(2, (int) $row['users_id']);
        $this->assertStringContainsString('PHPUnit assureur cyber fictif', (string) $row['recipients']);
        $this->assertStringContainsString('Presse régionale fictive', (string) $row['recipients']);
        $this->assertStringNotContainsString('999999', (string) $row['recipients'], 'Destinataire non proposé.');
        $this->assertSame((int) $incident->fields['entities_id'], (int) $row['entities_id']);

        $followups = (new \ITILFollowup())->find([
            'itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $incident->getID(),
        ]);
        $this->assertCount(1, $followups, 'Ligne du journal de crise.');
        $followup = array_values($followups)[0];
        $this->assertSame(1, (int) $followup['is_private']);
        $this->assertStringContainsString('PHPUnit premier message', (string) $followup['content']);
        $this->assertSame($queued, countElementsInTable('glpi_queuednotifications'), 'Aucun courriel envoyé.');

        $history = CrisisCommunication::historyFor($incident);
        $this->assertSame('PHPUnit premier message', $history[0]['subject']);
        $this->assertNotSame('', (new CrisisCommunication())->getTabNameForItem($incident));

        $timeline = array_column(SecurityIncidentPostMortem::collect($incident, true)['timeline'], 'text');
        $this->assertNotEmpty(array_filter(
            $timeline,
            static fn ($text): bool => str_contains((string) $text, 'PHPUnit premier message')
        ), 'Repris dans le rapport post-incident.');

        // Refus : sans destinataire, date future, modèle inactif.
        $base = [CrisisMessageRecipient::TEMPLATE_FK => $template->getID(), 'subject' => 'x', 'body' => 'y'];
        $this->assertIsString(CrisisCommunication::record($incident, $base));
        $this->assertIsString(CrisisCommunication::record($incident, $base + [
            'other_recipients' => 'Direction', 'date_communication' => '2999-01-01T10:00',
        ]));
        $this->assertTrue($template->update(['id' => $template->getID(), 'is_active' => 0]));
        $this->assertIsString(CrisisCommunication::record($incident, $base + ['other_recipients' => 'Direction']));
    }

    public function testRightsOfTheIncidentAreRequired(): void
    {
        $template = $this->template();
        $incident = $this->incident();
        $right = PluginGrcmanagerSecurityIncident::RIGHTNAME;
        $saved = $_SESSION['glpiactiveprofile'][$right];
        try {
            // Lecture seule : l'onglet s'affiche, la trace est refusée.
            $_SESSION['glpiactiveprofile'][$right] = PluginGrcmanagerSecurityIncident::READALL;
            $this->assertIsString(CrisisCommunication::record($incident, self::minimalInput($template->getID())));
            ob_start();
            $this->assertTrue(CrisisCommunication::displayTabContentForItem($incident));
            $this->assertStringNotContainsString('name="record"', (string) ob_get_clean());

            // Aucun droit : ni onglet ni contenu.
            $_SESSION['glpiactiveprofile'][$right] = 0;
            $this->assertSame('', (new CrisisCommunication())->getTabNameForItem($incident));
            ob_start();
            $this->assertFalse(CrisisCommunication::displayTabContentForItem($incident));
            $this->assertSame('', (string) ob_get_clean());
        } finally {
            $_SESSION['glpiactiveprofile'][$right] = $saved;
        }
        $this->assertIsInt(CrisisCommunication::record($incident, self::minimalInput($template->getID())));
    }

    public function testIncidentOfAnotherEntityIsRefused(): void
    {
        $otherId = $this->createTestEntity(0, 'PHPUnit entité hors session');
        $template = $this->template();
        $incident = $this->incident($otherId);
        $saved = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        try {
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $this->assertIsString(CrisisCommunication::record($incident, self::minimalInput($template->getID())));
            ob_start();
            $shown = CrisisCommunication::displayTabContentForItem($incident);
            ob_end_clean();
            $this->assertFalse($shown);
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $saved;
        }
    }

    public function testBreachMessagesUseTheBreachAndItsIncident(): void
    {
        $incident = $this->incident(0, 'PHPUnit fuite du fichier clients');
        $breach = new DataBreach();
        $breachId = (int) $breach->add([
            'entities_id'           => 0,
            'name'                  => 'PHPUnit fichier clients publié',
            'date_awareness'        => '2026-03-11 08:00',
            DataBreach::INCIDENT_FK => $incident->getID(),
        ]);
        $this->assertGreaterThan(0, $breachId);
        $breach->getFromDB($breachId);

        $messages = CrisisCommunication::prepared($breach);
        $first = new CrisisMessageTemplate();
        $this->assertTrue($first->getFromDB($messages[0]['id']));
        $this->assertSame(Rules::SITUATION_DATA_BREACH, $first->fields['situation'], 'Modèle de violation en premier.');
        $values = CrisisCommunication::valuesFor($breach);
        $this->assertSame((string) $incident->fields['reference'], $values['reference']);
        $this->assertSame('PHPUnit fichier clients publié', $values['title']);
        $this->assertNotSame('', (new CrisisCommunication())->getTabNameForItem($breach));

        $this->assertIsInt(CrisisCommunication::record($breach, self::minimalInput($messages[0]['id'])));
        $this->assertCount(1, CrisisCommunication::historyFor($breach));
        $this->assertCount(0, CrisisCommunication::historyFor($incident), 'Trace propre à la violation.');
    }

    public function testEvidenceOnControlA526AndClause74(): void
    {
        $evidence = CrisisCommunication::evidence('A.5.26');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(4, $evidence['templates']);
        $this->assertSame([], $evidence['missing_situations']);
        $this->assertNotNull(CrisisCommunication::clauseReadiness('7.4'));
        $this->assertNull(CrisisCommunication::evidence('A.5.25'));
        $this->assertNull(CrisisCommunication::clauseReadiness('7.3'));

        $before = (int) $evidence['communications'];
        $templateId = $this->template()->getID();
        $this->assertIsInt(CrisisCommunication::record($this->incident(), self::minimalInput($templateId)));
        $after = CrisisCommunication::evidence('A.5.26');
        $this->assertNotNull($after);
        $this->assertSame($before + 1, $after['communications']);

        $saved = $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME] = 0;
            $this->assertNull(CrisisCommunication::evidence('A.5.26'));
        } finally {
            $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME] = $saved;
        }
    }

    private function userWithEmail(string $firstname, string $email): int
    {
        $userId = $this->createTestUser($firstname, 'Fictive');
        $this->assertGreaterThan(0, (int) (new \UserEmail())->add([
            'users_id' => $userId, 'email' => $email, 'is_default' => 1,
        ]));

        return $userId;
    }

    public function testDeputyFollowsItsMainRecipientAndIsTickedOnlyWhenNeeded(): void
    {
        $template = $this->template();
        $id = $template->getID();
        $mainUser = $this->userWithEmail('Directrice', 'directrice@example.com');
        $deputyUser = $this->userWithEmail('Adjoint', 'adjoint@example.com');
        $contactWithout = $this->contact(['name' => 'PHPUnit avocat injoignable', 'contact_details' => '']);
        $contactDeputy = $this->contact(['name' => 'PHPUnit cabinet suppléant']);

        $rowOf = static function (string $itemtype, int $itemsId) use ($id): int {
            foreach (CrisisMessageRecipient::rowsFor($id) as $row) {
                if ($row['itemtype'] === $itemtype && (int) $row['items_id'] === $itemsId) {
                    return (int) $row['id'];
                }
            }

            return 0;
        };
        $this->assertTrue(CrisisMessageRecipient::addTo($id, 'User', $mainUser));
        $mainRow = $rowOf('User', $mainUser);
        $this->assertTrue(CrisisMessageRecipient::addTo($id, 'User', $deputyUser, $mainRow));
        $this->assertTrue(CrisisMessageRecipient::addTo($id, Rules::RECIPIENT_CONTACT, $contactWithout));
        $contactRow = $rowOf(Rules::RECIPIENT_CONTACT, $contactWithout);
        $this->assertTrue(CrisisMessageRecipient::addTo($id, Rules::RECIPIENT_CONTACT, $contactDeputy, $contactRow));
        $deputyRow = $rowOf('User', $deputyUser);
        $this->assertFalse(
            CrisisMessageRecipient::addTo($id, 'User', $this->createTestUser('Tiers', 'Fictif'), $deputyRow),
            'Jamais le suppléant d\'un suppléant.'
        );
        $other = $this->template(['name' => 'PHPUnit autre modèle']);
        $this->assertFalse(
            CrisisMessageRecipient::addTo($other->getID(), 'User', $deputyUser, $mainRow),
            'Le principal doit être dans la même liste.'
        );

        // Contacts officiels puis utilisateurs (ordre du type), chaque suppléant après son principal.
        $list = CrisisMessageRecipient::listFor($id, null);
        $this->assertSame(
            [$contactWithout, $contactDeputy, $mainUser, $deputyUser],
            array_map(static fn (array $r): int => (int) substr(strrchr($r['key'], ':'), 1), $list['recipients']),
            'Chaque suppléant juste après son principal.'
        );
        $this->assertSame(0, $list['without_deputy']);
        $this->assertSame($mainRow, $list['recipients'][3]['deputy_of']);
        $this->assertTrue($list['recipients'][3]['principal_available'], 'Principal joignable.');
        $this->assertFalse($list['recipients'][1]['principal_available'], 'Principal sans adresse.');
        $this->assertTrue($list['recipients'][2]['has_deputy']);
        $this->assertSame('PHPUnit avocat injoignable', $list['recipients'][1]['principal']);

        ob_start();
        CrisisCommunication::displayTabContentForItem($this->incident());
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/value="User:' . $deputyUser . '" data-address="[^"]*" >/', $html);
        $this->assertMatchesRegularExpression(
            '/value="' . preg_quote(Rules::RECIPIENT_CONTACT, '/') . ':' . $contactDeputy
                . '" data-address="[^"]*" checked>/',
            $html
        );

        // La trace nomme le suppléant et son principal.
        $incident = $this->incident();
        $traceId = CrisisCommunication::record($incident, self::minimalInput($id) + [
            'recipients' => [Rules::recipientKey('User', $deputyUser)],
        ]);
        $this->assertIsInt($traceId);
        $this->assertStringContainsString(
            (string) getUserName($mainUser),
            implode("\n", CrisisCommunication::historyFor($incident)[0]['recipients'])
        );

        // Retirer le principal : son suppléant devient principal.
        $this->assertTrue((new CrisisMessageRecipient())->delete(['id' => $mainRow], true));
        $list = CrisisMessageRecipient::listFor($id, null);
        $this->assertCount(3, $list['recipients']);
        $this->assertSame(0, $list['recipients'][2]['deputy_of']);
        $this->assertSame(1, $list['without_deputy']);
    }

    public function testGroupMembersAddressesAreIncluded(): void
    {
        $template = $this->template();
        $groupId = (int) (new \Group())->add([
            'name' => 'PHPUnit direction fictive', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $members = [$this->userWithEmail('Membre', 'membre@example.com'), $this->createTestUser('Sans', 'Courriel')];
        foreach ($members as $user) {
            $this->assertGreaterThan(0, (int) (new \Group_User())->add(['groups_id' => $groupId, 'users_id' => $user]));
        }
        $this->assertTrue(CrisisMessageRecipient::addTo($template->getID(), 'Group', $groupId));

        $group = CrisisMessageRecipient::listFor($template->getID(), null)['recipients'][0];
        $this->assertSame('membre@example.com', $group['emails']);
        $this->assertSame(1, $group['without_email']);
        $this->assertFalse($group['missing_address']);
        $this->assertSame(0, CrisisMessageRecipient::listFor($template->getID(), null)['without_deputy'], 'Groupe.');
    }

    public function testDistributionListReviewIsRecordedAndShownAsEvidence(): void
    {
        $template = $this->template();
        $this->assertSame('never', $template->reviewState()['status']);
        $before = CrisisCommunication::evidence('A.5.26');
        $this->assertNotNull($before);
        $this->assertGreaterThanOrEqual(1, $before['review_due']);

        $this->assertTrue($template->markReviewed());
        $template->getFromDB($template->getID());
        $state = $template->reviewState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame(date('Y-m-d'), $state['date']);
        $this->assertNotSame('', $state['reviewer']);
        $this->assertSame('overdue', $template->reviewState(date('Y-m-d', strtotime('+13 months')))['status']);
        $after = CrisisCommunication::evidence('A.5.26');
        $this->assertNotNull($after);
        $this->assertSame($before['review_due'] - 1, $after['review_due']);

        ob_start();
        CrisisMessageRecipient::displayTabContentForItem($template);
        $this->assertStringContainsString('name="review"', (string) ob_get_clean());

        $saved = $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME] = READ;
            $this->assertFalse($template->markReviewed(), 'Lecture seule : revue refusée.');
            $this->assertFalse(
                CrisisMessageRecipient::addTo($template->getID(), 'User', 2),
                'Lecture seule : ajout refusé.'
            );
            ob_start();
            CrisisMessageRecipient::displayTabContentForItem($template);
            $this->assertStringNotContainsString('name="review"', (string) ob_get_clean(), 'Pas de bouton.');
        } finally {
            $_SESSION['glpiactiveprofile'][CrisisMessageTemplate::RIGHTNAME] = $saved;
        }
    }

    public function testCommunicationsAppearInTheCrisisExerciseReport(): void
    {
        $exerciseId = CrisisExercise::launch('outage');
        $this->assertGreaterThan(0, $exerciseId);
        $exercise = new CrisisExercise();
        $this->assertTrue($exercise->getFromDB($exerciseId));
        $incident = $exercise->getIncident();
        $this->assertNotNull($incident);
        $templateId = $this->template()->getID();
        $this->assertIsInt(CrisisCommunication::record($incident, self::minimalInput($templateId)));

        $data = $exercise->collect();
        $this->assertCount(1, $data['communications']);
        $this->assertSame('PHPUnit objet', $data['communications'][0]['subject']);
        $this->assertStringContainsString('forcetab=', $data['communications_url']);

        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/crisis_exercise_report.html.twig',
            ['journal' => []] + $data + ['report_title' => 'PHPUnit', 'generated_at_label' => '']
        );
        $this->assertStringContainsString('PHPUnit objet', $html, 'Repris dans le rapport d\'exercice.');
        $this->assertStringContainsString('Direction fictive', $html);
    }
}
