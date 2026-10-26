<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PluginGrcmanagerPeriodicReview;

/**
 * Issue #141 : une revue activée devient un ticket récurrent natif ; son ticket résolu date la
 * revue, sans que le plugin modifie le ticket lui-même.
 */
final class PeriodicReviewTest extends GrcmanagerIntegrationTestCase
{
    private function review(string $key): PluginGrcmanagerPeriodicReview
    {
        $review = PluginGrcmanagerPeriodicReview::getByKey($key);
        $this->assertNotNull($review, 'Ligne créée par l\'installeur pour chaque revue du catalogue.');

        return $review;
    }

    /** Utilisateur que la liste « Attribué à » propose : profil Technicien à la racine. */
    private function technician(string $firstname, string $realname): int
    {
        global $DB;

        $usersId = $this->createTestUser($firstname, $realname);
        $DB->insert(\Profile_User::getTable(), [
            'users_id' => $usersId, 'profiles_id' => 6, 'entities_id' => 0, 'is_recursive' => 1,
        ]);

        return $usersId;
    }

    public function testAnAssigneeTheFormWouldNotOfferIsDropped(): void
    {
        // Revue de sécurité 2.11.0 : id d'utilisateur posté sans droit de recevoir des tickets.
        $review = $this->review('privileged_accounts');
        $this->assertTrue($review->activate(6, $this->createTestUser('Sans', 'Profil'), 0));
        $review->getFromDB($review->getID());
        $this->assertSame(0, (int) $review->fields['users_id_assign']);
        $review->deactivate();
    }

    public function testActivationCreatesANativeTemplateAndRecurrentTicket(): void
    {
        $owner = $this->technician('Rssi', 'Revues');
        $review = $this->review('access_rights');
        $this->assertTrue($review->activate(3, $owner, 0));
        $review->getFromDB($review->getID());

        $this->assertTrue($review->isActive());
        $this->assertSame(3, (int) $review->fields['frequency_months']);
        $this->assertSame(date('Y-m-d'), $review->fields['date_activation']);

        $recurrent = new \TicketRecurrent();
        $this->assertTrue($recurrent->getFromDB((int) $review->fields['ticketrecurrents_id']));
        $this->assertSame('3MONTH', $recurrent->fields['periodicity']);
        $this->assertSame(1, (int) $recurrent->fields['is_active']);
        $this->assertSame((int) $review->fields['tickettemplates_id'], (int) $recurrent->fields['tickettemplates_id']);

        $templatesId = (int) $review->fields['tickettemplates_id'];
        $predefined = (new \TicketTemplatePredefinedField())->getPredefinedFields($templatesId);
        $this->assertSame($owner, (int) $predefined['_users_id_assign']);
        $this->assertStringContainsString('A.5.15', $predefined['content']);

        // Réactiver ou enregistrer réutilise le même ticket récurrent et le même gabarit.
        $this->assertTrue($review->activate(12, $owner, 0));
        $review->getFromDB($review->getID());
        $this->assertSame($recurrent->getID(), (int) $review->fields['ticketrecurrents_id']);
        $recurrent->getFromDB($recurrent->getID());
        $this->assertSame('1YEAR', $recurrent->fields['periodicity']);
        $this->assertLessThanOrEqual(
            date('Y-m-d H:i:s'),
            $recurrent->fields['next_creation_date'],
            'Aucun ticket encore créé : modifier la revue ne repousse pas le premier.'
        );
    }

    public function testTheGeneratedTicketIsFollowedAndItsResolutionDatesTheReview(): void
    {
        $owner = $this->technician('Admin', 'Sauvegardes');
        global $DB;

        $review = $this->review('backup_restore_test');
        // Instance partagée : repartir d'une revue jamais faite (annulé par le rollback).
        $DB->update(
            $review->getTable(),
            ['last_review_date' => null, 'last_tickets_id' => 0],
            ['id' => $review->getID()]
        );
        $review->getFromDB($review->getID());
        $review->activate(6, $owner, 0);
        $review->getFromDB($review->getID());

        $recurrent = new \TicketRecurrent();
        $recurrent->getFromDB((int) $review->fields['ticketrecurrents_id']);
        $_SESSION['glpiactive_entity'] ??= 0; // Ticket::getDefaultValues(), comme sous le cron.

        // Un ticket saisi à la main avec le gabarit de la revue ne devient pas son ticket.
        $manual = new \Ticket();
        $manual->add([
            'name' => 'PHPUnit ticket manuel', 'content' => 'x', 'entities_id' => 0,
            'tickettemplates_id' => (int) $review->fields['tickettemplates_id'],
        ]);
        $review->getFromDB($review->getID());
        $this->assertSame(0, (int) $review->fields['last_tickets_id'], 'Ticket manuel ignoré.');

        // Création par l'action automatique des tickets récurrents (Session::isCron()).
        $_SESSION['glpicronuserrunning'] = 'cron_ticketrecurrent';
        $ticket = null;
        try {
            $this->assertTrue($recurrent->createItem([], $ticket));
        } finally {
            unset($_SESSION['glpicronuserrunning']);
        }
        $this->assertInstanceOf(\Ticket::class, $ticket);
        // Libellé traduit : la CI tourne en anglais, l'instance de développement en français.
        $this->assertSame($review->getRecurrentName(), $ticket->fields['name']);

        $review->getFromDB($review->getID());
        $this->assertSame($ticket->getID(), (int) $review->fields['last_tickets_id']);
        $this->assertNull($review->fields['last_review_date']);

        $ticket->update(['id' => $ticket->getID(), 'status' => \CommonITILObject::SOLVED]);
        $review->getFromDB($review->getID());
        $this->assertSame(date('Y-m-d'), $review->fields['last_review_date']);
        $this->assertFalse($review->isOverdue(date('Y-m-d')));
    }

    public function testAnUnrelatedTicketChangesNothing(): void
    {
        $review = $this->review('policies');
        $review->activate(12, 0, 0);
        $review->getFromDB($review->getID());

        $ticket = new \Ticket();
        $ticket->add(['name' => 'PHPUnit ticket sans lien', 'content' => 'x', 'entities_id' => 0]);
        $review->getFromDB($review->getID());
        $this->assertSame(0, (int) $review->fields['last_tickets_id']);
    }

    public function testDeactivationSuspendsTheRecurrentTicketWithoutDeletingIt(): void
    {
        $review = $this->review('soa');
        $review->activate(12, 0, 0);
        $review->getFromDB($review->getID());
        $recurrentsId = (int) $review->fields['ticketrecurrents_id'];

        $this->assertTrue($review->deactivate());
        $review->getFromDB($review->getID());
        $this->assertFalse($review->isActive());
        $recurrent = new \TicketRecurrent();
        $this->assertTrue($recurrent->getFromDB($recurrentsId));
        $this->assertSame(0, (int) $recurrent->fields['is_active']);
        $this->assertNull($review->getNextCreationDate());
    }
}
