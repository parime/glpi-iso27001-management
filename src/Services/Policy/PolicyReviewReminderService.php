<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Policy;

use NotificationEvent;
use GlpiPlugin\Grcmanager\Policy;

/**
 * Finds security policies whose next review date has passed or is approaching, and raises GLPI's
 * native NotificationEvent for each one (see inc/notificationtargetpolicy.class.php for the
 * NotificationTarget, and src/Install/Installer.php for the seeded Notification/
 * NotificationTemplate rows). Driven by the daily Cron task
 * \GlpiPlugin\Grcmanager\Policy::cronReviewreminder() (issue #28, ISO/IEC 27001:2022
 * A.5.1/A.5.1.1/A.5.1.2).
 *
 * Mirrors GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService's overall structure (same
 * find()-then-raiseEvent()-per-row shape), but is its OWN class rather than a third caller of that
 * shared service: ReviewReminderService's query is hardcoded to `status <> 'closed'` and the
 * `review_date` column, which doesn't fit this itemtype's own `status`
 * (draft/approved/archived) / `next_review_date` columns (see PolicyLifecycle,
 * PolicyReviewReminderWindow). Fetches every non-archived policy with a `next_review_date` set,
 * broader than strictly necessary, then narrows to the actually-due ones with the pure, unit-tested
 * PolicyReviewReminderWindow::isDue() rather than re-deriving that window inline in SQL - the one
 * deliberate improvement over ReviewReminderService, which leaves its own equivalent window
 * untestable in isolation (see its docblock and phpstan.neon.dist).
 *
 * NOTE: depends on GLPI's legacy global-namespace CommonDBTM class (\GlpiPlugin\Grcmanager\Policy) and
 * the NotificationEvent core class, not unit-tested in isolation, same exclusion rationale as
 * ReviewReminderService, see phpstan.neon.dist.
 */
final class PolicyReviewReminderService
{
    public const EVENT = 'policy_review_due';

    public function notify(): PolicyReviewReminderResult
    {
        $prototype = new \GlpiPlugin\Grcmanager\Policy();

        $candidates = $prototype->find([
            'status' => ['<>', PolicyLifecycle::STATUS_ARCHIVED],
            ['NOT' => ['next_review_date' => null]],
        ]);

        $today    = date('Y-m-d');
        $due      = 0;
        $notified = 0;

        foreach ($candidates as $row) {
            if (!PolicyReviewReminderWindow::isDue((string) $row['status'], $row['next_review_date'], $today)) {
                continue;
            }

            $due++;

            $item = new \GlpiPlugin\Grcmanager\Policy();
            if (!$item->getFromDB((int) $row['id'])) {
                continue;
            }

            if (NotificationEvent::raiseEvent(self::EVENT, $item)) {
                $notified++;
            }
        }

        return new PolicyReviewReminderResult($due, $notified);
    }
}
