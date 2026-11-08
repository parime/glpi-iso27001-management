<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\AiActCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class AiActCatalogDefaultsTest extends TestCase
{
    /** Dates d'application de l'article 113 consolidé (règlement 2026/1744 compris). */
    private const APPLICATION_DATES = [
        '2025-02-02', '2025-08-02', '2026-08-02', '2026-12-02', '2027-12-02', '2028-08-02',
    ];

    public function testEveryArticleIsBilingualWithAKnownRoleAndAnArticle113Date(): void
    {
        foreach (AiActCatalogDefaults::ARTICLES as $number => $article) {
            $label = 'Art. ' . $number;
            self::assertArrayHasKey($article['role'], AiActCatalogDefaults::ROLES, $label);
            foreach (['title_fr', 'title_en', 'fr', 'en'] as $field) {
                self::assertNotSame('', trim($article[$field]), "$label $field");
            }
            self::assertContains($article['applies'], self::APPLICATION_DATES, $label);
        }
    }

    public function testOmnibusDatesAreTheConsolidatedOnes(): void
    {
        // Article 4 : obligation assouplie mais toujours applicable depuis le 2/2/2025.
        self::assertSame('2025-02-02', AiActCatalogDefaults::ARTICLES[4]['applies']);
        self::assertStringContainsString('aucun niveau imposé', AiActCatalogDefaults::ARTICLES[4]['fr']);
        // Haut risque (annexe III) reporté au 2/12/2027 par le règlement 2026/1744.
        foreach ([6, 9, 26, 27, 73] as $number) {
            self::assertSame('2027-12-02', AiActCatalogDefaults::ARTICLES[$number]['applies'], 'Art. ' . $number);
        }
        self::assertSame('2026-08-02', AiActCatalogDefaults::ARTICLES[50]['applies']);
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (AiActCatalogDefaults::ARTICLES as $number => $article) {
            self::assertNotEmpty($article['annex_a'], 'Art. ' . $number);
            foreach ($article['annex_a'] as $code) {
                self::assertArrayHasKey($code, ControlCatalogDefaults::CONTROLS, "Art. $number -> $code");
            }
            foreach ($article['modules'] as $module) {
                self::assertContains($module, AiActCatalogDefaults::MODULES, "Art. $number -> $module");
            }
        }
        self::assertSame(['4', '26'], AiActCatalogDefaults::articlesForControl('A.6.3'));
        self::assertSame([], AiActCatalogDefaults::articlesForControl('A.7.12'));
    }
}
