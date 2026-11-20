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

use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\OfficialContactNotification;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as Rules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #771, lot 2 : prises de contact tracées avec les contacts officiels depuis un incident de
 * sécurité ou une violation de données, échéance, historique du contact et preuve. Données fictives.
 */
final class OfficialContactNotificationTest extends GrcmanagerIntegrationTestCase
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
            'contact_details'       => 'Formulaire en ligne fictif',
            'contact_delay'         => '24 heures',
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

    private function incident(int $entityId = 0, string $date = '2026-03-10 09:30:00'): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add([
            'name'        => 'PHPUnit hameçonnage fictif',
            'content'     => 'Incident fictif',
            'entities_id' => $entityId,
            'date'        => $date,
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function input(ContextItem $contact, array $input = []): array
    {
        return $input + [
            OfficialContactNotification::CONTACT_FK => $contact->getID(),
            'decision'      => Rules::DECISION_NOTIFIED,
            'date_notified' => '2026-03-10T15:00',
            'reference'     => 'DOSSIER-FICTIF-001',
            'comment'       => 'Signalement par le formulaire en ligne',
        ];
    }

    public function testDelayHoursAreStoredOnTheContact(): void
    {
        $contact = $this->contact(['contact_delay_hours' => '-3']);
        $this->assertSame(0, (int) $contact->fields['contact_delay_hours']);
        $contact->update(['id' => $contact->getID(), 'contact_delay_hours' => '72']);
        $contact->getFromDB($contact->getID());
        $this->assertSame(72, (int) $contact->fields['contact_delay_hours']);
    }

    public function testRecordAContactOnTimeAndSeeItEverywhere(): void
    {
        $contact = $this->contact();
        $incident = $this->incident();

        $id = OfficialContactNotification::record($incident, self::input($contact), '2026-11-20 10:00:00');
        $this->assertIsInt($id, 'Prise de contact tracée.');
        $row = (new OfficialContactNotification());
        $row->getFromDB($id);
        $this->assertSame(2, (int) $row->fields['users_id'], 'Auteur : la personne connectée.');
        $this->assertSame('2026-03-10 15:00:00', $row->fields['date_notified']);
        $this->assertSame(0, (int) $row->fields['entities_id'], 'Entité de l\'incident.');

        $notices = OfficialContactNotification::noticesFor($incident, [$contact->fields], '2026-11-20 10:00:00');
        $this->assertSame(Rules::NOTICE_ON_TIME, $notices[$contact->getID()]['state']);
        $this->assertSame('2026-03-11 09:30:00', $notices[$contact->getID()]['deadline']);

        ob_start();
        $this->assertTrue(OfficialContact::displayTabContentForItem($incident));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('DOSSIER-FICTIF-001', $html);
        $this->assertStringContainsString('grcmanager-trace-contact-form', $html);

        $history = OfficialContactNotification::historyForContact($contact->getID());
        $this->assertCount(1, $history['entries']);
        $this->assertSame('PHPUnit hameçonnage fictif', $history['entries'][0]['item_name']);
        $this->assertNotSame('', (new OfficialContactNotification())->getTabNameForItem($contact));

        global $DB;
        $logs = $DB->request([
            'FROM'  => 'glpi_logs',
            'WHERE' => ['itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $incident->getID()],
        ]);
        $found = false;
        foreach ($logs as $log) {
            $found = $found || str_contains((string) $log['new_value'], 'PHPUnit CSIRT fictif');
        }
        $this->assertTrue($found, 'La prise de contact est inscrite dans l\'historique de l\'incident.');
    }

    public function testRefusals(): void
    {
        $contact = $this->contact();
        $incident = $this->incident();
        $now = '2026-11-20 10:00:00';

        $this->assertIsString(OfficialContactNotification::record(
            $incident,
            self::input($contact, ['decision' => Rules::DECISION_NOT_REQUIRED, 'comment' => '']),
            $now
        ), 'Décision de ne pas prévenir sans motif.');
        $this->assertIsString(OfficialContactNotification::record(
            $incident,
            self::input($contact, ['date_notified' => '2026-03-01T10:00']),
            $now
        ), 'Prévenu avant la détection de l\'incident.');
        $this->assertIsString(OfficialContactNotification::record(
            $incident,
            self::input($contact, ['date_notified' => '2999-01-01T10:00']),
            $now
        ), 'Date future.');
        $this->assertIsString(OfficialContactNotification::record(
            $incident,
            self::input($contact, [OfficialContactNotification::CONTACT_FK => 0]),
            $now
        ), 'Aucun contact.');

        $ordinary = $this->contact(['name' => 'PHPUnit partie ordinaire', 'is_official_contact' => 0]);
        $this->assertIsString(
            OfficialContactNotification::record($incident, self::input($ordinary), $now),
            'Une partie qui n\'est pas un contact officiel ne peut pas être tracée.'
        );
        $this->assertSame([], OfficialContactNotification::rowsFor($incident));

        $decision = OfficialContactNotification::record(
            $incident,
            self::input($contact, ['decision' => Rules::DECISION_NOT_REQUIRED, 'comment' => 'Aucun impact hors SI']),
            $now
        );
        $this->assertIsInt($decision);
        $notices = OfficialContactNotification::noticesFor($incident, [$contact->fields], $now);
        $this->assertSame(Rules::NOTICE_NOT_REQUIRED, $notices[$contact->getID()]['state']);
    }

    public function testNeverAContactOfASiblingEntity(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale A');
        $siblingId = $this->createTestEntity(0, 'PHPUnit filiale B');
        $sibling = $this->contact(['name' => 'PHPUnit police filiale B', 'entities_id' => $siblingId]);
        $incident = $this->incident($childId);

        $this->assertIsString(OfficialContactNotification::record($incident, self::input($sibling), '2026-11-20'));
        $this->assertSame([], OfficialContactNotification::rowsFor($incident));
    }

    public function testRecordingNeedsTheRightToUpdateTheIncident(): void
    {
        $contact = $this->contact();
        $incident = $this->incident();
        $right = PluginGrcmanagerSecurityIncident::$rightname;
        $saved = $_SESSION['glpiactiveprofile'][$right] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][$right] = READ | PluginGrcmanagerSecurityIncident::READALL;
            $this->assertIsString(OfficialContactNotification::record($incident, self::input($contact), '2026-11-20'));
            ob_start();
            OfficialContact::displayTabContentForItem($incident);
            $html = (string) ob_get_clean();
            $this->assertStringNotContainsString(
                'grcmanager-trace-contact-form',
                $html,
                'Pas de formulaire en lecture.'
            );
        } finally {
            $_SESSION['glpiactiveprofile'][$right] = $saved;
        }
        $this->assertSame([], OfficialContactNotification::rowsFor($incident));
    }

    public function testRemoveAndPurgeForgetTheTraces(): void
    {
        $contact = $this->contact();
        $incident = $this->incident();
        $first = OfficialContactNotification::record($incident, self::input($contact), '2026-11-20');
        $this->assertIsInt($first);
        $this->assertNull(OfficialContactNotification::remove($first));
        $this->assertSame([], OfficialContactNotification::rowsFor($incident));
        $this->assertIsString(OfficialContactNotification::remove($first), 'Déjà retirée.');

        $this->assertIsInt(OfficialContactNotification::record($incident, self::input($contact), '2026-11-20'));
        $this->assertTrue($incident->delete(['id' => $incident->getID()], true));
        $this->assertSame(0, countElementsInTable(
            OfficialContactNotification::getTable(),
            ['itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $incident->getID()]
        ));

        $breach = new DataBreach();
        $breachId = (int) $breach->add(['entities_id' => 0, 'date_awareness' => '2026-03-10 09:30']);
        $breach->getFromDB($breachId);
        $this->assertIsInt(OfficialContactNotification::record($breach, self::input($contact), '2026-11-20'));
        $this->assertTrue($contact->delete(['id' => $contact->getID()], true));
        $this->assertSame(0, countElementsInTable(
            OfficialContactNotification::getTable(),
            [OfficialContactNotification::CONTACT_FK => $contact->getID()]
        ));
    }

    public function testBreachDeadlineAndEvidenceOnControlA55(): void
    {
        $cnil = $this->contact([
            'name' => 'PHPUnit autorité de contrôle fictive', 'contact_category' => 'supervisory_authority',
            'contact_delay_hours' => 72, 'on_security_incident' => 0, 'on_data_breach' => 1,
        ]);
        $breach = new DataBreach();
        $breachId = (int) $breach->add([
            'entities_id'    => 0,
            'date_awareness' => date('Y-m-d H:i', strtotime('-5 days')),
        ]);
        $breach->getFromDB($breachId);

        $before = OfficialContact::evidence('A.5.5');
        $this->assertNotNull($before);
        $notices = OfficialContactNotification::noticesFor($breach, [$cnil->fields]);
        $this->assertSame(Rules::NOTICE_OVERDUE, $notices[$cnil->getID()]['state'], '72 h dépassées sans trace.');

        $late = OfficialContactNotification::record($breach, self::input($cnil, [
            'date_notified' => date('Y-m-d\TH:i', strtotime('-1 day')),
        ]));
        $this->assertIsInt($late);
        $notices = OfficialContactNotification::noticesFor($breach, [$cnil->fields]);
        $this->assertSame(Rules::NOTICE_LATE, $notices[$cnil->getID()]['state']);

        $after = OfficialContact::evidence('A.5.5');
        $this->assertNotNull($after);
        $this->assertSame($before['notified'] + 1, $after['notified']);
        $groups = OfficialContact::evidence('A.5.6');
        $this->assertNotNull($groups);
    }

    public function testLinkedObligationsAreShownWithTheContact(): void
    {
        $obligation = new ComplianceObligation();
        $obligationId = (int) $obligation->add([
            'title' => 'PHPUnit obligation de notification fictive',
            'type'  => 'contractual',
        ]);
        $this->assertGreaterThan(0, $obligationId);
        $contact = $this->contact([
            ContextItem::LINKS_MARKER => 1,
            '_linked_obligations'     => [$obligationId],
        ]);
        $presented = OfficialContact::present($contact->fields, date('Y-m-d'));
        $this->assertSame(
            ['PHPUnit obligation de notification fictive'],
            array_column($presented['obligations'], 'name')
        );
        $this->assertStringContainsString('PHPUnit obligation de notification fictive', OfficialContact::reportHtml());
    }
}
