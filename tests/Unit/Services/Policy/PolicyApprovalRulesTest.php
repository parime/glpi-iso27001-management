<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #611 : règles de l'approbation des politiques (règle de validation, empreinte, nouvelle
 * version exigée, contenu figé).
 */
final class PolicyApprovalRulesTest extends TestCase
{
    private const POLICY = [
        'title'       => 'Politique de télétravail',
        'version'     => '1.2',
        'description' => "Règles du travail à distance.\r\nVPN obligatoire.",
        'users_id'    => 5,
    ];

    public function testFingerprintIsStableAndIgnoresOrderLineEndingsAndOtherFields(): void
    {
        $reference = Rules::fingerprint(self::POLICY, ['bbb', 'aaa']);

        self::assertSame(64, strlen($reference));
        self::assertSame($reference, Rules::fingerprint(
            ['description' => "Règles du travail à distance.\nVPN obligatoire.  "] + self::POLICY,
            ['AAA', 'bbb', 'aaa']
        ));
        self::assertSame($reference, Rules::fingerprint(['users_id' => 9] + self::POLICY, ['aaa', 'bbb']));
    }

    public function testFingerprintChangesWithContentOrDocuments(): void
    {
        $reference = Rules::fingerprint(self::POLICY, ['aaa']);

        self::assertNotSame($reference, Rules::fingerprint(['version' => '1.3'] + self::POLICY, ['aaa']));
        self::assertNotSame($reference, Rules::fingerprint(['title' => 'Autre'] + self::POLICY, ['aaa']));
        self::assertNotSame($reference, Rules::fingerprint(self::POLICY, ['aaa', 'ccc']));
        self::assertNotSame($reference, Rules::fingerprint(self::POLICY, []));
    }

    public function testEveryApproverMustAgreeByDefault(): void
    {
        self::assertSame(Rules::VERSION_PENDING, Rules::versionStatus(['approved', 'pending'], Rules::QUORUM_ALL));
        self::assertSame(Rules::VERSION_APPROVED, Rules::versionStatus(['approved', 'approved'], Rules::QUORUM_ALL));
        self::assertSame(Rules::VERSION_PENDING, Rules::versionStatus([], Rules::QUORUM_ALL));
    }

    public function testMinimumNumberOfApprovals(): void
    {
        self::assertSame(Rules::VERSION_PENDING, Rules::versionStatus(['approved', 'pending', 'pending'], 2));
        self::assertSame(Rules::VERSION_APPROVED, Rules::versionStatus(['approved', 'pending', 'approved'], 2));
    }

    public function testOneRejectionRejectsWhateverTheRule(): void
    {
        self::assertSame(Rules::VERSION_REJECTED, Rules::versionStatus(['approved', 'rejected'], Rules::QUORUM_ALL));
        self::assertSame(Rules::VERSION_REJECTED, Rules::versionStatus(['rejected', 'pending', 'pending'], 1));
    }

    public function testRuleMustBeReachableWithTheApprovers(): void
    {
        self::assertTrue(Rules::isReachable(Rules::QUORUM_ALL, 2));
        self::assertTrue(Rules::isReachable(2, 2));
        self::assertFalse(Rules::isReachable(3, 2));
        self::assertFalse(Rules::isReachable(Rules::QUORUM_ALL, 0));
    }

    public function testQuorumSanitizingAndDefault(): void
    {
        self::assertSame(Rules::QUORUM_DEFAULT, Rules::sanitizeQuorum('-1'));
        self::assertSame(Rules::QUORUM_ALL, Rules::sanitizeQuorum('-1', false));
        self::assertSame(3, Rules::sanitizeQuorum('3'));
        self::assertSame(Rules::QUORUM_ALL, Rules::sanitizeQuorum('99'));
        self::assertSame(Rules::QUORUM_DEFAULT, Rules::sanitizeQuorum('abc'));
        self::assertSame(2, Rules::effectiveQuorum(Rules::QUORUM_DEFAULT, 2));
        self::assertSame(Rules::QUORUM_ALL, Rules::effectiveQuorum(Rules::QUORUM_ALL, 2));
        self::assertSame(1, Rules::effectiveQuorum(1, 2));
    }

    public function testAnApprovedVersionLabelRequiresANewVersion(): void
    {
        self::assertTrue(Rules::needsNewVersion('1.2', ['1.1', '1.2']));
        self::assertTrue(Rules::needsNewVersion(' V1.2 ', ['v1.2']));
        self::assertFalse(Rules::needsNewVersion('1.3', ['1.1', '1.2']));
        self::assertFalse(Rules::needsNewVersion('1.0', []));
    }

    public function testContentIsLockedWhilePendingOrOnceApproved(): void
    {
        self::assertTrue(Rules::isContentLocked('approved', false));
        self::assertTrue(Rules::isContentLocked('draft', true));
        self::assertFalse(Rules::isContentLocked('draft', false));
        self::assertFalse(Rules::isContentLocked('archived', false));
    }

    public function testChangedContentFieldsOnlyListsRealChanges(): void
    {
        self::assertSame([], Rules::changedContentFields(self::POLICY, ['title' => ' Politique de télétravail ']));
        self::assertSame([], Rules::changedContentFields(self::POLICY, ['next_review_date' => '2027-01-01']));
        self::assertSame(
            ['version', 'description'],
            Rules::changedContentFields(self::POLICY, ['version' => '1.3', 'description' => 'Nouveau'])
        );
    }

    public function testCurrentStateOfAnApprovedPolicy(): void
    {
        self::assertSame('not_approved', Rules::currentState('draft', 'abc', 'abc'));
        self::assertSame('legacy', Rules::currentState('approved', null, 'abc'));
        self::assertSame('legacy', Rules::currentState('approved', '', 'abc'));
        self::assertSame('up_to_date', Rules::currentState('approved', 'abc', 'abc'));
        self::assertSame('changed', Rules::currentState('approved', 'abc', 'abd'));
    }
}
