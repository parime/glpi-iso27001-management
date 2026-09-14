<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\CpeVersionMatch;
use PHPUnit\Framework\TestCase;

final class CpeVersionMatchTest extends TestCase
{
    /**
     * @param array<string, ?string> $overrides
     * @return array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * }
     */
    private function affectedCpe(array $overrides = []): array
    {
        return array_merge([
            'cpe23_uri'                => 'cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*',
            'version_start_including'  => null,
            'version_start_excluding'  => null,
            'version_end_including'    => null,
            'version_end_excluding'    => null,
        ], $overrides);
    }

    public function testInstalledVersionInsideAnIncludingExcludingRangeIsMatched(): void
    {
        $affectedCpe = $this->affectedCpe([
            'version_start_including' => '2.0.1',
            'version_end_excluding'   => '2.3.1',
        ]);

        self::assertSame(CpeVersionMatch::MATCHED, CpeVersionMatch::evaluate($affectedCpe, '2.1.0'));
    }

    public function testInstalledVersionExactlyAtTheExcludedUpperBoundIsExcluded(): void
    {
        $affectedCpe = $this->affectedCpe([
            'version_start_including' => '2.0.1',
            'version_end_excluding'   => '2.3.1',
        ]);

        self::assertSame(CpeVersionMatch::EXCLUDED, CpeVersionMatch::evaluate($affectedCpe, '2.3.1'));
    }

    public function testInstalledVersionExactlyAtTheIncludedLowerBoundIsMatched(): void
    {
        $affectedCpe = $this->affectedCpe([
            'version_start_including' => '2.0.1',
            'version_end_excluding'   => '2.3.1',
        ]);

        self::assertSame(CpeVersionMatch::MATCHED, CpeVersionMatch::evaluate($affectedCpe, '2.0.1'));
    }

    public function testInstalledVersionBelowTheLowerBoundIsExcluded(): void
    {
        $affectedCpe = $this->affectedCpe(['version_start_including' => '2.0.1']);

        self::assertSame(CpeVersionMatch::EXCLUDED, CpeVersionMatch::evaluate($affectedCpe, '1.9.0'));
    }

    /**
     * The classic string-ordering trap ("9.10" < "9.9" lexically) — version_compare() must be used
     * under the hood, not a plain string comparison, or this would wrongly exclude a genuinely
     * vulnerable later version.
     */
    public function testVersionComparisonIsNumericNotLexical(): void
    {
        $affectedCpe = $this->affectedCpe(['version_start_including' => '9.9']);

        self::assertSame(CpeVersionMatch::MATCHED, CpeVersionMatch::evaluate($affectedCpe, '9.10'));
    }

    public function testFallsBackToTheConcreteCpeVersionFieldWhenNoBoundsArePresent(): void
    {
        $affectedCpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:a:apache:log4j:2.0:beta9:*:*:*:*:*:*']);

        self::assertSame(CpeVersionMatch::MATCHED, CpeVersionMatch::evaluate($affectedCpe, '2.0'));
        self::assertSame(CpeVersionMatch::EXCLUDED, CpeVersionMatch::evaluate($affectedCpe, '2.17.0'));
    }

    public function testIsUnknownRatherThanGuessedWhenNoBoundsAndNoConcreteCpeVersionExist(): void
    {
        // A bare wildcard CPE ("*" as the version field) with no start/end bounds either — real
        // NVD data does have this shape for some entries, and there is genuinely nothing to
        // compare against, so this must be UNKNOWN, never MATCHED nor EXCLUDED by default.
        $affectedCpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*']);

        self::assertSame(CpeVersionMatch::UNKNOWN, CpeVersionMatch::evaluate($affectedCpe, '2.1.0'));
    }

    public function testIsUnknownWhenTheCpeVersionFieldIsTheNotApplicableMarker(): void
    {
        $affectedCpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:a:apache:log4j:-:*:*:*:*:*:*:*']);

        self::assertSame(CpeVersionMatch::UNKNOWN, CpeVersionMatch::evaluate($affectedCpe, '2.1.0'));
    }

    /**
     * Real bug found by testing a real installed-version string against a real CVE with explicit
     * bounds on the shared Docker instance: version_compare("unknown", "2.13.0", ">=") returns
     * false (non-numeric input degrades to very low precedence), which the bounds check used to
     * read as a confirmed EXCLUDED — silently claiming "not vulnerable" for a value the plugin
     * actually has no basis to judge. Must be UNKNOWN instead, same as the no-bounds/no-concrete-
     * version case above.
     */
    public function testIsUnknownWhenTheInstalledVersionIsNotAVersionAtAllAgainstExplicitBounds(): void
    {
        $affectedCpe = $this->affectedCpe([
            'version_start_including' => '2.13.0',
            'version_end_excluding'   => '2.15.0',
        ]);

        self::assertSame(CpeVersionMatch::UNKNOWN, CpeVersionMatch::evaluate($affectedCpe, 'unknown'));
    }

    public function testIsUnknownWhenTheInstalledVersionIsNotAVersionAtAllAgainstAConcreteCpeVersion(): void
    {
        $affectedCpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:a:apache:log4j:2.0:beta9:*:*:*:*:*:*']);

        self::assertSame(CpeVersionMatch::UNKNOWN, CpeVersionMatch::evaluate($affectedCpe, 'N/A'));
    }

    public function testIsUnknownWhenTheInstalledVersionIsAnEmptyString(): void
    {
        $affectedCpe = $this->affectedCpe(['version_start_including' => '2.0.1']);

        self::assertSame(CpeVersionMatch::UNKNOWN, CpeVersionMatch::evaluate($affectedCpe, ''));
    }

    /**
     * looksLikeAVersion() is deliberately permissive (leading digit only) so real messy-but-real
     * version strings are never wrongly turned away as UNKNOWN.
     */
    public function testAVersionWithATrailingQualifierIsStillComparedNormally(): void
    {
        $affectedCpe = $this->affectedCpe([
            'version_start_including' => '2.0.1',
            'version_end_excluding'   => '2.3.1',
        ]);

        self::assertSame(CpeVersionMatch::MATCHED, CpeVersionMatch::evaluate($affectedCpe, '2.1.0-patched'));
    }
}
