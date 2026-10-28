<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Outcome of one OverdueTreatmentActionService::notify() run, reported by
 * \GlpiPlugin\Grcmanager\RiskTreatmentAction::cronOverduetreatmentaction() (inc/risktreatmentaction.class.php)
 * to the GLPI Cron log. Same shape as the sibling
 * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaResult.
 */
final class OverdueTreatmentActionResult
{
    public function __construct(
        private readonly int $due,
        private readonly int $notified
    ) {
    }

    /**
     * Number of treatment actions found with a due date passed and not yet marked done.
     */
    public function getDue(): int
    {
        return $this->due;
    }

    /**
     * Number of those treatment actions for which NotificationEvent::raiseEvent() actually fired
     * (GLPI notifications enabled and at least one active Notification configured for the event).
     */
    public function getNotified(): int
    {
        return $this->notified;
    }
}
