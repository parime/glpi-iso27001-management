<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\DoraCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class DoraCatalogDefaultsTest extends TestCase
{
    public function testEveryRequirementIsBilingualAndBelongsToAKnownArticle(): void
    {
        foreach (DoraCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            $code = (string) $code;
            self::assertArrayHasKey((int) $requirement['article'], DoraCatalogDefaults::ARTICLES, $code);
            self::assertTrue(
                $code === $requirement['article'] || str_starts_with($code, $requirement['article'] . '.'),
                $code
            );
            self::assertNotSame('', trim($requirement['fr']), "$code fr");
            self::assertNotSame('', trim($requirement['en']), "$code en");
            self::assertTrue($requirement['summary'], "$code : texte résumé par le projet");
        }
    }

    public function testEveryArticleHasAtLeastOneRequirement(): void
    {
        $articles = array_unique(array_map(
            static fn (array $requirement): string => $requirement['article'],
            DoraCatalogDefaults::REQUIREMENTS
        ));
        foreach (array_keys(DoraCatalogDefaults::ARTICLES) as $article) {
            self::assertContains((string) $article, $articles, "Article $article sans exigence");
        }
    }

    public function testEveryAnnexAReferenceExistsInTheAnnexACatalog(): void
    {
        foreach (DoraCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertNotEmpty($requirement['annex_a'], (string) $code);
            foreach ($requirement['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
        }
    }

    public function testMajorIncidentReportingDeadlinesAreStated(): void
    {
        $text = DoraCatalogDefaults::REQUIREMENTS['19']['fr'];
        self::assertStringContainsString('4 heures', $text);
        self::assertStringContainsString('24 heures', $text);
        self::assertStringContainsString('72 heures', $text);
        self::assertStringContainsString('un mois', $text);
    }

    public function testReverseMappingAndReference(): void
    {
        self::assertContains('28.3', DoraCatalogDefaults::requirementsForControl('A.5.19'));
        self::assertContains('19', DoraCatalogDefaults::requirementsForControl('A.5.5'));
        self::assertSame([], DoraCatalogDefaults::requirementsForControl('A.7.12'));
        self::assertSame('Art. 28 §3', DoraCatalogDefaults::reference('28.3'));
        self::assertSame('Art. 19', DoraCatalogDefaults::reference('19'));
    }
}
