<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Document_Item;
use Group;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Policy\AcknowledgementRules;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use Profile_User;

/**
 * Issue #535 : campagne de prise de connaissance d'une politique, de la définition du public à
 * l'archivage du rapport sur A.5.1. Aucun statut ni droit n'est modifié par une relance.
 * Indépendant de la langue de l'instance : aucune comparaison de texte traduit.
 */
final class PolicyAcknowledgementTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    private function createPolicy(string $status = 'approved'): Policy
    {
        global $DB;

        $entityA = $this->createTestEntity(0, 'PHPUnit Siège');
        $entityB = $this->createTestEntity(0, 'PHPUnit Agence');
        $alice = $this->createTestUser('Alice', 'Lecture');
        $bob = $this->createTestUser('Bob', 'Lecture');
        $carla = $this->createTestUser('Carla', 'Agence');
        $outsider = $this->createTestUser('Oscar', 'Dehors');
        $inactive = $this->createTestUser('Ines', 'Inactive', ['is_active' => 0]);
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit Comptabilité', 'entities_id' => $entityA]);
        foreach ([$alice, $bob, $inactive] as $usersId) {
            $DB->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $usersId]);
        }
        $authorizations = [[$alice, $entityA], [$bob, $entityA], [$carla, $entityB], [$outsider, $entityA]];
        foreach ($authorizations as [$usersId, $entityId]) {
            $DB->insert(Profile_User::getTable(), [
                'users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0,
            ]);
        }
        $this->ids = compact('entityA', 'entityB', 'alice', 'bob', 'carla', 'outsider', 'inactive', 'groupId');

        $policy = new Policy();
        $id = (int) $policy->add([
            'title'                => 'PHPUnit politique mots de passe',
            'version'              => '1.0',
            'status'               => $status,
            'approval_date'        => $status === 'approved' ? date('Y-m-d') : null,
            'acknowledgement_days' => 15,
        ]);
        $this->assertGreaterThan(0, $id);
        $policy->getFromDB($id);

        return $policy;
    }

    private function defineAudience(Policy $policy): void
    {
        $this->assertTrue(PolicyAcknowledgement::addAudience($policy->getID(), 'Group', $this->ids['groupId']));
        $this->assertTrue(PolicyAcknowledgement::addAudience($policy->getID(), 'Entity', $this->ids['entityB'], true));
        $this->assertFalse(PolicyAcknowledgement::addAudience($policy->getID(), 'User', 2), 'type refusé');
        $this->assertFalse(PolicyAcknowledgement::addAudience($policy->getID(), 'Group', 999999999), 'groupe inconnu');
    }

    /**
     * @return array<int, array<string, mixed>> users_id => ligne
     */
    private function linesByUser(int $campaignId): array
    {
        $lines = [];
        foreach (PolicyAcknowledgement::getLines($campaignId) as $line) {
            $lines[$line['users_id']] = $line;
        }

        return $lines;
    }

    public function testAudienceAndDeadlineAreDefinedOnThePolicy(): void
    {
        $policy = $this->createPolicy();
        $this->assertSame(15, (int) $policy->fields['acknowledgement_days']);
        $this->defineAudience($policy);

        $members = PolicyAcknowledgement::resolveMembers($policy->getID());
        $this->assertSame(
            [$this->ids['alice'], $this->ids['bob'], $this->ids['carla']],
            array_keys($members),
            'groupe et entité réunis ; compte inactif et personne hors public exclus'
        );
        $this->assertSame($this->ids['entityB'], $members[$this->ids['carla']]);

        $policy->update(['id' => $policy->getID(), 'acknowledgement_days' => 5000]);
        $policy->getFromDB($policy->getID());
        $this->assertSame(AcknowledgementRules::MAX_DAYS, (int) $policy->fields['acknowledgement_days']);
    }

    public function testCampaignOnlyOnAnApprovedPolicyWithAnAudience(): void
    {
        $policy = $this->createPolicy('draft');
        $this->assertSame(-1, PolicyAcknowledgement::launch($policy, 2), 'sans public');
        $this->defineAudience($policy);
        $this->assertSame(-1, PolicyAcknowledgement::launch($policy, 2), 'brouillon');
        $this->assertNull(PolicyAcknowledgement::getCurrentCampaign($policy->getID()));
    }

    public function testLaunchConfirmationRateAndMySpace(): void
    {
        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        $this->assertSame(3, PolicyAcknowledgement::launch($policy, 2));
        $this->assertSame(-1, PolicyAcknowledgement::launch($policy, 2), 'une seule campagne par version');

        $campaign = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $this->assertSame('1.0', $campaign['version']);
        $this->assertSame(date('Y-m-d', strtotime('+15 days')), $campaign['due_date']);
        $lines = $this->linesByUser((int) $campaign['id']);

        // La personne voit la politique à lire, dans ses préférences et dans « Mon espace GRC ».
        $mine = PolicyAcknowledgement::getMine($this->ids['alice']);
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['pending']);
        $sources = array_column(MySpaceProvider::items($this->ids['alice'], date('Y-m-d')), 'source');
        $this->assertContains('policy_acknowledgement', $sources);
        $this->assertSame([], PolicyAcknowledgement::getMine($this->ids['outsider']), 'hors du public');

        // Une personne ne confirme que pour elle-même.
        $this->assertNotSame(
            '',
            PolicyAcknowledgement::acknowledge($lines[$this->ids['alice']]['id'], $this->ids['bob'])
        );
        $this->assertSame(
            '',
            PolicyAcknowledgement::acknowledge($lines[$this->ids['alice']]['id'], $this->ids['alice'])
        );
        $this->assertSame(
            '',
            PolicyAcknowledgement::acknowledge($lines[$this->ids['alice']]['id'], $this->ids['alice']),
            'idempotent'
        );
        $this->assertFalse(PolicyAcknowledgement::getMine($this->ids['alice'])[0]['pending']);
        $this->assertNotContains(
            'policy_acknowledgement',
            array_column(MySpaceProvider::items($this->ids['alice'], date('Y-m-d')), 'source')
        );

        $summary = PolicyAcknowledgement::summarize($campaign);
        $this->assertSame(33, $summary['progress']['rate']);
        $this->assertSame(0, $summary['progress']['late'], 'échéance pas encore passée');
        $byEntity = array_column($summary['by_entity'], 'rate', 'entity');
        $this->assertCount(2, $byEntity);
        $this->assertSame(
            [$this->ids['bob'], $this->ids['carla']],
            PolicyAcknowledgement::getPendingUserIds($policy->getID())
        );
    }

    public function testLatecomersAndRemindersNeverChangeAnything(): void
    {
        global $DB;

        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        PolicyAcknowledgement::launch($policy, 2);
        $campaign = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $lines = $this->linesByUser((int) $campaign['id']);
        PolicyAcknowledgement::acknowledge($lines[$this->ids['carla']]['id'], $this->ids['carla']);
        $DB->update(
            PolicyAcknowledgement::CAMPAIGNS_TABLE,
            ['due_date' => date('Y-m-d', strtotime('-2 days'))],
            ['id' => $campaign['id']]
        );
        $campaign = PolicyAcknowledgement::getCampaign((int) $campaign['id']);

        $late = array_column(PolicyAcknowledgement::summarize($campaign)['latecomers'], 'users_id');
        $this->assertSame([$this->ids['alice'], $this->ids['bob']], array_values(array_intersect(
            [$this->ids['alice'], $this->ids['bob']],
            $late
        )));
        $this->assertCount(2, $late);

        $this->assertSame(2, PolicyAcknowledgement::sendReminders(date('Y-m-d')));
        $this->assertSame(0, PolicyAcknowledgement::sendReminders(date('Y-m-d')), 'au plus une relance par semaine');
        $this->assertSame(
            2,
            PolicyAcknowledgement::sendReminders(date('Y-m-d', strtotime('+7 days'))),
            'nouvelle relance une semaine plus tard'
        );
        $policy->getFromDB($policy->getID());
        $this->assertSame('approved', $policy->fields['status'], 'une relance ne change pas la politique');
        $this->assertSame(
            AcknowledgementRules::STATUS_OPEN,
            PolicyAcknowledgement::getCampaign((int) $campaign['id'])['status']
        );
    }

    public function testANewVersionRelaunchesACampaign(): void
    {
        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        PolicyAcknowledgement::launch($policy, 2);
        $first = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $lines = $this->linesByUser((int) $first['id']);
        PolicyAcknowledgement::acknowledge($lines[$this->ids['alice']]['id'], $this->ids['alice']);

        $policy->update(['id' => $policy->getID(), 'title' => 'PHPUnit politique mots de passe (corrigée)']);
        $this->assertSame(
            (int) $first['id'],
            (int) PolicyAcknowledgement::getCurrentCampaign($policy->getID())['id'],
            'même version'
        );

        $policy->update(['id' => $policy->getID(), 'version' => '2.0']);
        $second = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $this->assertNotSame((int) $first['id'], (int) $second['id']);
        $this->assertSame('2.0', $second['version']);
        $this->assertSame(
            AcknowledgementRules::STATUS_OPEN,
            PolicyAcknowledgement::getCampaign((int) $first['id'])['status'],
            'ancienne campagne non close automatiquement'
        );

        // L'ancienne campagne n'attend plus rien ; la nouvelle version est à relire.
        $this->assertNotSame(
            '',
            PolicyAcknowledgement::acknowledge($lines[$this->ids['bob']]['id'], $this->ids['bob'])
        );
        $mine = PolicyAcknowledgement::getMine($this->ids['alice']);
        $this->assertSame(['2.0', '1.0'], array_column($mine, 'version'));
        $this->assertTrue($mine[0]['pending']);
        $this->assertFalse($mine[1]['pending'], 'confirmation de la version 1.0 conservée');
        $this->assertCount(
            1,
            array_filter(PolicyAcknowledgement::getMine($this->ids['bob']), static fn ($r) => $r['pending'])
        );
    }

    public function testCloseArchivesTheReportAsEvidenceOnA51(): void
    {
        global $DB;

        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        PolicyAcknowledgement::launch($policy, 2);
        $campaign = PolicyAcknowledgement::getCurrentCampaign($policy->getID());

        $documentsId = PolicyAcknowledgement::close((int) $campaign['id']);
        $this->assertGreaterThan(0, $documentsId);
        $this->assertSame(-1, PolicyAcknowledgement::close((int) $campaign['id']), 'déjà close');
        $this->assertSame(
            AcknowledgementRules::STATUS_CLOSED,
            PolicyAcknowledgement::getCampaign((int) $campaign['id'])['status']
        );

        $control = $DB->request([
            'FROM'  => Control::getTable(),
            'WHERE' => ['code' => PolicyAcknowledgement::CONTROL_CODE],
        ])->current();
        $this->assertNotNull($control);
        foreach ([[Control::class, (int) $control['id']], [Policy::class, $policy->getID()]] as [$itemtype, $itemsId]) {
            $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
                'documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId,
            ]), $itemtype);
        }
        $this->assertSame(
            ['itemtype' => PolicyAcknowledgement::class, 'items_id' => (int) $campaign['id']],
            RestrictedDocument::sourceOf($documentsId)
        );
        // Le rapport (noms des retardataires) n'est jamais remis au public de la politique.
        $this->assertNotContains(
            $documentsId,
            array_column(PolicyAcknowledgement::getPolicyDocuments($policy->getID()), 'id')
        );
        $this->assertFalse(PolicyAcknowledgement::canDownload($policy->getID(), $documentsId, $this->ids['alice']));

        $lines = $this->linesByUser((int) $campaign['id']);
        $this->assertNotSame(
            '',
            PolicyAcknowledgement::acknowledge($lines[$this->ids['bob']]['id'], $this->ids['bob']),
            'campagne close'
        );
    }

    public function testRightsOnTheReportAndPolicyDocuments(): void
    {
        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        PolicyAcknowledgement::launch($policy, 2);
        $documentsId = (int) (new \Document())->add([
            'name' => 'PHPUnit charte mots de passe', 'entities_id' => $this->ids['entityA'],
        ]);
        (new Document_Item())->add(
            ['documents_id' => $documentsId, 'itemtype' => Policy::class, 'items_id' => $policy->getID()]
        );

        $this->assertTrue(PolicyAcknowledgement::canDownload($policy->getID(), $documentsId, $this->ids['alice']));
        $this->assertFalse(
            PolicyAcknowledgement::canDownload($policy->getID(), $documentsId, $this->ids['outsider']),
            'hors du public'
        );
        $this->assertFalse(
            PolicyAcknowledgement::canDownload($policy->getID(), 999999999, $this->ids['alice']),
            'autre document'
        );

        $campaign = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $this->assertTrue(PolicyAcknowledgement::canReadReport((int) $campaign['id']));
        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        try {
            $this->assertFalse(
                PolicyAcknowledgement::canReadReport((int) $campaign['id']),
                'sans droit de lecture des politiques'
            );
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
    }

    public function testPurgingThePolicyRemovesItsCampaigns(): void
    {
        $policy = $this->createPolicy();
        $this->defineAudience($policy);
        PolicyAcknowledgement::launch($policy, 2);
        $campaign = PolicyAcknowledgement::getCurrentCampaign($policy->getID());
        $policy->delete(['id' => $policy->getID()], true);

        $this->assertSame(0, countElementsInTable(PolicyAcknowledgement::CAMPAIGNS_TABLE, ['id' => $campaign['id']]));
        $this->assertSame(
            0,
            countElementsInTable(
                PolicyAcknowledgement::LINES_TABLE,
                ['plugin_grcmanager_policycampaigns_id' => $campaign['id']]
            )
        );
        $this->assertSame(
            0,
            countElementsInTable(
                PolicyAcknowledgement::AUDIENCE_TABLE,
                ['plugin_grcmanager_policies_id' => $policy->getID()]
            )
        );
    }
}
