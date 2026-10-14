<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use PHPUnit\Framework\TestCase;

final class Nis2CatalogDefaultsTest extends TestCase
{
    public function testArticle21Paragraph2HasExactlyTheTenPointsAToJ(): void
    {
        $points = array_values(array_filter(
            array_keys(Nis2CatalogDefaults::REQUIREMENTS),
            static fn (string $code): bool => str_starts_with($code, '21.2.')
        ));

        self::assertSame(
            ['21.2.a', '21.2.b', '21.2.c', '21.2.d', '21.2.e', '21.2.f', '21.2.g', '21.2.h', '21.2.i', '21.2.j'],
            $points
        );
    }

    public function testArticle21PointsAreVerbatimNotSummaries(): void
    {
        foreach (Nis2CatalogDefaults::REQUIREMENTS as $code => $requirement) {
            if ($requirement['article'] === '21') {
                self::assertFalse($requirement['summary'], "$code doit être le texte officiel");
            }
        }
    }

    public function testEveryRequirementIsBilingualAndBelongsToAKnownArticle(): void
    {
        foreach (Nis2CatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertArrayHasKey((int) $requirement['article'], Nis2CatalogDefaults::ARTICLES, $code);
            self::assertStringStartsWith($requirement['article'] . '.', $code);
            self::assertNotSame('', trim($requirement['fr']), "$code fr");
            self::assertNotSame('', trim($requirement['en']), "$code en");
        }
    }

    public function testEveryAnnexAReferenceExistsInTheAnnexACatalog(): void
    {
        foreach (Nis2CatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertNotEmpty($requirement['annex_a'], $code);
            foreach ($requirement['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
        }
    }

    public function testNotificationDeadlinesAreStated(): void
    {
        self::assertStringContainsString('24 heures', Nis2CatalogDefaults::REQUIREMENTS['23.4.a']['fr']);
        self::assertStringContainsString('72 heures', Nis2CatalogDefaults::REQUIREMENTS['23.4.b']['fr']);
        self::assertStringContainsString('un mois', Nis2CatalogDefaults::REQUIREMENTS['23.4.d']['fr']);
    }

    public function testReverseLookupAndReferenceLabel(): void
    {
        self::assertSame(['21.2.h'], Nis2CatalogDefaults::requirementsForControl('A.8.24'));
        self::assertContains('21.2.i', Nis2CatalogDefaults::requirementsForControl('A.5.11'));
        self::assertSame([], Nis2CatalogDefaults::requirementsForControl('A.7.12'));

        self::assertSame('Art. 21 §2 j)', Nis2CatalogDefaults::reference('21.2.j'));
        self::assertSame('Art. 20 §1', Nis2CatalogDefaults::reference('20.1'));
    }
}
