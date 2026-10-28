<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Outcome of one PolicyReviewReminderService::notify() run, reported by
 * \GlpiPlugin\Grcmanager\Policy::cronReviewreminder() (inc/policy.class.php) to the GLPI Cron log. Same
 * shape as GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderResult, kept as its own class rather
 * than reused: a policy's "due" definition (PolicyReviewReminderWindow, `next_review_date`,
 * `archived` exclusion) is independent of the risk register's own `review_date`/`closed`
 * semantics, only the reporting shape happens to match.
 */
final class PolicyReviewReminderResult
{
    public function __construct(
        private readonly int $due,
        private readonly int $notified
    ) {
    }

    /**
     * Number of policies found with a next review date passed or within the reminder window.
     */
    public function getDue(): int
    {
        return $this->due;
    }

    /**
     * Number of those policies for which NotificationEvent::raiseEvent() actually fired (GLPI
     * notifications enabled and at least one active Notification configured for the event).
     */
    public function getNotified(): int
    {
        return $this->notified;
    }
}
