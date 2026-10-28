<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27090CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;
use PHPUnit\Framework\TestCase;

final class Iso27090CatalogDefaultsTest extends TestCase
{
    public function testAllCompromiseTypesOfClause6AreListed(): void
    {
        foreach (['6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8', '6.9', '6.10'] as $code) {
            self::assertArrayHasKey($code, Iso27090CatalogDefaults::TOPICS, $code);
        }
    }

    public function testEveryTopicIsBilingualAndBelongsToAKnownChapter(): void
    {
        foreach (Iso27090CatalogDefaults::TOPICS as $code => $topic) {
            self::assertArrayHasKey($topic['chapter'], Iso27090CatalogDefaults::CHAPTERS, (string) $code);
            self::assertStringStartsWith($topic['chapter'] . '.', (string) $code);
            self::assertNotSame('', trim($topic['fr']), "$code fr");
            self::assertNotSame('', trim($topic['en']), "$code en");
        }
    }

    public function testEveryAnnexAReferenceExistsInTheAnnexACatalog(): void
    {
        foreach (Iso27090CatalogDefaults::TOPICS as $code => $topic) {
            self::assertNotEmpty($topic['annex_a'], (string) $code);
            foreach ($topic['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
        }
    }

    public function testEveryAiRiskOfTheCatalogPointsToKnownSections(): void
    {
        $aiRisks = array_filter(
            InfrastructureRiskCatalog::keys(),
            static fn (string $key): bool
                => InfrastructureRiskCatalog::group($key) === InfrastructureRiskCatalog::GROUP_AI
        );
        self::assertGreaterThanOrEqual(5, count($aiRisks));
        foreach ($aiRisks as $key) {
            self::assertNotEmpty(InfrastructureRiskCatalog::iso27090($key), $key);
            foreach (InfrastructureRiskCatalog::iso27090($key) as $section) {
                self::assertArrayHasKey($section, Iso27090CatalogDefaults::TOPICS, "$key -> $section");
            }
        }
    }

    public function testInfrastructureRisksHaveNoIso27090Section(): void
    {
        self::assertSame([], InfrastructureRiskCatalog::iso27090('flat_network'));
        self::assertSame(
            InfrastructureRiskCatalog::GROUP_INFRASTRUCTURE,
            InfrastructureRiskCatalog::group('flat_network')
        );
    }

    public function testReverseMappingFindsPromptInjectionFromItsControl(): void
    {
        self::assertContains('6.9', Iso27090CatalogDefaults::topicsForControl('A.8.26'));
        self::assertSame([], Iso27090CatalogDefaults::topicsForControl('A.7.1'));
    }
}
