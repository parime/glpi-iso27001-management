<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Compliance;

use DateTimeImmutable;

/**
 * Pure decision logic behind \GlpiPlugin\Grcmanager\ComplianceObligation (issue #30, ISO 27001 clause 4.2
 * "parties intéressées et leurs exigences" / Annexe A A.5.31-36) : type/compliance_status
 * normalization, the optional zero-or-one link to a \GlpiPlugin\Grcmanager\Risk, and the review-date
 * reminder window. Kept GLPI-independent (no $DB, no CommonDBTM, no __()) so all three are unit
 * tested directly, the same split already used throughout this plugin between pure decision logic
 * and the thin CommonDBTM/$DB wrapper that calls it (see RiskItemLinkNormalizer,
 * ClassificationLevels, CapaRequirementService).
 */
final class ComplianceObligationRules
{
    /**
     * @var array<int, string>
     */
    public const ALLOWED_TYPES = ['legal', 'regulatory', 'contractual'];

    public const DEFAULT_TYPE = 'legal';

    /**
     * @var array<int, string>
     */
    public const ALLOWED_STATUSES = ['compliant', 'partially_compliant', 'non_compliant', 'not_assessed'];

    public const DEFAULT_STATUS = 'not_assessed';

    /**
     * Same reminder window as GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService
     * (REMINDER_WINDOW_DAYS): an obligation is flagged this many days before its review_date, and
     * stays flagged for as long as it remains overdue. Kept as an independent constant rather than
     * a cross-namespace reference (Risk -> Compliance would be a layering inversion, this plugin's
     * `Services\Risk` namespace predates and is unrelated to obligations); both values must be
     * changed together if this window is ever revisited, cheap to keep in sync given how rarely a
     * fixed business constant like this changes.
     */
    public const REMINDER_WINDOW_DAYS = 30;

    /**
     * @return string One of self::ALLOWED_TYPES, self::DEFAULT_TYPE for anything else (unset,
     *         empty, or a value no longer/not yet valid, e.g. stale form input).
     */
    public static function normalizeType(?string $value): string
    {
        return in_array($value, self::ALLOWED_TYPES, true) ? $value : self::DEFAULT_TYPE;
    }

    /**
     * @return string One of self::ALLOWED_STATUSES, self::DEFAULT_STATUS for anything else.
     */
    public static function normalizeComplianceStatus(?string $value): string
    {
        return in_array($value, self::ALLOWED_STATUSES, true) ? $value : self::DEFAULT_STATUS;
    }

    /**
     * The optional link to a risk (issue #30 : "lien optionnel vers un risque si le non-respect
     * constitue un risque identifié") is a direct nullable-style column
     * (`plugin_grcmanager_risks_id`), not a link table like the many-to-many relations elsewhere in
     * this plugin (control<->risk, audit<->control) : a compliance obligation maps to AT MOST ONE
     * risk, the same 1-vers-1-optionnelle cardinality already used for `users_id` (owner) on every
     * other registry of this plugin, simpler than issue #25's polymorphic risk<->asset link (which
     * genuinely needed many-to-many). 0 is the canonical "no risk linked" value throughout this
     * plugin's schema (`users_id` defaults to 0 with the same meaning), never NULL: any strictly
     * positive id is normalized as-is (existence against a real `\GlpiPlugin\Grcmanager\Risk` row is
     * checked by the caller against $DB, out of scope for this pure class), anything else
     * (0, negative, non-numeric) collapses to "unlinked".
     */
    public static function normalizeLinkedRiskId(int|string|null $value): int
    {
        $id = (int) $value;

        return $id > 0 ? $id : 0;
    }

    public static function isLinkedToRisk(int $riskId): bool
    {
        return $riskId > 0;
    }

    /**
     * True as soon as $reviewDate is set and falls on or before $now + self::REMINDER_WINDOW_DAYS
     * days, matching exactly the predicate
     * GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService::notify() applies via
     * CommonDBTM::find() (`review_date <= today+30` AND `review_date IS NOT NULL`) — see
     * \GlpiPlugin\Grcmanager\ComplianceObligation::cronReviewreminder(), which reuses that same service
     * (generalized in this issue to accept obligations, whose `compliance_status` is not a
     * terminal "closed" state the way \GlpiPlugin\Grcmanager\Risk's own `status` is, so no exclusion
     * criteria is passed for this itemtype: even a `compliant` obligation still needs its periodic
     * review). A missing/blank/unparsable review date is never due (an obligation with no review
     * date has no periodic-review commitment yet, nothing to remind about).
     */
    public static function isReviewDue(?string $reviewDate, ?DateTimeImmutable $now = null): bool
    {
        if ($reviewDate === null || trim($reviewDate) === '') {
            return false;
        }

        try {
            $due = new DateTimeImmutable($reviewDate);
        } catch (\Exception) {
            return false;
        }

        $now ??= new DateTimeImmutable('today');
        $threshold = $now->modify(sprintf('+%d days', self::REMINDER_WINDOW_DAYS));

        return $due <= $threshold;
    }
}
