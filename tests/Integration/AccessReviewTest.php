<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Document_Item;
use Group;
use PluginGrcmanagerAccessReview;
use PluginGrcmanagerControl;
use Profile_User;
use Ticket;

/**
 * Issue #126 : campagne de revue des accès, de son lancement à son rapport de clôture. Aucun droit
 * n'est modifié : une demande de modification ou de retrait crée un ticket.
 */
final class AccessReviewTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    private function createCampaign(): PluginGrcmanagerAccessReview
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Revue Accès');
        $manager = $this->createTestUser('Marie', 'Manager');
        $chief = $this->createTestUser('Claire', 'Chef');
        $alice = $this->createTestUser('Alice', 'Agent', ['users_id_supervisor' => $chief]);
        $bob = $this->createTestUser('Bob', 'Agent');
        $orphan = $this->createTestUser('Oscar', 'Seul');
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit Support N1', 'entities_id' => $entityId]);
        $DB->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $bob, 'is_manager' => 0]);
        $DB->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $manager, 'is_manager' => 1]);
        foreach ([$alice, $bob, $orphan] as $usersId) {
            $DB->insert(Profile_User::getTable(), [
                'users_id'     => $usersId,
                'profiles_id'  => 2,
                'entities_id'  => $entityId,
                'is_recursive' => 0,
            ]);
        }
        $this->ids = compact('entityId', 'manager', 'chief', 'alice', 'bob', 'orphan', 'groupId');

        $campaign = new PluginGrcmanagerAccessReview();
        $id = (int) $campaign->add([
            'name'         => 'PHPUnit revue T4',
            'entities_id'  => $entityId,
            'is_recursive' => 0,
            'frequency'    => 'quarterly',
            'due_date'     => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->assertGreaterThan(0, $id);
        $campaign->getFromDB($id);

        return $campaign;
    }

    /**
     * @return array<int, array<string, mixed>> users_id => ligne
     */
    private function linesByUser(int $campaignId): array
    {
        $lines = [];
        foreach (PluginGrcmanagerAccessReview::getLines($campaignId) as $line) {
            $lines[$line['users_id']] = $line;
        }

        return $lines;
    }

    public function testLaunchFreezesAccountsAndPicksTheirReviewer(): void
    {
        $campaign = $this->createCampaign();
        $this->assertSame('draft', $campaign->fields['status']);
        $this->assertSame(
            PluginGrcmanagerAccessReview::getDefaultControlId(),
            (int) $campaign->fields['plugin_grcmanager_controls_id'],
            'mesure A.5.18 proposée par défaut'
        );

        $this->assertSame(3, $campaign->launch());

        $this->assertSame('open', $campaign->fields['status']);
        $lines = $this->linesByUser($campaign->getID());
        $this->assertSame($this->ids['chief'], $lines[$this->ids['alice']]['reviewers_id']);
        $this->assertSame('supervisor', $lines[$this->ids['alice']]['reviewer_source']);
        $this->assertSame($this->ids['manager'], $lines[$this->ids['bob']]['reviewers_id']);
        $this->assertSame(['PHPUnit Support N1'], $lines[$this->ids['bob']]['groups_list']);
        $this->assertSame(2, $lines[$this->ids['orphan']]['reviewers_id'], 'sans responsable : le pilote');
        $this->assertStringContainsString('PHPUnit Revue Accès', $lines[$this->ids['alice']]['profiles_list'][0]);
        $this->assertSame(-1, $campaign->launch(), 'une campagne ne se lance qu\'une fois');
    }

    public function testARemovalRequestOpensATicketWithoutTouchingRights(): void
    {
        $campaign = $this->createCampaign();
        $campaign->launch();
        $lines = $this->linesByUser($campaign->getID());
        $bobLine = $lines[$this->ids['bob']]['id'];
        $profilesBefore = countElementsInTable(Profile_User::getTable(), ['users_id' => $this->ids['bob']]);

        $this->assertNotSame(
            '',
            PluginGrcmanagerAccessReview::decide($bobLine, 'remove', '', $this->ids['manager']),
            'motif obligatoire'
        );
        $this->assertSame(
            '',
            PluginGrcmanagerAccessReview::decide($bobLine, 'remove', 'A quitté le support', $this->ids['manager'])
        );

        $line = $this->linesByUser($campaign->getID())[$this->ids['bob']];
        $this->assertSame('remove', $line['decision']);
        $this->assertSame($this->ids['manager'], (int) $line['decided_by']);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB($line['tickets_id']));
        // Libellé comparé dans la langue de l'instance (anglais en CI, français en développement).
        $this->assertSame(
            sprintf(__('Revue des accès : retirer les accès de %s', 'grcmanager'), getUserName($this->ids['bob'])),
            $ticket->fields['name']
        );
        $this->assertStringContainsString('A quitté le support', $ticket->fields['content']);
        $this->assertSame(
            $profilesBefore,
            countElementsInTable(Profile_User::getTable(), ['users_id' => $this->ids['bob']])
        );
        $this->assertNotSame(
            '',
            PluginGrcmanagerAccessReview::decide($bobLine, 'keep', '', $this->ids['manager']),
            'ligne figée une fois le ticket créé'
        );

        $this->assertSame('', PluginGrcmanagerAccessReview::decide(
            $lines[$this->ids['alice']]['id'],
            'keep',
            '',
            $this->ids['chief']
        ));
        $this->assertSame(0, $this->linesByUser($campaign->getID())[$this->ids['alice']]['tickets_id']);
    }

    public function testLateReviewersAreRemindedAtMostOnceAWeek(): void
    {
        global $DB;

        $campaign = $this->createCampaign();
        $campaign->launch();
        $DB->update(
            $campaign->getTable(),
            ['due_date' => date('Y-m-d', strtotime('-2 days'))],
            ['id' => $campaign->getID()]
        );
        PluginGrcmanagerAccessReview::decide(
            $this->linesByUser($campaign->getID())[$this->ids['alice']]['id'],
            'keep',
            '',
            $this->ids['chief']
        );

        $this->assertSame(
            2,
            PluginGrcmanagerAccessReview::sendReminders(date('Y-m-d')),
            'Bob et Oscar restent à revoir'
        );
        $this->assertSame(0, PluginGrcmanagerAccessReview::sendReminders(date('Y-m-d')));
        $this->assertSame(2, PluginGrcmanagerAccessReview::sendReminders(date('Y-m-d', strtotime('+7 days'))));
    }

    public function testClosingArchivesTheReportOnTheCampaignAndTheControl(): void
    {
        $campaign = $this->createCampaign();
        $campaign->launch();

        $this->assertTrue($campaign->close());

        $this->assertSame('closed', $campaign->fields['status']);
        $documentsId = (int) $campaign->fields['documents_id'];
        $this->assertGreaterThan(0, $documentsId);
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => PluginGrcmanagerAccessReview::class,
            'items_id'     => $campaign->getID(),
        ]));
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => PluginGrcmanagerControl::class,
        ]));
        $this->assertFalse($campaign->close());

        $nextId = $campaign->prepareNext();
        $next = new PluginGrcmanagerAccessReview();
        $this->assertTrue($next->getFromDB($nextId));
        $this->assertSame('draft', $next->fields['status']);
        $this->assertSame(
            date('Y-m-d', strtotime($campaign->fields['date_start'] . ' +3 months')),
            $next->fields['date_start']
        );
    }
}
