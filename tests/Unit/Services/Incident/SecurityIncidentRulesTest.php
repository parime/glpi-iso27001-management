<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentRules;
use PHPUnit\Framework\TestCase;

final class SecurityIncidentRulesTest extends TestCase
{
    // --- category ------------------------------------------------------------------------------

    /**
     * @dataProvider allowedCategoryProvider
     */
    public function testNormalizeCategoryKeepsAnAllowedValue(string $category): void
    {
        self::assertSame($category, SecurityIncidentRules::normalizeCategory($category));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedCategoryProvider(): array
    {
        return [
            'data_breach'         => ['data_breach'],
            'malware'             => ['malware'],
            'unauthorized_access' => ['unauthorized_access'],
            'availability'        => ['availability'],
            'other'               => ['other'],
        ];
    }

    public function testNormalizeCategoryFallsBackToOtherForAnUnknownValue(): void
    {
        self::assertSame('other', SecurityIncidentRules::normalizeCategory('not_a_real_category'));
    }

    public function testNormalizeCategoryFallsBackToOtherForNull(): void
    {
        self::assertSame('other', SecurityIncidentRules::normalizeCategory(null));
    }

    // --- severity (issue #29: reuses PluginGrcmanagerNonconformity's own scale) ----------------

    /**
     * @dataProvider allowedSeverityProvider
     */
    public function testNormalizeSeverityKeepsAnAllowedValue(string $severity): void
    {
        self::assertSame($severity, SecurityIncidentRules::normalizeSeverity($severity));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedSeverityProvider(): array
    {
        return [
            'minor'    => ['minor'],
            'major'    => ['major'],
            'critical' => ['critical'],
        ];
    }

    public function testNormalizeSeverityFallsBackToMinorForAnUnknownValue(): void
    {
        self::assertSame('minor', SecurityIncidentRules::normalizeSeverity('bogus'));
    }

    public function testNormalizeSeverityFallsBackToMinorForNull(): void
    {
        self::assertSame('minor', SecurityIncidentRules::normalizeSeverity(null));
    }

    // --- cia_impact --------------------------------------------------------------------------

    public function testNormalizeCiaImpactFromArrayKeepsValidAxesInCanonicalOrder(): void
    {
        self::assertSame(
            'confidentiality,availability',
            SecurityIncidentRules::normalizeCiaImpact(['availability', 'confidentiality'])
        );
    }

    public function testNormalizeCiaImpactFromArrayKeepsAllThreeAxes(): void
    {
        self::assertSame(
            'confidentiality,integrity,availability',
            SecurityIncidentRules::normalizeCiaImpact(['integrity', 'availability', 'confidentiality'])
        );
    }

    public function testNormalizeCiaImpactFromArrayDropsUnknownValues(): void
    {
        self::assertSame(
            'integrity',
            SecurityIncidentRules::normalizeCiaImpact(['integrity', 'bogus'])
        );
    }

    public function testNormalizeCiaImpactFromCommaSeparatedString(): void
    {
        self::assertSame(
            'confidentiality,integrity',
            SecurityIncidentRules::normalizeCiaImpact('integrity,confidentiality')
        );
    }

    public function testNormalizeCiaImpactFromEmptyArrayIsEmptyString(): void
    {
        self::assertSame('', SecurityIncidentRules::normalizeCiaImpact([]));
    }

    public function testNormalizeCiaImpactFromNullIsEmptyString(): void
    {
        self::assertSame('', SecurityIncidentRules::normalizeCiaImpact(null));
    }

    public function testSplitCiaImpactReturnsEachAxis(): void
    {
        self::assertSame(
            ['confidentiality', 'availability'],
            SecurityIncidentRules::splitCiaImpact('confidentiality,availability')
        );
    }

    public function testSplitCiaImpactOfEmptyStringIsEmptyArray(): void
    {
        self::assertSame([], SecurityIncidentRules::splitCiaImpact(''));
    }

    // --- optional zero-or-one link to a risk (issue #29, same pattern as issue #30) -----------

    public function testNormalizeLinkedRiskIdKeepsAPositiveId(): void
    {
        self::assertSame(42, SecurityIncidentRules::normalizeLinkedRiskId(42));
        self::assertSame(42, SecurityIncidentRules::normalizeLinkedRiskId('42'));
    }

    public function testNormalizeLinkedRiskIdCollapsesZeroToZero(): void
    {
        self::assertSame(0, SecurityIncidentRules::normalizeLinkedRiskId(0));
    }

    public function testNormalizeLinkedRiskIdCollapsesNullToZero(): void
    {
        self::assertSame(0, SecurityIncidentRules::normalizeLinkedRiskId(null));
    }

    public function testNormalizeLinkedRiskIdCollapsesNegativeToZero(): void
    {
        self::assertSame(0, SecurityIncidentRules::normalizeLinkedRiskId(-3));
    }

    public function testUnlinkingTheRiskIsSettingBackToZero(): void
    {
        $linked = SecurityIncidentRules::normalizeLinkedRiskId(7);
        self::assertTrue(SecurityIncidentRules::isLinkedToRisk($linked));

        $unlinked = SecurityIncidentRules::normalizeLinkedRiskId(0);
        self::assertFalse(SecurityIncidentRules::isLinkedToRisk($unlinked));
    }

    // --- root_cause/lessons_learned required before closing (issue #29, clause A.5.27) ---------
    //
    // Absorbed onto the full ITIL object (ROADMAP.md "Version 2.0"): the caller
    // (PluginGrcmanagerSecurityIncident::normalizeIsoFields()) now checks `status === self::CLOSED`
    // itself before calling this, so this rule only ever receives the two documentation fields -
    // no `linked_itemtype`/`linked_items_id`/`status` string vocabulary left to test here, both
    // superseded by CommonITILObject's own mechanisms (see the class docblock).

    public function testClosureIsBlockedWithoutRootCause(): void
    {
        self::assertTrue(SecurityIncidentRules::isClosureDocumentationMissing('', 'On a appris X'));
    }

    public function testClosureIsBlockedWithoutLessonsLearned(): void
    {
        self::assertTrue(SecurityIncidentRules::isClosureDocumentationMissing('Cause racine', ''));
    }

    public function testClosureIsBlockedWithOnlyWhitespace(): void
    {
        self::assertTrue(SecurityIncidentRules::isClosureDocumentationMissing('   ', '   '));
    }

    public function testClosureIsBlockedWithBothMissing(): void
    {
        self::assertTrue(SecurityIncidentRules::isClosureDocumentationMissing(null, null));
    }

    public function testClosureIsAllowedWithBothDocumented(): void
    {
        self::assertFalse(
            SecurityIncidentRules::isClosureDocumentationMissing(
                'Cause racine identifiée',
                'Enseignements tirés'
            )
        );
    }
}
