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

use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\DataSubjectRequest;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use Ticket;

/**
 * Issue #375 : demandes d'exercice des droits (RGPD). Données fictives.
 */
final class DataSubjectRequestTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function request(array $input = []): DataSubjectRequest
    {
        $request = new DataSubjectRequest();
        $id = (int) $request->add($input + [
            'entities_id' => 0, 'requester' => 'Personne Fictive', 'date_received' => '2026-03-31',
        ]);
        $this->assertGreaterThan(0, $id);
        $request->getFromDB($id);

        return $request;
    }

    public function testDeadlineIsComputedAndCannotBeForged(): void
    {
        $request = $this->request(['date_due' => '2099-01-01', 'request_type' => 'inconnu']);
        $id = (int) $request->getID();
        $this->assertSame('2026-04-30', $request->fields['date_due']);
        $this->assertSame('access', $request->fields['request_type']);
        $this->assertNotSame('', trim((string) $request->fields['name']), 'Un titre par défaut, sans identité.');
        $this->assertStringNotContainsString('Fictive', (string) $request->fields['name']);

        // Prolongation : refusée sans justification, sinon trois mois.
        $this->assertFalse($request->update(['id' => $id, 'is_extended' => 1]));
        $request->getFromDB($id);
        $this->assertSame('2026-04-30', $request->fields['date_due']);
        $extension = ['id' => $id, 'is_extended' => 1, 'extension_reason' => 'Demande complexe'];
        $this->assertTrue($request->update($extension));
        $request->getFromDB($id);
        $this->assertSame('2026-06-30', $request->fields['date_due']);

        // La date de réception reste obligatoire.
        $this->assertFalse($request->update(['id' => $id, 'date_received' => 'pas une date']));

        $request->delete(['id' => $id], true);
    }

    public function testClosingNeedsAnAnswerDateAndARefusalNeedsAReason(): void
    {
        $request = $this->request();
        $id = (int) $request->getID();

        $this->assertFalse($request->update(['id' => $id, 'status' => 'refused']));
        $request->getFromDB($id);
        $this->assertSame('received', $request->fields['status']);

        // Une réponse sans date prend celle du jour.
        $this->assertTrue($request->update(['id' => $id, 'status' => 'answered']));
        $request->getFromDB($id);
        $this->assertNotEmpty($request->fields['date_answered']);

        $this->assertTrue($request->update(['id' => $id, 'date_answered' => '2026-04-30']));
        $request->getFromDB($id);
        $this->assertSame('closed', DataSubjectRequest::stateOf($request->fields)['state']);
        $this->assertTrue($request->update(['id' => $id, 'date_answered' => '2026-05-02']));
        $request->getFromDB($id);
        $this->assertSame('late_answer', DataSubjectRequest::stateOf($request->fields)['state']);

        $request->delete(['id' => $id], true);
    }

    public function testActivityLinkAndEntityStayWithinWhatTheUserCanSee(): void
    {
        $activity = new ProcessingActivity();
        $activityId = (int) $activity->add(['name' => 'PHPUnit traitement demande', 'entities_id' => 0]);
        $key = ProcessingActivity::getForeignKeyField();

        $request = $this->request([$key => $activityId]);
        $this->assertSame($activityId, (int) $request->fields[$key]);
        $other = $this->request([$key => 999999]);
        $this->assertSame(0, (int) $other->fields[$key], 'Une fiche inexistante n\'est pas liée.');

        $request->delete(['id' => (int) $request->getID()], true);
        $other->delete(['id' => (int) $other->getID()], true);
        $activity->delete(['id' => $activityId], true);
    }

    public function testFollowUpTicketNeverCarriesTheIdentityAndIsCreatedOnce(): void
    {
        $request = $this->request(['name' => 'Titre libre fictif', 'request_type' => 'erasure']);
        $this->assertSame(0, (int) $request->fields['tickets_id'], 'Aucun ticket sans demande explicite.');

        $ticketId = $request->createTicket();
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame($ticketId, $request->createTicket(), 'Une seule création.');

        $ticket = new Ticket();
        $ticket->getFromDB($ticketId);
        $text = $ticket->fields['name'] . ' ' . $ticket->fields['content'];
        $this->assertStringNotContainsString('Personne Fictive', $text);
        $this->assertStringNotContainsString('Titre libre fictif', $text);
        $this->assertStringContainsString('datasubjectrequest.form.php', (string) $ticket->fields['content']);

        $ticket->delete(['id' => $ticketId], true);
        $request->delete(['id' => (int) $request->getID()], true);
    }

    public function testCalendarShowsTheDeadlineWithoutIdentityAndRespectsTheRight(): void
    {
        $request = $this->request(['name' => 'Titre libre fictif', 'date_received' => '2026-03-15']);
        $id = (int) $request->getID();
        $begin = '2026-04-01 00:00:00';
        $end = '2026-05-01 00:00:00';
        $mine = static fn (): array => array_values(array_filter(
            ComplianceEvent::collect($begin, $end),
            static fn (array $event): bool => $event['uid'] === 'data_subject_request-' . $id . '@grcmanager'
        ));

        $events = $mine();
        $this->assertCount(1, $events);
        $this->assertStringStartsWith('2026-04-15', $events[0]['begin']);
        $this->assertStringNotContainsString('Personne Fictive', $events[0]['name']);
        $this->assertStringNotContainsString('Titre libre fictif', $events[0]['name']);

        $saved = $_SESSION['glpiactiveprofile'][DataSubjectRequest::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][DataSubjectRequest::RIGHTNAME] = 0;
            $this->assertSame([], $mine(), 'Sans le droit RGPD, rien au calendrier.');
        } finally {
            $_SESSION['glpiactiveprofile'][DataSubjectRequest::RIGHTNAME] = $saved;
        }

        // Une demande close sort des échéances à tenir.
        $request->update(['id' => $id, 'status' => 'answered', 'date_answered' => '2026-04-10']);
        $this->assertTrue($mine()[0]['done']);

        $request->delete(['id' => $id], true);
    }

    public function testFormAndListRender(): void
    {
        $request = $this->request();
        ob_start();
        $request->showForm((int) $request->getID());
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="create_ticket"', $html);
        $this->assertStringContainsString('Personne Fictive', $html);

        $badge = DataSubjectRequest::getSpecificValueToDisplay('date_due', $request->fields);
        $this->assertStringContainsString('badge', $badge);

        $request->delete(['id' => (int) $request->getID()], true);
    }
}
