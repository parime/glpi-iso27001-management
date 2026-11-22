<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Delegation;

use GlpiPlugin\Grcmanager\Services\Delegation\DelegationRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #618 : règles de la délégation des décisions pendant une absence.
 */
final class DelegationRulesTest extends TestCase
{
    private const NOW = '2026-08-07 10:00:00';

    public function testNothingIsDelegableByDefault(): void
    {
        $this->assertSame([], Rules::sanitizeTypes(null));
        $this->assertSame([], Rules::sanitizeTypes(''));
        $this->assertSame([], Rules::sanitizeTypes('not json'));
        $this->assertSame([], Rules::sanitizeTypes(['risk_acceptance', 'waiver']), 'décisions tenues par un droit');
    }

    public function testOnlyKnownTypesAreKeptInAStableOrder(): void
    {
        $this->assertSame(
            [Rules::TYPE_ACCESS_REVIEW, Rules::TYPE_POLICY_APPROVAL],
            Rules::sanitizeTypes(['policy_approval', ' access_review ', 'access_review', 'other'])
        );
        $this->assertSame([Rules::TYPE_SOA_APPROVAL], Rules::sanitizeTypes('["soa_approval"]'));
    }

    public function testThePeriodNeedsBothDates(): void
    {
        $this->assertFalse(Rules::isWithinPeriod(null, null, self::NOW), 'GLPI : illimité ; ici : rien');
        $this->assertFalse(Rules::isWithinPeriod('2026-08-01 00:00:00', null, self::NOW));
        $this->assertFalse(Rules::isWithinPeriod(null, '2026-08-15 00:00:00', self::NOW));
        $this->assertFalse(
            Rules::isWithinPeriod('2026-08-15 00:00:00', '2026-08-01 00:00:00', self::NOW),
            'fin avant début'
        );
    }

    public function testTheDelegationStopsByItselfAtTheEndOfThePeriod(): void
    {
        $this->assertTrue(Rules::isWithinPeriod('2026-08-01 00:00:00', '2026-08-15 23:59:59', self::NOW));
        $ended = Rules::isWithinPeriod('2026-08-01 00:00:00', '2026-08-07 09:59:59', self::NOW);
        $this->assertFalse($ended, 'terminée');
        $notStarted = Rules::isWithinPeriod('2026-08-07 10:00:01', '2026-08-15 00:00:00', self::NOW);
        $this->assertFalse($notStarted, 'pas commencée');
        $this->assertTrue(Rules::isWithinPeriod('2026-08-01', '2026-08-07', self::NOW), 'une date seule couvre la journée');
        $this->assertFalse(Rules::isWithinPeriod('2026-08-01', '2026-08-06', self::NOW));
    }

    public function testDatesAreNormalized(): void
    {
        $this->assertSame('2026-08-01 08:30:00', Rules::normalizeDateTime('2026-08-01T08:30'));
        $this->assertSame('2026-08-01 23:59:59', Rules::normalizeDateTime('2026-08-01', true));
        $this->assertNull(Rules::normalizeDateTime('01/08/2026'));
        $this->assertNull(Rules::normalizeDateTime(20260801));
    }

    public function testASubstituteDecidesOnlyTheLinesOfTheHolderAndNeverHisOwnAccount(): void
    {
        $this->assertTrue(Rules::canDecideAccessLine(7, 3, 3, 12));
        $this->assertFalse(Rules::canDecideAccessLine(7, 3, 4, 12), 'ligne d\'un autre responsable');
        $this->assertFalse(Rules::canDecideAccessLine(7, 3, 3, 7), 'son propre compte');
        $this->assertFalse(Rules::canDecideAccessLine(3, 3, 3, 12), 'pas de suppléance de soi-même');
        $this->assertFalse(Rules::canDecideAccessLine(0, 3, 3, 12));
    }

    public function testASubstituteNeverVotesTwice(): void
    {
        $this->assertTrue(Rules::canAnswerApproval(7, 3, [3, 4], [3]));
        $this->assertFalse(Rules::canAnswerApproval(7, 3, [3, 4], [4]), 'le titulaire a déjà répondu');
        $this->assertFalse(Rules::canAnswerApproval(7, 3, [3, 7], [3, 7]), 'le suppléant approuve aussi');
        $this->assertFalse(Rules::canAnswerApproval(7, 3, [4], [4]), 'titulaire non désigné');
        $this->assertFalse(Rules::canAnswerApproval(3, 3, [3], [3]));
    }
}
