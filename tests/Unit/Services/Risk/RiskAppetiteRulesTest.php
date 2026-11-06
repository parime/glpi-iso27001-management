<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskAppetiteRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskAppetiteRulesTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?string, ?string, string}>
     */
    public static function positions(): array
    {
        return [
            'au niveau de l\'appétence'     => ['medium', 'medium', 'high', RiskAppetiteRules::WITHIN],
            'sous l\'appétence'              => ['low', 'medium', 'high', RiskAppetiteRules::WITHIN],
            'entre appétence et tolérance'   => ['high', 'medium', 'high', RiskAppetiteRules::ABOVE_APPETITE],
            'au-delà de la tolérance'        => ['critical', 'medium', 'high', RiskAppetiteRules::ABOVE_TOLERANCE],
            'tolérance vide = appétence'     => ['high', 'medium', '', RiskAppetiteRules::ABOVE_TOLERANCE],
            'appétence non définie'          => ['critical', null, null, RiskAppetiteRules::UNDEFINED],
            'tolérance sous l\'appétence'    => ['critical', 'high', 'low', RiskAppetiteRules::UNDEFINED],
            'niveau inconnu'                 => ['extreme', 'low', 'high', RiskAppetiteRules::UNDEFINED],
        ];
    }

    #[DataProvider('positions')]
    public function testPosition(?string $level, ?string $appetite, ?string $tolerance, string $expected): void
    {
        self::assertSame($expected, RiskAppetiteRules::position($level, $appetite, $tolerance));
    }

    public function testValidPair(): void
    {
        self::assertTrue(RiskAppetiteRules::isValidPair('low', 'critical'));
        self::assertTrue(RiskAppetiteRules::isValidPair('high', 'high'));
        self::assertTrue(RiskAppetiteRules::isValidPair('medium', null));
        self::assertFalse(RiskAppetiteRules::isValidPair('high', 'medium'));
        self::assertFalse(RiskAppetiteRules::isValidPair('', 'high'));
        self::assertFalse(RiskAppetiteRules::isValidPair('medium', 'x'));
    }

    public function testDecisionIsNeededOnlyAboveAppetiteWithoutTreatment(): void
    {
        self::assertTrue(RiskAppetiteRules::needsDecision(RiskAppetiteRules::ABOVE_APPETITE, '', 'identified'));
        self::assertTrue(RiskAppetiteRules::needsDecision(RiskAppetiteRules::ABOVE_TOLERANCE, '', 'in_treatment'));
        self::assertFalse(
            RiskAppetiteRules::needsDecision(RiskAppetiteRules::ABOVE_APPETITE, 'mitigate', 'identified')
        );
        self::assertFalse(RiskAppetiteRules::needsDecision(RiskAppetiteRules::ABOVE_APPETITE, '', 'closed'));
        self::assertFalse(RiskAppetiteRules::needsDecision(RiskAppetiteRules::WITHIN, '', 'identified'));
        self::assertFalse(RiskAppetiteRules::needsDecision(RiskAppetiteRules::UNDEFINED, '', 'identified'));
    }

    public function testOnlyManagementAcceptsBeyondTheToleranceOnceItIsSet(): void
    {
        $tolerance = RiskAppetiteRules::ABOVE_TOLERANCE;
        $appetite = RiskAppetiteRules::ABOVE_APPETITE;
        $accept = RiskAppetiteRules::acceptance(...);

        // Pas d'acceptation formelle : rien à signer.
        self::assertSame(RiskAppetiteRules::ACCEPT_NONE, $accept($tolerance, 'mitigate', true, false));
        self::assertSame(RiskAppetiteRules::ACCEPT_NONE, $accept('within', 'accept', true, false));
        // Au-dessus de l'appétence : la personne signe ; un membre de la direction signe pour elle.
        self::assertSame(RiskAppetiteRules::ACCEPT_SIGN, $accept($appetite, 'accept', true, false));
        self::assertSame(RiskAppetiteRules::ACCEPT_DIRECTION, $accept($appetite, 'accept', true, true));
        // Au-delà de la tolérance : refus hors direction, sauf sans groupe Direction.
        self::assertSame(RiskAppetiteRules::ACCEPT_REFUSED, $accept($tolerance, 'accept', true, false));
        self::assertSame(RiskAppetiteRules::ACCEPT_DIRECTION, $accept($tolerance, 'accept', true, true));
        self::assertSame(RiskAppetiteRules::ACCEPT_SIGN, $accept($tolerance, 'accept', false, false));
    }

    public function testAcceptanceSignedOutsideManagementIsFlaggedBeyondTheTolerance(): void
    {
        $tolerance = RiskAppetiteRules::ABOVE_TOLERANCE;
        $needs = RiskAppetiteRules::needsDirectionSignature(...);
        self::assertTrue($needs($tolerance, 'accept', true, false));
        self::assertFalse($needs($tolerance, 'accept', true, true));
        self::assertFalse($needs($tolerance, 'accept', false, false));
        self::assertFalse($needs($tolerance, 'mitigate', true, false));
        self::assertFalse($needs(RiskAppetiteRules::ABOVE_APPETITE, 'accept', true, false));
    }

    public function testResidualLevelIsComparedOnceItIsAssessed(): void
    {
        self::assertSame('low', RiskAppetiteRules::retainedLevel('low', 'critical'));
        self::assertSame('critical', RiskAppetiteRules::retainedLevel('', 'critical'), 'Résiduel non évalué.');
        self::assertSame('critical', RiskAppetiteRules::retainedLevel('inconnu', 'critical'));
        self::assertSame('', RiskAppetiteRules::retainedLevel(null, null));
    }

    public function testFormalAcceptance(): void
    {
        self::assertTrue(RiskAppetiteRules::isFormalAcceptance(RiskAppetiteRules::ABOVE_APPETITE, 'accept'));
        self::assertTrue(RiskAppetiteRules::isFormalAcceptance(RiskAppetiteRules::ABOVE_TOLERANCE, 'accept'));
        self::assertFalse(RiskAppetiteRules::isFormalAcceptance(RiskAppetiteRules::WITHIN, 'accept'));
        self::assertFalse(RiskAppetiteRules::isFormalAcceptance(RiskAppetiteRules::ABOVE_APPETITE, 'mitigate'));
    }
}
