<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

use NotificationEvent;
use GlpiPlugin\Grcmanager\Risk;

/**
 * Finds risks whose review date has passed or is approaching, and raises GLPI's native
 * NotificationEvent for each one (see inc/notificationtargetrisk.class.php for the
 * NotificationTarget, and src/Install/Installer.php for the seeded Notification/
 * NotificationTemplate rows). Driven by the daily Cron task
 * \GlpiPlugin\Grcmanager\Risk::cronReviewreminder() (see issue tracked in
 * docs/design/DEVELOPMENT_PLAN.md, Sprint 2).
 *
 * Sprint 5 generalized this service to also drive \GlpiPlugin\Grcmanager\SupplierRisk's own
 * cronReviewreminder(): both itemtypes share the exact same `status`/`review_date` columns and the
 * exact same "review due" semantics (see RiskAssessmentTrait), only the itemtype/table differ, so
 * a single `$itemtype` constructor argument was enough, no second copy of this query/notify logic.
 * GLPI's own Cron task model still requires one CronTask::Register() and one static
 * `cron<name>()` entry point per itemtype (see src/Install/Installer.php), and each itemtype needs
 * its own NotificationTarget class (GLPI dispatches NotificationEvent::raiseEvent() by the
 * notified item's own get_class()), so those two stay one-per-itemtype; only the query/notify
 * logic below is shared.
 *
 * Issue #30 (\GlpiPlugin\Grcmanager\ComplianceObligation) reuses this same service for a third itemtype
 * that has no `status` column at all (it has `compliance_status`, which grades compliance, not
 * whether the obligation is still being tracked) - the previously-hardcoded
 * `'status' => ['<>', 'closed']` exclusion is now the optional `$excludeCriteria` constructor
 * argument below, defaulting to that exact same criterion so \GlpiPlugin\Grcmanager\Risk and
 * \GlpiPlugin\Grcmanager\SupplierRisk see no behaviour change; \GlpiPlugin\Grcmanager\ComplianceObligation's own
 * cronReviewreminder() passes an empty array instead.
 *
 * NOTE: depends on GLPI's legacy global-namespace CommonDBTM classes (\GlpiPlugin\Grcmanager\Risk and,
 * dynamically, \GlpiPlugin\Grcmanager\SupplierRisk) and the NotificationEvent core class, not
 * unit-tested in isolation, same exclusion rationale as the sibling plugin
 * glpi-vulnerability-manager, see phpstan.neon.dist.
 */
final class ReviewReminderService
{
    /**
     * A risk is flagged from this many days before its review_date, and stays flagged for as
     * long as it remains overdue (no upper bound past the date); matches the dashboard card
     * risksPendingReviewCount()'s own "review_date <= today" definition of overdue, extended here
     * to also cover risks approaching their review date, not only those already past it.
     */
    private const REMINDER_WINDOW_DAYS = 30;

    public const EVENT = 'review_due';

    /**
     * @param class-string $itemtype A CommonDBTM class with a `review_date` column and a
     *                                `review_due` NotificationEvent target, defaults to
     *                                \GlpiPlugin\Grcmanager\Risk for backward compatibility with Sprint 2
     *                                callers that never passed an argument.
     * @param array<int|string, mixed> $excludeCriteria Additional CommonDBTM::find() criteria
     *                                merged into the base review_date window, e.g. to exclude a
     *                                terminal status value. Defaults to the exact criterion this
     *                                service always applied before issue #30
     *                                (`status <> closed`), matching \GlpiPlugin\Grcmanager\Risk's and
     *                                \GlpiPlugin\Grcmanager\SupplierRisk's own `status` enum. Pass an
     *                                empty array for an itemtype with no such column (see class
     *                                docblock, issue #30).
     */
    public function __construct(
        private readonly string $itemtype = \GlpiPlugin\Grcmanager\Risk::class,
        private readonly array $excludeCriteria = ['status' => ['<>', 'closed']]
    ) {
    }

    public function notify(): ReviewReminderResult
    {
        $itemtype = $this->itemtype;

        /** @var \CommonDBTM $prototype */
        $prototype = new $itemtype();

        $criteria = $this->excludeCriteria;
        $criteria['review_date'] = ['<=', date('Y-m-d', strtotime('+' . self::REMINDER_WINDOW_DAYS . ' days'))];
        $criteria[] = ['NOT' => ['review_date' => null]];

        $dueRisks = $prototype->find($criteria);

        $notified = 0;

        foreach ($dueRisks as $riskRow) {
            /** @var \CommonDBTM $item */
            $item = new $itemtype();
            if (!$item->getFromDB((int) $riskRow['id'])) {
                continue;
            }

            if (NotificationEvent::raiseEvent(self::EVENT, $item)) {
                $notified++;
            }
        }

        return new ReviewReminderResult(count($dueRisks), $notified);
    }
}
