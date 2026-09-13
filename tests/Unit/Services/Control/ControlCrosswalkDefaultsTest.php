<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class ControlCrosswalkDefaultsTest extends TestCase
{
    public function testIsNotEmpty(): void
    {
        self::assertNotEmpty(ControlCrosswalkDefaults::CROSSWALK);
    }

    public function testEveryAnnexACodeExistsInTheAnnexACatalog(): void
    {
        $annexACodes = array_keys(ControlCatalogDefaults::CONTROLS);

        foreach (array_keys(ControlCrosswalkDefaults::CROSSWALK) as $annexACode) {
            self::assertContains(
                'A.' . $annexACode,
                $annexACodes,
                "Crosswalk references unknown Annex A code A.$annexACode"
            );
        }
    }

    public function testEveryNistCsfReferenceExistsInTheNistCatalog(): void
    {
        foreach (ControlCrosswalkDefaults::CROSSWALK as $annexACode => $frameworks) {
            foreach ($frameworks['nist_csf'] ?? [] as $nistCode) {
                self::assertArrayHasKey(
                    $nistCode,
                    NistCsfCatalogDefaults::SUBCATEGORIES,
                    "Crosswalk entry for A.$annexACode references unknown NIST CSF subcategory $nistCode"
                );
            }
        }
    }

    public function testNoEntryIsEmpty(): void
    {
        foreach (ControlCrosswalkDefaults::CROSSWALK as $annexACode => $frameworks) {
            $total = array_sum(array_map('count', $frameworks));

            self::assertGreaterThan(0, $total, "Crosswalk entry for A.$annexACode has no references at all");
        }
    }
}
