<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class NistCsfCatalogDefaultsTest extends TestCase
{
    public function testExactlySixFunctions(): void
    {
        self::assertCount(6, NistCsfCatalogDefaults::FUNCTIONS);
    }

    public function testExactlyTwentyTwoCategories(): void
    {
        self::assertCount(22, NistCsfCatalogDefaults::CATEGORIES);
    }

    public function testExactlyOneHundredAndSixSubcategories(): void
    {
        self::assertCount(106, NistCsfCatalogDefaults::SUBCATEGORIES);
    }

    public function testEveryCategoryReferencesAnExistingFunction(): void
    {
        foreach (NistCsfCatalogDefaults::CATEGORIES as $code => $category) {
            self::assertArrayHasKey(
                $category['function'],
                NistCsfCatalogDefaults::FUNCTIONS,
                "Category $code references an unknown function {$category['function']}"
            );
        }
    }

    public function testEverySubcategoryReferencesAnExistingCategory(): void
    {
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $code => $subcategory) {
            self::assertArrayHasKey(
                $subcategory['category'],
                NistCsfCatalogDefaults::CATEGORIES,
                "Subcategory $code references an unknown category {$subcategory['category']}"
            );
        }
    }

    public function testEverySubcategoryCodeIsPrefixedByItsCategoryCode(): void
    {
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $code => $subcategory) {
            self::assertStringStartsWith($subcategory['category'] . '-', $code);
        }
    }

    public function testNoSubcategoryTextIsEmpty(): void
    {
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $code => $subcategory) {
            self::assertNotSame('', trim($subcategory['text']), "Subcategory $code has no text");
        }
    }
}
