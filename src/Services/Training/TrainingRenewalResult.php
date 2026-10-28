<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Training;

/**
 * Outcome of one TrainingRenewalService::notify() run, reported by
 * \GlpiPlugin\Grcmanager\Training::cronRenewaldue() (inc/training.class.php) to the GLPI Cron log. Same
 * shape as the sibling GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaResult.
 */
final class TrainingRenewalResult
{
    public function __construct(
        private readonly int $due,
        private readonly int $notified
    ) {
    }

    /**
     * Number of trainings found with at least one participant overdue for renewal.
     */
    public function getDue(): int
    {
        return $this->due;
    }

    /**
     * Number of those trainings for which NotificationEvent::raiseEvent() actually fired (GLPI
     * notifications enabled and at least one active Notification configured for the event).
     */
    public function getNotified(): int
    {
        return $this->notified;
    }
}
