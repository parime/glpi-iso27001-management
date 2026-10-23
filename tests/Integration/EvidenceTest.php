<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PluginGrcmanagerEvidence;
use PluginGrcmanagerPolicy;

/**
 * Issue #145 : validité des documents de preuve, rappel au responsable puis ticket de
 * renouvellement, sans jamais changer le statut de l'élément porteur.
 */
final class EvidenceTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array{0: PluginGrcmanagerPolicy, 1: int}
     */
    private function policyWithDocument(): array
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit preuves ' . random_int(1000, 9999));
        $policy = new PluginGrcmanagerPolicy();
        $policy->getFromDB((int) $policy->add(['title' => 'PHPUnit politique preuves', 'entities_id' => $entityId]));
        $documentsId = (int) (new \Document())->add([
            'name' => 'PHPUnit certificat ISO fournisseur', 'entities_id' => $entityId,
        ]);
        (new \Document_Item())->add([
            'documents_id' => $documentsId, 'itemtype' => PluginGrcmanagerPolicy::class, 'items_id' => $policy->getID(),
        ]);

        return [$policy, $documentsId];
    }

    private function evidence(
        PluginGrcmanagerPolicy $policy,
        int $documentsId,
        string $validUntil,
        int $usersId = 0
    ): PluginGrcmanagerEvidence {
        $evidence = new PluginGrcmanagerEvidence();
        $evidence->getFromDB((int) $evidence->add([
            'itemtype' => PluginGrcmanagerPolicy::class, 'items_id' => $policy->getID(), 'documents_id' => $documentsId,
            'valid_until' => $validUntil, 'users_id' => $usersId,
        ]));

        return $evidence;
    }

    public function testValidityAttachesOnlyToADocumentLinkedToTheItem(): void
    {
        [$policy, $documentsId] = $this->policyWithDocument();
        $unlinked = (int) (new \Document())->add(['name' => 'PHPUnit document non rattaché', 'entities_id' => 0]);

        $this->assertFalse((new PluginGrcmanagerEvidence())->add([
            'itemtype' => PluginGrcmanagerPolicy::class, 'items_id' => $policy->getID(),
            'documents_id' => $unlinked, 'valid_until' => '2027-01-01',
        ]));
        $this->assertFalse((new PluginGrcmanagerEvidence())->add([
            'itemtype' => 'Computer', 'items_id' => 1, 'documents_id' => $documentsId, 'valid_until' => '2027-01-01',
        ]), 'Type d\'élément non pris en charge.');

        $evidence = $this->evidence($policy, $documentsId, '2027-01-01');
        $document = new \Document();
        $document->getFromDB($documentsId);
        $this->assertSame(
            (int) $document->fields['entities_id'],
            (int) $evidence->fields['entities_id'],
            'Entité reprise du document (les éléments porteurs sont globaux).'
        );
        $this->assertCount(1, PluginGrcmanagerEvidence::getDocumentsFor($policy));
    }

    public function testTheResponsibleIsWarnedOnceThenOneRenewalTicketIsOpenedAndTheItemNeverChanges(): void
    {
        [$policy, $documentsId] = $this->policyWithDocument();
        $statusBefore = $policy->fields['status'];
        $owner = $this->createTestUser('Rssi', 'Preuves');
        $evidence = $this->evidence($policy, $documentsId, date('Y-m-d', strtotime('+10 days')), $owner);

        // L'instance de test peut contenir d'autres preuves : on suit celle-ci.
        PluginGrcmanagerEvidence::runReminders(date('Y-m-d'));
        $evidence->getFromDB($evidence->getID());
        $notifiedAt = $evidence->fields['date_notified'];
        $this->assertNotNull($notifiedAt, 'Responsable prévenu avant l\'échéance.');
        $this->assertSame(0, (int) $evidence->fields['tickets_id'], 'Pas de ticket avant l\'échéance.');
        PluginGrcmanagerEvidence::runReminders(date('Y-m-d'));
        $evidence->getFromDB($evidence->getID());
        $this->assertSame($notifiedAt, $evidence->fields['date_notified'], 'Un seul rappel.');

        // Le jour suivant l'échéance : un ticket, une seule fois.
        $afterExpiry = date('Y-m-d', strtotime('+11 days'));
        PluginGrcmanagerEvidence::runReminders($afterExpiry);
        $evidence->getFromDB($evidence->getID());
        $ticketsId = (int) $evidence->fields['tickets_id'];
        $this->assertGreaterThan(0, $ticketsId);
        PluginGrcmanagerEvidence::runReminders($afterExpiry);
        $evidence->getFromDB($evidence->getID());
        $this->assertSame($ticketsId, (int) $evidence->fields['tickets_id'], 'Un seul ticket.');

        $ticket = new \Ticket();
        $this->assertTrue($ticket->getFromDB((int) $evidence->fields['tickets_id']));
        $this->assertStringContainsString('PHPUnit certificat ISO fournisseur', (string) $ticket->fields['name']);
        $this->assertSame(
            (int) $evidence->fields['entities_id'],
            (int) $ticket->fields['entities_id'],
            'Ticket dans l\'entité du document.'
        );

        $policy->getFromDB($policy->getID());
        $this->assertSame($statusBefore, $policy->fields['status'], 'Jamais de changement de statut de l\'élément.');
    }

    public function testANewValidityDateRearmsTheReminderAndTheTicket(): void
    {
        [$policy, $documentsId] = $this->policyWithDocument();
        $evidence = $this->evidence($policy, $documentsId, date('Y-m-d', strtotime('-1 day')));
        PluginGrcmanagerEvidence::runReminders(date('Y-m-d'));
        $evidence->getFromDB($evidence->getID());
        $this->assertGreaterThan(0, (int) $evidence->fields['tickets_id']);

        $evidence->update(['id' => $evidence->getID(), 'valid_until' => date('Y-m-d', strtotime('+1 year'))]);
        $evidence->getFromDB($evidence->getID());

        $this->assertSame(0, (int) $evidence->fields['tickets_id']);
        $this->assertNull($evidence->fields['date_notified']);
    }
}
