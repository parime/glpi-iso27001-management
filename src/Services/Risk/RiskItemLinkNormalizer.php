<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Pure validation/lookup logic behind the risk <-> CMDB item polymorphic link (issue #25,
 * glpi_plugin_grcmanager_risks_items), see \GlpiPlugin\Grcmanager\Risk::syncLinkedAssets()/
 * getRisksLinkedToItem(). Kept GLPI-independent (no $DB, no CommonDBTM) so the zero/one/multiple
 * link and reverse-lookup scenarios can be unit tested directly (see
 * tests/Unit/Services/Risk/RiskItemLinkNormalizerTest.php), the same split already used for the
 * risk-scoring logic (RiskAssessmentTrait) and the risk matrix defaults (RiskMatrixDefaults vs
 * RiskMatrixConfig): pure decision logic here, thin $DB wrapper on the CommonDBTM class itself.
 *
 * The actual insert/delete/join methods on \GlpiPlugin\Grcmanager\Risk are NOT unit tested beyond what
 * this class covers: every other simple link table in this plugin (controls_risks,
 * audits_controls, trainings_users, managementreviews_users) has the same gap, validated instead
 * against a real GLPI instance (see docs/design/DEVELOPMENT_PLAN.md, "Environnement de
 * développement et tests"), not by PHPUnit — this issue does not change that established
 * convention, see TECH_DEBT.md.
 */
final class RiskItemLinkNormalizer
{
    /**
     * A link is only ever created for a strictly positive items_id, on an itemtype explicitly
     * allowed for this plugin (see \GlpiPlugin\Grcmanager\Risk::getLinkableItemtypes()). Silent rejection
     * (no exception): a risk ending up with zero valid links is the expected, non-error baseline
     * this issue asks for (e.g. a purely organizational risk such as "processus de recrutement"),
     * not a failure case.
     *
     * @param array<int, string> $allowedItemtypes
     */
    public static function isLinkable(string $itemtype, int $itemsId, array $allowedItemtypes): bool
    {
        return $itemsId > 0 && $itemtype !== '' && in_array($itemtype, $allowedItemtypes, true);
    }

    /**
     * Reverse lookup: given every link-table row for ONE itemtype (a real caller narrows the SQL
     * `WHERE` to `itemtype` first, cheap thanks to the `item` index on
     * glpi_plugin_grcmanager_risks_items, then hands the resulting rows here) and one target asset
     * id, returns the ids of every risk linked to it. De-duplicated (a risk cannot legitimately be
     * linked twice to the same asset, `unicity_link` in the schema enforces it, but this stays
     * correct even fed inconsistent input) and order-preserving.
     *
     * @param array<int, array{plugin_grcmanager_risks_id:int|string, itemtype:string, items_id:int|string}> $links
     * @return array<int, int> risk ids
     */
    public static function findRiskIdsForItem(array $links, string $itemtype, int $itemsId): array
    {
        $ids = [];
        foreach ($links as $link) {
            if ($link['itemtype'] === $itemtype && (int) $link['items_id'] === $itemsId) {
                $ids[(int) $link['plugin_grcmanager_risks_id']] = true;
            }
        }

        return array_keys($ids);
    }
}
