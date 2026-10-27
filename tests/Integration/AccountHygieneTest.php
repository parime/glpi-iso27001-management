<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use PluginGrcmanagerAccountHygiene;

/**
 * Issue #184 : comptes actifs à vérifier, limités aux entités de la personne, et ticket de revue
 * sans aucune modification des comptes.
 */
final class AccountHygieneTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> entités de session avant le test (restreintes par ces tests) */
    private array $sessionEntities = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity'] as $key) {
            $this->sessionEntities[$key] = $_SESSION[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        // Les entités créées ici disparaissent au rollback : les tests suivants ne doivent pas en hériter.
        foreach ($this->sessionEntities as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function userIn(int $entitiesId, string $name, array $fields = [], ?string $email = null): int
    {
        global $DB;

        $id = $this->createTestUser('Hyg', $name);
        (new \Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $entitiesId]);
        // Champs gérés par l'authentification (dernière connexion, suppression dans l'annuaire) :
        // posés directement, comme les écrirait la synchronisation.
        if ($fields !== []) {
            $DB->update('glpi_users', $fields, ['id' => $id]);
        }
        if ($email !== null) {
            (new \UserEmail())->add(['users_id' => $id, 'email' => $email]);
        }

        return $id;
    }

    /**
     * @return array<int, list<string>>
     */
    private function checksById(int $days = 90): array
    {
        return array_column(PluginGrcmanagerAccountHygiene::findAccounts($days), 'checks', 'id');
    }

    public function testAccountsToCheckAreFoundOnlyInTheVisibleEntities(): void
    {
        $visible = $this->createTestEntity(0, 'PHPUnit hygiène A ' . random_int(1000, 9999));
        $hidden = $this->createTestEntity(0, 'PHPUnit hygiène B ' . random_int(1000, 9999));
        $now = date('Y-m-d H:i:s');

        $healthy = $this->userIn($visible, 'Sain', ['last_login' => $now]);
        $deleted = $this->userIn($visible, 'Supprime', ['is_deleted_ldap' => 1, 'last_login' => $now]);
        $idle = $this->userIn($visible, 'Inactif', ['last_login' => date('Y-m-d H:i:s', strtotime('-200 days'))]);
        $twinA = $this->userIn($visible, 'JumeauA', ['last_login' => $now], 'phpunit.jumeau@exemple.fr');
        $twinB = $this->userIn($visible, 'JumeauB', ['last_login' => $now], 'PHPUnit.Jumeau@exemple.fr');
        $other = $this->userIn($hidden, 'Autre', ['is_deleted_ldap' => 1]);

        $_SESSION['glpiactiveentities'] = [$visible];
        $_SESSION['glpiactiveentities_string'] = (string) $visible;
        $checks = $this->checksById();

        $this->assertArrayNotHasKey($healthy, $checks);
        $this->assertSame([AccountHygieneRules::DIRECTORY_DELETED], $checks[$deleted]);
        $this->assertSame([AccountHygieneRules::INACTIVE], $checks[$idle]);
        $this->assertSame([AccountHygieneRules::DUPLICATE_EMAIL], $checks[$twinA]);
        $this->assertSame([AccountHygieneRules::DUPLICATE_EMAIL], $checks[$twinB]);
        $this->assertArrayNotHasKey($other, $checks, 'Compte d\'une entité non visible.');
        $this->assertArrayNotHasKey($idle, $this->checksById(365), 'Sous le seuil choisi.');
    }

    /** Issue #184, phase 2 : changement de groupe sans revue des profils. */
    public function testAGroupChangeWithoutProfileReviewIsReported(): void
    {
        global $DB;

        $entity = $this->createTestEntity(0, 'PHPUnit hygiène C ' . random_int(1000, 9999));
        $now = date('Y-m-d H:i:s');
        $moved = $this->userIn($entity, 'Mute', [
            'last_login' => $now, 'date_creation' => date('Y-m-d H:i:s', strtotime('-400 days')),
        ]);
        $reviewed = $this->userIn($entity, 'Revu', [
            'last_login' => $now, 'date_creation' => date('Y-m-d H:i:s', strtotime('-400 days')),
        ]);
        // Habilitations posées à la création : datées comme le compte (historique ancien).
        $DB->update('glpi_logs', ['date_mod' => date('Y-m-d H:i:s', strtotime('-400 days'))], [
            'itemtype' => 'User', 'items_id' => [$moved, $reviewed],
        ]);
        $group = (int) (new \Group())->add(['name' => 'PHPUnit Comptabilité', 'entities_id' => 0]);
        foreach ([$moved, $reviewed] as $usersId) {
            (new \Group_User())->add(['users_id' => $usersId, 'groups_id' => $group]);
        }
        // L'un voit ses profils revus après son changement de groupe.
        $DB->update('glpi_logs', ['date_mod' => date('Y-m-d H:i:s', strtotime('-1 hour'))], [
            'itemtype' => 'User', 'items_id' => [$moved, $reviewed], 'itemtype_link' => 'Group',
        ]);
        (new \Profile_User())->add(['users_id' => $reviewed, 'profiles_id' => 2, 'entities_id' => $entity]);

        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string) $entity;
        $checks = $this->checksById();

        $this->assertSame([AccountHygieneRules::GROUP_CHANGED], $checks[$moved]);
        $this->assertArrayNotHasKey($reviewed, $checks, 'Profils revus après le changement de groupe.');
    }

    public function testTheReviewTicketListsTheCheckedAccountsAndChangesNothing(): void
    {
        $entity = $this->createTestEntity(0, 'PHPUnit hygiène C ' . random_int(1000, 9999));
        $deleted = $this->userIn($entity, 'Parti', ['is_deleted_ldap' => 1, 'last_login' => date('Y-m-d H:i:s')]);
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string) $entity;
        $_SESSION['glpiactive_entity'] = $entity;

        $this->assertSame(0, PluginGrcmanagerAccountHygiene::createReviewTicket([], 90), 'Rien de coché.');
        $this->assertSame(0, PluginGrcmanagerAccountHygiene::createReviewTicket([2], 90), 'Compte hors liste.');

        $ticketsId = PluginGrcmanagerAccountHygiene::createReviewTicket([$deleted], 90);
        $this->assertGreaterThan(0, $ticketsId);
        $ticket = new \Ticket();
        $ticket->getFromDB($ticketsId);
        $this->assertStringContainsString('Parti', $ticket->fields['content']);
        $this->assertSame($entity, (int) $ticket->fields['entities_id']);

        $user = new \User();
        $user->getFromDB($deleted);
        $this->assertSame(1, (int) $user->fields['is_active'], 'Le compte n\'est jamais désactivé.');
    }
}
