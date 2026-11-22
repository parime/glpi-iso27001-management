<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyVersion;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationRules as Rules;
use GlpiPlugin\Grcmanager\SoaVersion;

/**
 * Issue #618 : délégation des décisions pendant une absence, sur une vraie instance. Exemple fictif :
 * Marie Exemple, absente, suppléée par Paul Test. Le suppléant décide pour le compte de la titulaire
 * pendant la période déclarée seulement, pour les types délégables seulement, jamais de son propre
 * compte ni en votant deux fois ; chaque décision est tracée. Aucune comparaison de texte traduit.
 */
final class DelegationTest extends GrcmanagerIntegrationTestCase
{
    private int $holder = 0;
    private int $substitute = 0;
    /** @var array<string, int> */
    private array $savedRights = [];

    protected function setUp(): void
    {
        global $DB;

        parent::setUp();
        $this->holder = $this->createTestUser('Marie', 'Exemple');
        $this->substitute = $this->createTestUser('Paul', 'Test');
        $this->absence('-1 day', '+7 days');
        $DB->insert('glpi_validatorsubstitutes', [
            'users_id'            => $this->holder,
            'users_id_substitute' => $this->substitute,
        ]);
        DelegationConfig::save(Rules::TYPES);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedRights as $name => $value) {
            $_SESSION['glpiactiveprofile'][$name] = $value;
        }
        $this->savedRights = [];
        parent::tearDown();
    }

    private function absence(?string $start, ?string $end): void
    {
        global $DB;

        $DB->update('glpi_users', [
            'substitution_start_date' => $start === null ? null : date('Y-m-d H:i:s', strtotime($start)),
            'substitution_end_date'   => $end === null ? null : date('Y-m-d H:i:s', strtotime($end)),
        ], ['id' => $this->holder]);
    }

    /** Le suppléant n'a aucun droit sur le plugin, comme un membre de la direction. */
    private function withoutPluginRights(): void
    {
        foreach (array_keys($_SESSION['glpiactiveprofile']) as $name) {
            if (str_starts_with((string) $name, 'plugin_grcmanager')) {
                $this->savedRights[$name] ??= (int) $_SESSION['glpiactiveprofile'][$name];
                $_SESSION['glpiactiveprofile'][$name] = 0;
            }
        }
    }

    public function testNothingIsDelegatedByDefaultNorWithoutBothDates(): void
    {
        $this->assertTrue(Delegation::isSubstituteOf($this->substitute, $this->holder, Rules::TYPE_ACCESS_REVIEW));

        DelegationConfig::save([]);
        $this->assertSame([], DelegationConfig::types());
        $this->assertSame([], Delegation::holdersFor($this->substitute, Rules::TYPE_ACCESS_REVIEW));

        DelegationConfig::save([Rules::TYPE_ACCESS_REVIEW]);
        $this->assertSame([], Delegation::holdersFor($this->substitute, Rules::TYPE_SOA_APPROVAL), 'type non choisi');
        $this->absence('-1 day', null);
        $this->assertSame([], Delegation::holdersFor($this->substitute, Rules::TYPE_ACCESS_REVIEW), 'période sans fin');
        $this->absence('-10 days', '-1 hour');
        $this->assertSame([], Delegation::holdersFor($this->substitute, Rules::TYPE_ACCESS_REVIEW), 'période terminée');
        $this->absence('+1 day', '+7 days');
        $this->assertSame([], Delegation::holdersFor($this->substitute, Rules::TYPE_ACCESS_REVIEW), 'pas commencée');
        $this->assertSame([], Delegation::holdersFor($this->holder, Rules::TYPE_ACCESS_REVIEW), 'sens unique');
    }

    public function testTheSubstituteReviewsTheAccountsOfTheAbsentHolderOnly(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Délégation');
        $campaign = new AccessReview();
        $campaignId = (int) $campaign->add([
            'name'         => 'PHPUnit revue du logiciel de comptabilité',
            'entities_id'  => $entityId,
            'is_recursive' => 0,
            'frequency'    => 'quarterly',
            'due_date'     => date('Y-m-d', strtotime('+15 days')),
        ]);
        $DB->update(AccessReview::getTable(), ['status' => 'open'], ['id' => $campaignId]);
        $other = $this->createTestUser('Olivier', 'Autre');
        $accounts = [$this->createTestUser('Alice', 'Agent'), $this->createTestUser('Bob', 'Agent')];
        $line = static function (int $account, int $reviewer) use ($DB, $campaignId): int {
            $DB->insert(AccessReview::LINES_TABLE, [
                'plugin_grcmanager_accessreviews_id' => $campaignId,
                'users_id'                           => $account,
                'reviewers_id'                       => $reviewer,
            ]);

            return (int) $DB->insertId();
        };
        $holderLine = $line($accounts[0], $this->holder);
        $secondLine = $line($accounts[1], $this->holder);
        $ownAccount = $line($this->substitute, $this->holder);
        $otherLine = $line($this->createTestUser('Carl', 'Agent'), $other);

        $delegated = array_column(AccessReview::getDelegatedLines($this->substitute), 'id');
        sort($delegated);
        $this->assertSame([$holderLine, $secondLine], $delegated, 'ni son compte ni les lignes d\'un autre');

        $this->withoutPluginRights();
        $this->assertSame('', AccessReview::decide($holderLine, 'keep', '', $this->substitute));
        $this->assertNotSame('', AccessReview::decide($ownAccount, 'keep', '', $this->substitute));
        $this->assertNotSame('', AccessReview::decide($otherLine, 'keep', '', $this->substitute));

        $row = $DB->request(['FROM' => AccessReview::LINES_TABLE, 'WHERE' => ['id' => $holderLine]])->current();
        $this->assertSame($this->substitute, (int) $row['decided_by']);
        $this->assertSame(1, (int) $row['is_delegated']);
        $lines = array_column(AccessReview::getLines($campaignId), null, 'id');
        $this->assertSame(
            Delegation::onBehalfLabel($this->substitute, $this->holder),
            $lines[$holderLine]['decided_by_name'],
            'mention « pour le compte de » (écran et PDF du rapport)'
        );
        $journal = $DB->request([
            'FROM'  => Delegation::TABLE,
            'WHERE' => ['itemtype' => AccessReview::class, 'items_id' => $campaignId],
        ]);
        $this->assertCount(1, $journal);
        $entry = $journal->current();
        $this->assertSame($this->holder, (int) $entry['users_id_holder']);
        $this->assertSame($this->substitute, (int) $entry['users_id_substitute']);
        $this->assertSame($entityId, (int) $entry['entities_id']);
        $this->assertNotNull($entry['date_end'], 'période d\'absence tracée');

        // Fin de l'absence : la délégation cesse d'elle-même.
        $this->absence('-10 days', '-1 minute');
        $this->assertSame([], AccessReview::getDelegatedLines($this->substitute));
        $this->assertNotSame('', AccessReview::decide($secondLine, 'keep', '', $this->substitute));
        // La titulaire revenue reprend la main : sa décision n'est plus marquée comme déléguée.
        $this->assertSame('', AccessReview::decide($holderLine, 'keep', 'Vu au retour', $this->holder));
        $row = $DB->request(['FROM' => AccessReview::LINES_TABLE, 'WHERE' => ['id' => $holderLine]])->current();
        $this->assertSame(0, (int) $row['is_delegated']);
    }

    public function testTheSubstituteAnswersAnSoaVersionOnBehalfOfTheHolderOnce(): void
    {
        global $DB;

        $other = $this->createTestUser('Olivier', 'Autre');
        $DB->insert(SoaVersion::getTable(), [
            'version'  => random_int(900000, 999999),
            'status'   => 'pending',
            'users_id' => 2,
        ]);
        $versionId = (int) $DB->insertId();
        foreach ([$this->holder, $other] as $approver) {
            $DB->insert(SoaVersion::APPROVERS_TABLE, [
                'plugin_grcmanager_soaversions_id' => $versionId,
                'users_id'                         => $approver,
            ]);
        }

        $onBehalf = array_map('intval', array_column(SoaVersion::pendingOnBehalf($this->substitute), 'id'));
        $this->assertContains($versionId, $onBehalf);
        $this->assertFalse(SoaVersion::answer($versionId, $other, true, '', $this->substitute), 'pas suppléant d\'Olivier');
        $this->assertTrue(SoaVersion::answer($versionId, $this->holder, true, 'Validé en son absence', $this->substitute));
        $this->assertFalse(SoaVersion::answer($versionId, $this->holder, true, '', $this->substitute), 'déjà répondu');

        $approvers = array_column(SoaVersion::getApprovers($versionId), null, 'users_id');
        $this->assertSame($this->substitute, $approvers[$this->holder]['users_id_substitute']);
        $label = Delegation::onBehalfLabel($this->substitute, $this->holder);
        $this->assertSame($label, $approvers[$this->holder]['name'], 'historique, PDF, notifications');
        $this->assertSame(0, $approvers[$other]['users_id_substitute']);
        $this->assertSame(1, countElementsInTable(Delegation::TABLE, [
            'itemtype' => SoaVersion::class, 'items_id' => $versionId, 'decision_type' => Rules::TYPE_SOA_APPROVAL,
        ]));
    }

    public function testASubstituteWhoApprovesTheSameVersionCannotAnswerForTheHolder(): void
    {
        global $DB;

        $policy = new Policy();
        $policyId = (int) $policy->add(['title' => 'PHPUnit politique de télétravail', 'version' => '1.0']);
        $DB->insert(PolicyVersion::getTable(), [
            'plugin_grcmanager_policies_id' => $policyId,
            'version'                       => '1.0',
            'status'                        => 'pending',
            'quorum'                        => 0,
        ]);
        $versionId = (int) $DB->insertId();
        foreach ([$this->holder, $this->substitute] as $approver) {
            $DB->insert(PolicyVersion::APPROVERS_TABLE, [
                'plugin_grcmanager_policyversions_id' => $versionId,
                'users_id'                            => $approver,
            ]);
        }

        $this->assertSame([], PolicyVersion::pendingOnBehalf($this->substitute), 'il voterait deux fois');
        $this->assertFalse(PolicyVersion::answer($versionId, $this->holder, true, '', $this->substitute));

        // Sans être lui-même approbateur, il répond pour la titulaire et lit la version figée.
        $DB->delete(PolicyVersion::APPROVERS_TABLE, [
            'plugin_grcmanager_policyversions_id' => $versionId,
            'users_id'                            => $this->substitute,
        ]);
        $DB->insert(PolicyVersion::APPROVERS_TABLE, [
            'plugin_grcmanager_policyversions_id' => $versionId,
            'users_id'                            => $this->createTestUser('Olivier', 'Autre'),
        ]);
        $this->withoutPluginRights();
        $this->assertTrue(PolicyVersion::canReadPdf($versionId, $this->substitute));
        $this->assertTrue(PolicyVersion::answer($versionId, $this->holder, true, '', $this->substitute));
        $this->assertFalse(PolicyVersion::canReadPdf($versionId, $this->substitute), 'plus rien à décider');
        $approvers = array_column(PolicyVersion::getApprovers($versionId), null, 'users_id');
        $this->assertSame($this->substitute, $approvers[$this->holder]['users_id_substitute']);

        // Type retiré de la configuration : plus aucune version proposée.
        DelegationConfig::save([Rules::TYPE_ACCESS_REVIEW]);
        $this->assertSame([], PolicyVersion::pendingOnBehalf($this->substitute));
    }
}
