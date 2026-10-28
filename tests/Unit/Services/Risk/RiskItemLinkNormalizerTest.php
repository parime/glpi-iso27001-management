<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskItemLinkNormalizer;
use PHPUnit\Framework\TestCase;

final class RiskItemLinkNormalizerTest extends TestCase
{
    private const ALLOWED = ['Computer', 'Software'];

    /**
     * A risk with zero linked assets is a valid, expected state (issue #25: purely organizational
     * risks such as "processus de recrutement" have no CMDB counterpart), never an error.
     */
    public function testLinkingZeroAssetsIsNotAnError(): void
    {
        self::assertFalse(RiskItemLinkNormalizer::isLinkable('Computer', 0, self::ALLOWED));
        self::assertSame([], RiskItemLinkNormalizer::findRiskIdsForItem([], 'Computer', 1));
    }

    public function testLinkingOneAsset(): void
    {
        self::assertTrue(RiskItemLinkNormalizer::isLinkable('Computer', 42, self::ALLOWED));
    }

    /**
     * "panne électrique du site" (issue #25) can affect many servers at once: several distinct
     * itemtype/items_id pairs must each be individually linkable.
     */
    public function testLinkingMultipleAssetsAcrossDifferentItemtypes(): void
    {
        self::assertTrue(RiskItemLinkNormalizer::isLinkable('Computer', 1, self::ALLOWED));
        self::assertTrue(RiskItemLinkNormalizer::isLinkable('Computer', 2, self::ALLOWED));
        self::assertTrue(RiskItemLinkNormalizer::isLinkable('Software', 5, self::ALLOWED));
    }

    public function testRejectsAnItemtypeThatIsNotInTheAllowedList(): void
    {
        self::assertFalse(RiskItemLinkNormalizer::isLinkable('User', 1, self::ALLOWED));
    }

    public function testRejectsANegativeOrZeroItemsId(): void
    {
        self::assertFalse(RiskItemLinkNormalizer::isLinkable('Computer', 0, self::ALLOWED));
        self::assertFalse(RiskItemLinkNormalizer::isLinkable('Computer', -1, self::ALLOWED));
    }

    /**
     * "Unlinking" is a delete-then-reinsert of the desired end state on the real $DB-backed
     * method (\GlpiPlugin\Grcmanager\Risk::syncLinkedAssets(), same convention as every other simple link
     * table in this plugin, see its own docblock): simulated here as "the item is simply absent
     * from the next call's candidate pairs", which must never raise, on an already-empty set or
     * one that previously held the link.
     */
    public function testUnlinkingLeavesAnEmptySetWithoutError(): void
    {
        $afterUnlink = [];

        self::assertSame([], RiskItemLinkNormalizer::findRiskIdsForItem($afterUnlink, 'Computer', 42));
    }

    /**
     * Reverse lookup (issue #25: "inversement partir d'un risque pour voir les actifs concernés" —
     * and its mirror, from an asset back to the risks affecting it): given the rows for one
     * itemtype, finds every risk linked to a specific asset, de-duplicated and ignoring rows for a
     * different asset id.
     */
    public function testReverseLookupFindsEveryRiskLinkedToAGivenAsset(): void
    {
        $links = [
            ['plugin_grcmanager_risks_id' => 1, 'itemtype' => 'Computer', 'items_id' => 42],
            ['plugin_grcmanager_risks_id' => 2, 'itemtype' => 'Computer', 'items_id' => 42],
            ['plugin_grcmanager_risks_id' => 3, 'itemtype' => 'Computer', 'items_id' => 99],
            // Duplicate row (should never happen given `unicity_link`, but must not double-count).
            ['plugin_grcmanager_risks_id' => 1, 'itemtype' => 'Computer', 'items_id' => 42],
        ];

        self::assertSame([1, 2], RiskItemLinkNormalizer::findRiskIdsForItem($links, 'Computer', 42));
    }

    public function testReverseLookupReturnsEmptyArrayWhenNothingIsLinked(): void
    {
        $links = [
            ['plugin_grcmanager_risks_id' => 1, 'itemtype' => 'Computer', 'items_id' => 42],
        ];

        self::assertSame([], RiskItemLinkNormalizer::findRiskIdsForItem($links, 'Computer', 7));
        self::assertSame([], RiskItemLinkNormalizer::findRiskIdsForItem($links, 'Software', 42));
    }
}
