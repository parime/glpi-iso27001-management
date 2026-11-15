<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Config;
use Document_Item;
use Group;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\PolicyVersion;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules as Rules;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use Profile_User;
use User;

/**
 * Issue #611 : approbation des politiques de sécurité par la direction, sur une vraie instance :
 * version figée et empreintes, règle de validation, refus, contenu figé, nouvelle version,
 * politiques déjà approuvées, campagne de prise de connaissance, preuve sur A.5.1.
 * Indépendant de la langue de l'instance : aucune comparaison de texte traduit.
 */
final class PolicyApprovalTest extends GrcmanagerIntegrationTestCase
{
    private function enable(array $approvers = [], int $quorum = Rules::QUORUM_ALL): void
    {
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [
            PolicyApprovalConfig::ENABLED_KEY   => '1',
            PolicyApprovalConfig::APPROVERS_KEY => (string) json_encode($approvers),
            PolicyApprovalConfig::QUORUM_KEY    => (string) $quorum,
        ]);
    }

    private function user(string $first, string $last): int
    {
        return $this->createTestUser($first, $last);
    }

    private function policy(string $status = 'draft', string $version = '1.2'): Policy
    {
        $policy = new Policy();
        $id = (int) $policy->add([
            'title'         => 'Politique de télétravail',
            'version'       => $version,
            'status'        => $status,
            'approval_date' => $status === 'approved' ? '2026-01-15' : null,
            'description'   => 'Règles du travail à distance (fictif).',
            'users_id'      => 2,
        ]);
        $this->assertGreaterThan(0, $id);
        $policy->getFromDB($id);

        return $policy;
    }

    /**
     * @param list<int> $users
     */
    private function designate(Policy $policy, array $users, int $quorum = Rules::QUORUM_DEFAULT): void
    {
        PolicyVersion::setDesignated(
            $policy,
            array_map(static fn (int $id): array => ['itemtype' => User::class, 'items_id' => $id], $users),
            $quorum
        );
        $policy->getFromDB($policy->getID());
    }

    private function submit(Policy $policy): PolicyVersion
    {
        $id = PolicyVersion::submit($policy, 'PHPUnit');
        $this->assertGreaterThan(0, $id);
        $version = new PolicyVersion();
        $version->getFromDB($id);
        $policy->getFromDB($policy->getID());

        return $version;
    }

    private function reload(Policy $policy): Policy
    {
        $policy->getFromDB($policy->getID());

        return $policy;
    }

    public function testDisabledByDefaultNothingChanges(): void
    {
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [PolicyApprovalConfig::ENABLED_KEY => '0']);
        $this->assertFalse(PolicyApprovalConfig::isEnabled());
        $policy = $this->policy();
        $this->assertTrue($policy->update(['id' => $policy->getID(), 'status' => 'approved', 'approval_date' => '2026-02-01']));
        $this->assertSame('approved', $this->reload($policy)->fields['status']);
        $this->assertSame(0, PolicyVersion::submit($this->policy(), ''), 'circuit désactivé');
    }

    public function testManualApprovalIsRefusedOnceEnabled(): void
    {
        $this->enable();
        $policy = $this->policy();
        $this->assertFalse($policy->update(['id' => $policy->getID(), 'status' => 'approved', 'approval_date' => '2026-02-01']));
        $this->assertSame('draft', $this->reload($policy)->fields['status']);
        $this->assertFalse((bool) (new Policy())->add(['title' => 'Autre', 'status' => 'approved', 'approval_date' => '2026-02-01']));
        // La date d'approbation n'est plus saisie à la main.
        $policy->update(['id' => $policy->getID(), 'approval_date' => '2026-02-01']);
        $this->assertNull($this->reload($policy)->fields['approval_date']);
    }

    public function testSubmissionFreezesTheVersionWithBothFingerprints(): void
    {
        $alice = $this->user('Alice', 'Direction');
        $this->enable();
        $policy = $this->policy();
        $this->designate($policy, [$alice]);
        $version = $this->submit($policy);

        $this->assertSame(Rules::VERSION_PENDING, $version->fields['status']);
        $this->assertSame('1.2', $version->fields['version']);
        $this->assertSame(PolicyVersion::currentFingerprint($policy), $version->fields['fingerprint']);
        $document = new \Document();
        $this->assertTrue($document->getFromDB((int) $version->fields['documents_id']));
        $this->assertSame(
            $version->fields['pdf_sha256'],
            hash_file('sha256', GLPI_DOC_DIR . '/' . $document->fields['filepath']),
            'l\'empreinte prouve exactement le document figé'
        );
        $this->assertSame(PolicyVersion::class, RestrictedDocument::sourceOf($document->getID())['itemtype'] ?? null);
        $this->assertSame('draft', $policy->fields['status'], 'pas encore approuvée');

        // Contenu figé pendant l'approbation ; le reste (prochaine revue) reste modifiable.
        $this->assertFalse($policy->update(['id' => $policy->getID(), 'description' => 'Modifiée']));
        $this->assertTrue($policy->update(['id' => $policy->getID(), 'next_review_date' => '2027-06-01']));
        $this->assertSame(0, PolicyVersion::submit($this->reload($policy), ''), 'une seule demande à la fois');

        // Retirer la demande rend le contenu modifiable.
        $this->assertTrue(PolicyVersion::withdraw($version->getID()));
        $this->assertTrue($policy->update(['id' => $policy->getID(), 'description' => 'Modifiée']));
        $this->assertFalse(PolicyVersion::answer($version->getID(), $alice, true, ''), 'demande retirée');
    }

    public function testStatusChangesOnlyOnceEveryApproverHasApproved(): void
    {
        $alice = $this->user('Alice', 'Direction');
        $bruno = $this->user('Bruno', 'Rssi');
        $outsider = $this->user('Oscar', 'Dehors');
        $this->enable();
        $policy = $this->policy();
        $this->designate($policy, [$alice, $bruno]);
        $version = $this->submit($policy);

        $this->assertFalse(PolicyVersion::answer($version->getID(), $outsider, true, ''), 'non approbateur');
        $this->assertTrue(PolicyVersion::answer($version->getID(), $alice, true, ''));
        $this->assertFalse(PolicyVersion::answer($version->getID(), $alice, true, ''), 'une seule réponse');
        $this->assertSame('draft', $this->reload($policy)->fields['status']);
        $this->assertSame([], PolicyVersion::pendingForUser($alice));
        $this->assertContains('policy_approval', array_column(MySpaceProvider::items($bruno, date('Y-m-d')), 'source'));

        $this->assertTrue(PolicyVersion::answer($version->getID(), $bruno, true, 'Validé'));
        $this->reload($policy);
        $this->assertSame('approved', $policy->fields['status']);
        $this->assertSame(date('Y-m-d'), $policy->fields['approval_date'], 'date de la dernière approbation');
        $version->getFromDB($version->getID());
        $this->assertSame(Rules::VERSION_APPROVED, $version->fields['status']);
        $this->assertSame('up_to_date', PolicyVersion::currentState($policy));
        $this->assertNotContains('policy_approval', array_column(MySpaceProvider::items($bruno, date('Y-m-d')), 'source'));

        // Procès-verbal archivé comme preuve sur la politique et sur A.5.1, réservé.
        $recordId = (int) $version->fields['record_documents_id'];
        $this->assertGreaterThan(0, $recordId);
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => 'A.5.1']));
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $recordId, 'itemtype' => Control::class, 'items_id' => $control->getID(),
        ]));
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $recordId, 'itemtype' => Policy::class, 'items_id' => $policy->getID(),
        ]));
        $this->assertNotNull(RestrictedDocument::sourceOf($recordId));
        $this->assertSame([], PolicyVersion::policyDocuments($policy->getID()), 'le procès-verbal n\'entre pas dans l\'empreinte');
    }

    public function testMinimumNumberOfApprovalsAndGroups(): void
    {
        global $DB;

        $alice = $this->user('Alice', 'Direction');
        $bruno = $this->user('Bruno', 'Rssi');
        $chloe = $this->user('Chloe', 'Daf');
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit Direction', 'entities_id' => 0]);
        foreach ([$bruno, $chloe] as $member) {
            $DB->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $member]);
        }
        // Approbateurs par défaut de la configuration : une personne et un groupe, au moins deux accords.
        $this->enable([
            ['itemtype' => User::class, 'items_id' => $alice],
            ['itemtype' => Group::class, 'items_id' => $groupId],
        ], 2);
        $policy = $this->policy();
        $version = $this->submit($policy);
        $this->assertCount(3, PolicyVersion::getApprovers($version->getID()));
        $this->assertSame(2, (int) $version->fields['quorum']);

        PolicyVersion::answer($version->getID(), $alice, true, '');
        $this->assertSame('draft', $this->reload($policy)->fields['status']);
        PolicyVersion::answer($version->getID(), $chloe, true, '');
        $this->assertSame('approved', $this->reload($policy)->fields['status']);
        $this->assertSame([], PolicyVersion::pendingForUser($bruno), 'décision prise, plus rien à approuver');

        // Règle impossible à atteindre : demande refusée.
        $other = $this->policy('draft', '2.0');
        $this->designate($other, [$alice], 3);
        $this->assertSame(0, PolicyVersion::submit($other, ''));
    }

    public function testRejectionNeedsACommentAndKeepsTheDraft(): void
    {
        $alice = $this->user('Alice', 'Direction');
        $bruno = $this->user('Bruno', 'Rssi');
        $this->enable();
        $policy = $this->policy();
        $this->designate($policy, [$alice, $bruno]);
        $version = $this->submit($policy);

        $this->assertFalse(PolicyVersion::answer($version->getID(), $bruno, false, '  '), 'commentaire obligatoire');
        PolicyVersion::answer($version->getID(), $alice, true, '');
        $this->assertTrue(PolicyVersion::answer($version->getID(), $bruno, false, 'Préciser le VPN.'));
        $version->getFromDB($version->getID());
        $this->assertSame(Rules::VERSION_REJECTED, $version->fields['status']);
        $this->assertSame('draft', $this->reload($policy)->fields['status']);

        // Le rédacteur corrige puis soumet à nouveau.
        $this->assertTrue($policy->update(['id' => $policy->getID(), 'description' => 'VPN obligatoire (fictif).']));
        $second = $this->submit($this->reload($policy));
        $this->assertNotSame($version->fields['fingerprint'], $second->fields['fingerprint']);
        $this->assertSame(2, (int) $second->fields['revision']);
    }

    public function testAnyChangeAfterApprovalRequiresANewVersion(): void
    {
        $alice = $this->user('Alice', 'Direction');
        $this->enable();
        $policy = $this->policy();
        $this->designate($policy, [$alice]);
        PolicyVersion::answer($this->submit($policy)->getID(), $alice, true, '');
        $this->reload($policy);

        $this->assertFalse($policy->update(['id' => $policy->getID(), 'title' => 'Autre titre']));
        $this->assertFalse($policy->update(['id' => $policy->getID(), 'version' => '1.3']), 'nouvelle version explicite');
        $this->assertSame('approved', $this->reload($policy)->fields['status']);
        $this->assertFalse(PolicyVersion::prepareNewVersion($policy, '1.2'), 'numéro déjà approuvé');

        $this->assertTrue(PolicyVersion::prepareNewVersion($policy, '1.3'));
        $this->reload($policy);
        $this->assertSame('draft', $policy->fields['status']);
        $this->assertSame('1.3', $policy->fields['version']);
        $this->assertTrue($policy->update(['id' => $policy->getID(), 'description' => 'Version 1.3 (fictif).']));

        // Un numéro déjà approuvé ne se soumet pas à nouveau.
        $policy->update(['id' => $policy->getID(), 'version' => '1.2']);
        $this->assertSame(0, PolicyVersion::submit($this->reload($policy), ''));
    }

    public function testRequesterCannotApproveHisOwnRequest(): void
    {
        $this->enable();
        $policy = $this->policy();
        $this->designate($policy, [(int) $_SESSION['glpiID']]);
        $this->assertSame(0, PolicyVersion::submit($policy, ''), 'seul approbateur : le demandeur, retiré');
    }

    public function testExistingApprovedPoliciesStayApprovedWithoutFingerprint(): void
    {
        $policy = $this->policy('approved', '3.0');
        $this->enable();
        $this->assertGreaterThanOrEqual(1, PolicyVersion::markLegacyApproved());
        $this->assertSame(0, PolicyVersion::markLegacyApproved(), 'idempotent');
        $this->reload($policy);
        $this->assertSame('approved', $policy->fields['status']);
        $this->assertSame('2026-01-15', $policy->fields['approval_date']);
        $legacy = PolicyVersion::latest($policy->getID(), Rules::VERSION_APPROVED);
        $this->assertSame(1, (int) $legacy['is_legacy']);
        $this->assertSame('', $legacy['fingerprint']);
        $this->assertSame('legacy', PolicyVersion::currentState($policy));
        $this->assertFalse(PolicyVersion::prepareNewVersion($policy, '3.0'));
    }

    public function testAcknowledgementCampaignFollowsTheApprovedVersionOnly(): void
    {
        global $DB;

        $alice = $this->user('Alice', 'Direction');
        $reader = $this->user('Lea', 'Lecture');
        $entity = $this->createTestEntity(0, 'PHPUnit Siège');
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit Lecteurs', 'entities_id' => $entity]);
        $DB->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $reader]);
        $DB->insert(Profile_User::getTable(), [
            'users_id' => $reader, 'profiles_id' => 1, 'entities_id' => $entity, 'is_recursive' => 0,
        ]);

        $policy = $this->policy('approved', '1.0');
        $this->assertTrue(PolicyAcknowledgement::addAudience($policy->getID(), 'Group', $groupId));
        $this->assertSame(1, PolicyAcknowledgement::launch($policy, 2));
        $this->enable();
        PolicyVersion::markLegacyApproved();
        $this->designate($policy, [$alice]);

        // Nouvelle version en préparation, puis en attente : aucune campagne.
        $this->assertTrue(PolicyVersion::prepareNewVersion($this->reload($policy), '1.1'));
        $version = $this->submit($this->reload($policy));
        $this->assertSame('1.0', PolicyAcknowledgement::getCurrentCampaign($policy->getID())['version']);

        // Approuvée : la campagne de la nouvelle version est lancée.
        PolicyVersion::answer($version->getID(), $alice, true, '');
        $this->assertSame('1.1', PolicyAcknowledgement::getCurrentCampaign($policy->getID())['version']);
    }
}
