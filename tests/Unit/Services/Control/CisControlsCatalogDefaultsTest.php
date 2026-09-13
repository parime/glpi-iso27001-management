<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class CisControlsCatalogDefaultsTest extends TestCase
{
    public function testExactlyEighteenControls(): void
    {
        self::assertCount(18, CisControlsCatalogDefaults::CONTROLS);
        self::assertSame(range(1, 18), array_keys(CisControlsCatalogDefaults::CONTROLS));
    }

    public function testExactlyOneHundredAndFiftyThreeSafeguards(): void
    {
        self::assertCount(153, CisControlsCatalogDefaults::SAFEGUARDS);
    }

    /**
     * The widely-cited "56/74/23" breakdown dates from v8's 2021 release; CIS's own
     * assessment-specification repo (the source used here, see CisControlsCatalogDefaults'
     * docblock) reflects a handful of safeguards rebalanced since then. Only the total (153) is
     * a stable, independently-confirmed invariant — the per-IG split is asserted as a regression
     * guard on the data actually shipped, not as an external claim.
     */
    public function testImplementationGroupCountsSumToTheTotal(): void
    {
        $countsByIg = array_count_values(array_column(CisControlsCatalogDefaults::SAFEGUARDS, 'ig'));

        self::assertSame(58, $countsByIg[1]);
        self::assertSame(73, $countsByIg[2]);
        self::assertSame(22, $countsByIg[3]);
        self::assertSame(153, array_sum($countsByIg));
    }

    public function testEverySafeguardReferencesAnExistingControl(): void
    {
        foreach (CisControlsCatalogDefaults::SAFEGUARDS as $code => $safeguard) {
            self::assertArrayHasKey(
                $safeguard['control'],
                CisControlsCatalogDefaults::CONTROLS,
                "Safeguard $code references an unknown control {$safeguard['control']}"
            );
        }
    }

    public function testEverySafeguardCodeIsPrefixedByItsControlNumber(): void
    {
        foreach (CisControlsCatalogDefaults::SAFEGUARDS as $code => $safeguard) {
            self::assertStringStartsWith($safeguard['control'] . '.', $code);
        }
    }
}
