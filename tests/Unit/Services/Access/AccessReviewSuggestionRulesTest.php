<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AccessReviewSuggestionRules as Rules;
use PHPUnit\Framework\TestCase;

final class AccessReviewSuggestionRulesTest extends TestCase
{
    private const NOW = '2026-06-15 10:00:00';

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function codes(array $facts, array $settings = []): array
    {
        return array_column(Rules::suggest($facts, $settings, self::NOW)['reasons'], 'code');
    }

    public function testAnAccountWithoutAnySignalIsSuggestedToKeep(): void
    {
        $facts = ['last_login' => '2026-06-10 08:00:00', 'date_creation' => '2025-01-01'];
        $result = Rules::suggest($facts, [], self::NOW);

        $this->assertSame('keep', $result['suggestion']);
        $this->assertSame([['code' => 'no_signal', 'params' => ['date' => '2026-06-10']]], $result['reasons']);
    }

    public function testDeletedFromTheDirectoryExpiredOrDepartedAccountsAreSuggestedForRemoval(): void
    {
        $this->assertSame('remove', Rules::suggest(['is_deleted_ldap' => 1], [], self::NOW)['suggestion']);
        $expired = Rules::suggest(['end_date' => '2026-06-01 00:00:00'], [], self::NOW);
        $this->assertSame('remove', $expired['suggestion']);
        $this->assertSame(['code' => 'expired', 'params' => ['date' => '2026-06-01']], $expired['reasons'][0]);
        $this->assertSame('remove', Rules::suggest(['departure_date' => '2026-05-31'], [], self::NOW)['suggestion']);
    }

    public function testAnUpcomingDepartureIsToExamineWithinTheConfiguredHorizon(): void
    {
        $result = Rules::suggest(
            ['departure_date' => '2026-07-10', 'end_date' => '2026-06-30', 'last_login' => '2026-06-14 09:00:00'],
            [],
            self::NOW
        );
        $this->assertSame('examine', $result['suggestion']);
        $this->assertSame(
            ['code' => 'leaving', 'params' => ['date' => '2026-06-30', 'days' => 30]],
            $result['reasons'][0]
        );

        $later = ['departure_date' => '2026-08-30', 'last_login' => '2026-06-14'];
        $this->assertSame(['no_signal'], $this->codes($later));
        $this->assertSame(['no_signal'], $this->codes(['departure_date' => '2026-06-20'], ['leaving_days' => 0]));
    }

    public function testInactiveAndNeverUsedAccountsFollowTheConfiguredDays(): void
    {
        $this->assertSame(['inactive'], $this->codes(['last_login' => '2026-01-10 08:00:00']));
        $january = ['last_login' => '2026-01-10 08:00:00'];
        $this->assertSame(['no_signal'], $this->codes($january, ['inactive_days' => 180]));
        $this->assertSame(['no_signal'], $this->codes(['last_login' => '2026-01-10 08:00:00'], ['inactive_days' => 0]));
        $this->assertSame(['never_used'], $this->codes(['date_creation' => '2025-12-01 08:00:00']));
        $this->assertSame(['no_signal'], $this->codes(['date_creation' => '2026-06-01 08:00:00']), 'compte neuf');
    }

    public function testPrivilegedProfilesAreToExamineAndTheReasonSaysWhenNoMfaIsKnown(): void
    {
        $facts = ['last_login' => '2026-06-14 08:00:00', 'privileged_profiles' => ['Super-Admin']];

        $result = Rules::suggest($facts + ['mfa_level' => 'strong'], [], self::NOW);
        $this->assertSame('examine', $result['suggestion']);
        $this->assertSame(['code' => 'privileged', 'params' => ['profiles' => ['Super-Admin']]], $result['reasons'][0]);

        $this->assertSame(['privileged_no_mfa'], $this->codes($facts + ['mfa_level' => 'none']));
        $this->assertSame(['privileged'], $this->codes($facts + ['mfa_level' => 'none'], ['mfa' => 0]));
        $this->assertSame(['privileged'], $this->codes($facts + ['mfa_level' => null]), 'MFA inconnue : rien dit');
        $this->assertSame(['no_signal'], $this->codes($facts, ['privileged' => 0]));
    }

    public function testAGroupChangeIsToExamineUnlessTheRuleIsOff(): void
    {
        $facts = ['last_login' => '2026-06-14 08:00:00', 'group_changed' => true];

        $this->assertSame(['group_changed'], $this->codes($facts));
        $this->assertSame(['no_signal'], $this->codes($facts, ['group_change' => '0']));
    }

    public function testRemovalReasonsComeFirstAndAllReasonsAreKept(): void
    {
        $this->assertSame(
            ['directory_deleted', 'expired', 'inactive', 'privileged'],
            $this->codes([
                'is_deleted_ldap'     => 1,
                'end_date'            => '2026-06-01',
                'last_login'          => '2025-06-01 08:00:00',
                'privileged_profiles' => ['Admin'],
            ])
        );
    }

    public function testSettingsAreNormalized(): void
    {
        $this->assertSame(Rules::defaults(), Rules::normalize(null));
        $this->assertSame(Rules::defaults(), Rules::normalize('not json'));
        $this->assertSame(
            [
                'inactive_days' => 90,
                'leaving_days'  => 0,
                'privileged'    => false,
                'mfa'           => true,
                'group_change'  => true,
                'compliance'    => true,
            ],
            Rules::normalize(['inactive_days' => 45, 'leaving_days' => '0', 'privileged' => '0'])
        );
        $this->assertSame(180, Rules::normalize('{"inactive_days":180}')['inactive_days']);
    }

    public function testOnlyRealStaffComplianceGapsAreKept(): void
    {
        $components = [
            [
                'key' => 'training', 'status' => 'late', 'code' => 'renewal_overdue',
                'params' => ['titles' => ['Phishing']],
            ],
            [
                'key' => 'charter', 'status' => 'late', 'code' => 'pending',
                'params' => ['campaign' => 'IT charter 2026'],
            ],
            ['key' => 'policies', 'status' => 'pending', 'code' => 'pending', 'params' => ['titles' => ['Passwords']]],
            // Aucune campagne ni donnée RH saisie : pas un manquement de la personne.
            ['key' => 'charter', 'status' => 'late', 'code' => 'none', 'params' => []],
            ['key' => 'training', 'status' => 'late', 'code' => 'none', 'params' => []],
            ['key' => 'nda', 'status' => 'late', 'code' => 'missing', 'params' => []],
            ['key' => 'screening', 'status' => 'late', 'code' => 'missing', 'params' => []],
            ['key' => 'access_review', 'status' => 'late', 'code' => 'never', 'params' => []],
        ];

        $this->assertSame(
            [
                ['component' => 'training', 'detail' => 'renewal_overdue', 'titles' => ['Phishing']],
                ['component' => 'charter', 'detail' => 'pending', 'titles' => ['IT charter 2026']],
            ],
            Rules::complianceGaps($components)
        );
    }

    public function testAStaffComplianceGapIsToExamineUnlessTheRuleIsOff(): void
    {
        $facts = [
            'last_login'      => '2026-06-14 09:00:00',
            'compliance_gaps' => [
                ['component' => 'training', 'detail' => 'pending', 'titles' => ['Security basics']],
                ['component' => 'nda', 'detail' => 'missing', 'titles' => []],
            ],
        ];
        $result = Rules::suggest($facts, [], self::NOW);

        $this->assertSame('examine', $result['suggestion'], 'jamais « retirer » pour un écart de conformité');
        $this->assertSame(
            [[
                'code'   => 'compliance_gap',
                'params' => ['component' => 'training', 'detail' => 'pending', 'titles' => ['Security basics']],
            ]],
            $result['reasons'],
            'seuls les signaux connus sont retenus'
        );
        $this->assertSame(['no_signal'], $this->codes($facts, ['compliance' => '0']));
    }

    public function testKeepingAnAccountSuggestedForRemovalNeedsAComment(): void
    {
        $this->assertTrue(Rules::mustJustify('remove', 'keep', '  '));
        $this->assertFalse(Rules::mustJustify('remove', 'keep', 'Prestataire prolongé'));
        $this->assertFalse(Rules::mustJustify('examine', 'keep', ''));
        $this->assertFalse(Rules::mustJustify('remove', 'remove', ''));
    }

    public function testDecisionsOppositeToTheSuggestionAreFlagged(): void
    {
        $this->assertTrue(Rules::differs('remove', 'keep'));
        $this->assertTrue(Rules::differs('keep', 'remove'));
        $this->assertTrue(Rules::differs('keep', 'modify'));
        $this->assertFalse(Rules::differs('examine', 'keep'));
        $this->assertFalse(Rules::differs('remove', 'modify'));
        $this->assertFalse(Rules::differs('', 'keep'));
    }

    public function testCounts(): void
    {
        $this->assertSame(
            ['remove' => 1, 'examine' => 2, 'keep' => 1],
            Rules::counts(['examine', 'keep', 'remove', 'examine', '', 'other'])
        );
    }
}
