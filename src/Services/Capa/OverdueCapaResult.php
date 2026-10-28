<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Capa;

/**
 * Outcome of one OverdueCapaService::notify() run, reported by
 * \GlpiPlugin\Grcmanager\Nonconformity::cronOverduecapa() (inc/nonconformity.class.php) to the GLPI Cron
 * log. Same shape as the sibling GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderResult.
 */
final class OverdueCapaResult
{
    public function __construct(
        private readonly int $due,
        private readonly int $notified
    ) {
    }

    /**
     * Number of non-conformities found with a due date passed and not yet closed/verified.
     */
    public function getDue(): int
    {
        return $this->due;
    }

    /**
     * Number of those non-conformities for which NotificationEvent::raiseEvent() actually fired
     * (GLPI notifications enabled and at least one active Notification configured for the event).
     */
    public function getNotified(): int
    {
        return $this->notified;
    }
}
