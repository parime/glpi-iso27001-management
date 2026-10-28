<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

use DateTimeImmutable;

/**
 * Business rule behind `\GlpiPlugin\Grcmanager\RiskTreatmentAction` (issue #31, ISO 27001 clause 8.3/6.1.3
 * "mise en œuvre effective du plan de traitement des risques"), extracted as pure PHP so it is
 * unit-testable without a running GLPI instance, same "logique pure-PHP testable sous
 * src/Services/" convention as GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService (the
 * closest existing template for "a decision that's plugin-internal to the entity gating a related
 * field's requirement level") and GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules.
 *
 * A risk's treatment plan (its concrete treatment actions) is only relevant/required-ish for two of
 * the four `treatment` values (see \GlpiPlugin\Grcmanager\Risk::getTreatments() via RiskAssessmentTrait):
 * `accept` has, by definition, no treatment to plan (the organization knowingly keeps the risk as
 * is), and `avoid` means eliminating the risk source entirely, nothing ongoing to track either.
 * Only `mitigate` and `transfer` describe a decision that still needs to be CARRIED OUT through
 * concrete actions over time, which is exactly what this issue adds tracking for.
 *
 * `overdue` is deliberately NOT one of the stored `status` values (self::ALLOWED_STATUSES):
 * every other "in retard"/"overdue" concept already in this plugin (CAPA due dates, review dates,
 * training renewals, policy reviews) is a DERIVED condition (due date passed AND not yet in a
 * terminal state), never a value an admin picks from a dropdown - see
 * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService and TECH_DEBT.md Sprint 4. isOverdue()
 * below follows that exact same established convention rather than the three-status-plus-overdue
 * enum a first reading of the issue might suggest, see TECH_DEBT.md for this plugin's own note on
 * the deviation.
 */
final class TreatmentPlanRules
{
    public const STATUS_PLANNED     = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';

    /**
     * @var array<int, string>
     */
    public const ALLOWED_STATUSES = [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS, self::STATUS_DONE];

    public const DEFAULT_STATUS = self::STATUS_PLANNED;

    /**
     * @var array<int, string> the only two `treatment` values (see RiskAssessmentTrait::
     *      getTreatments()) for which a treatment plan is relevant: a decision that must still be
     *      carried out, not just recorded (see class docblock).
     */
    public const TREATMENTS_REQUIRING_A_PLAN = ['mitigate', 'transfer'];

    /**
     * True only for `mitigate`/`transfer`: the two treatment decisions ISO 27001 clause 8.3/6.1.3
     * requires an organization to actually IMPLEMENT and TRACK, as opposed to `accept` (no
     * treatment by definition) and `avoid` (the risk source itself is eliminated, nothing ongoing
     * to plan).
     */
    public static function isTreatmentPlanRelevant(?string $treatment): bool
    {
        return in_array($treatment, self::TREATMENTS_REQUIRING_A_PLAN, true);
    }

    /**
     * @return string One of self::ALLOWED_STATUSES, self::DEFAULT_STATUS for anything else (unset,
     *         empty, or a value no longer/not yet valid), same defensive posture as
     *         \GlpiPlugin\Grcmanager\Objective::sanitizeStatus()/ObjectiveStatuses::isValid().
     */
    public static function normalizeStatus(?string $value): string
    {
        return in_array($value, self::ALLOWED_STATUSES, true) ? $value : self::DEFAULT_STATUS;
    }

    /**
     * True as soon as $dueDate is set, in the past relative to $now, and the action has not
     * reached self::STATUS_DONE - matches exactly the predicate
     * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService applies via CommonDBTM::find()
     * (`due_date < today` AND status not terminal), so this pure check, the dashboard card and the
     * overdue-reminder Cron task can never drift on what counts as "overdue" (see
     * OverdueTreatmentActionService, DashboardCardService::overdueTreatmentActionsCount()).
     * A missing/blank/unparsable due date, or an action already marked done, is never overdue.
     */
    public static function isOverdue(?string $dueDate, string $status, ?DateTimeImmutable $now = null): bool
    {
        if ($status === self::STATUS_DONE) {
            return false;
        }

        if ($dueDate === null || trim($dueDate) === '') {
            return false;
        }

        try {
            $due = new DateTimeImmutable($dueDate);
        } catch (\Exception) {
            return false;
        }

        $now ??= new DateTimeImmutable('today');

        return $due < $now;
    }
}
