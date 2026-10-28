<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Document_Item;
use Group;
use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\Control;
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

    private function createCampaign(): \GlpiPlugin\Grcmanager\AccessReview
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

        $campaign = new \GlpiPlugin\Grcmanager\AccessReview();
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
        foreach (\GlpiPlugin\Grcmanager\AccessReview::getLines($campaignId) as $line) {
            $lines[$line['users_id']] = $line;
        }

        return $lines;
    }

    public function testLaunchFreezesAccountsAndPicksTheirReviewer(): void
    {
        $campaign = $this->createCampaign();
        $this->assertSame('draft', $campaign->fields['status']);
        $this->assertSame(
            \GlpiPlugin\Grcmanager\AccessReview::getDefaultControlId(),
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
            \GlpiPlugin\Grcmanager\AccessReview::decide($bobLine, 'remove', '', $this->ids['manager']),
            'motif obligatoire'
        );
        $this->assertSame(
            '',
            \GlpiPlugin\Grcmanager\AccessReview::decide(
                $bobLine,
                'remove',
                'A quitté le support',
                $this->ids['manager']
            )
        );

        $line = $this->linesByUser($campaign->getID())[$this->ids['bob']];
        $this->assertSame('remove', $line['decision']);
        $this->assertSame($this->ids['manager'], (int) $line['decided_by']);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB($line['tickets_id']));
        // Libellé comparé dans la langue de l'instance (anglais en CI, français en développement).
        $this->assertSame(
            sprintf(__('Access review: remove the access of %s', 'grcmanager'), getUserName($this->ids['bob'])),
            $ticket->fields['name']
        );
        $this->assertStringContainsString('A quitté le support', $ticket->fields['content']);
        $this->assertSame(
            $profilesBefore,
            countElementsInTable(Profile_User::getTable(), ['users_id' => $this->ids['bob']])
        );
        $this->assertNotSame(
            '',
            \GlpiPlugin\Grcmanager\AccessReview::decide($bobLine, 'keep', '', $this->ids['manager']),
            'ligne figée une fois le ticket créé'
        );

        $this->assertSame('', \GlpiPlugin\Grcmanager\AccessReview::decide(
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
        \GlpiPlugin\Grcmanager\AccessReview::decide(
            $this->linesByUser($campaign->getID())[$this->ids['alice']]['id'],
            'keep',
            '',
            $this->ids['chief']
        );

        $this->assertSame(
            2,
            \GlpiPlugin\Grcmanager\AccessReview::sendReminders(date('Y-m-d')),
            'Bob et Oscar restent à revoir'
        );
        $this->assertSame(0, \GlpiPlugin\Grcmanager\AccessReview::sendReminders(date('Y-m-d')));
        $this->assertSame(2, \GlpiPlugin\Grcmanager\AccessReview::sendReminders(date('Y-m-d', strtotime('+7 days'))));
    }

    public function testClosingArchivesTheReportOnTheCampaignOnly(): void
    {
        $campaign = $this->createCampaign();
        $campaign->launch();

        $this->assertTrue($campaign->close());

        $this->assertSame('closed', $campaign->fields['status']);
        $documentsId = (int) $campaign->fields['documents_id'];
        $this->assertGreaterThan(0, $documentsId);
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => \GlpiPlugin\Grcmanager\AccessReview::class,
            'items_id'     => $campaign->getID(),
        ]));
        // Revue de sécurité 2.11.0 : jamais lié à la mesure A.5.18 (globale), non récursif, et
        // lisible avec les seuls droits de la campagne.
        $this->assertSame(0, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => \GlpiPlugin\Grcmanager\Control::class,
        ]));
        $document = new \Document();
        $this->assertTrue($document->getFromDB($documentsId));
        $this->assertSame(0, (int) $document->fields['is_recursive']);
        $this->assertSame(
            ['itemtype' => \GlpiPlugin\Grcmanager\AccessReview::class, 'items_id' => $campaign->getID()],
            \PluginGrcmanagerRestrictedDocument::sourceOf($documentsId)
        );
        $this->assertFalse($campaign->close());

        $nextId = $campaign->prepareNext();
        $next = new \GlpiPlugin\Grcmanager\AccessReview();
        $this->assertTrue($next->getFromDB($nextId));
        $this->assertSame('draft', $next->fields['status']);
        $this->assertSame(
            date('Y-m-d', strtotime($campaign->fields['date_start'] . ' +3 months')),
            $next->fields['date_start']
        );
    }
}
