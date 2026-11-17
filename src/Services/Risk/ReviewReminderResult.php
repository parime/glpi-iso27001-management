<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Outcome of one ReviewReminderService::notify() run, reported by
 * \GlpiPlugin\Grcmanager\Risk::cronReviewreminder() (inc/risk.class.php) to the GLPI Cron log.
 */
final class ReviewReminderResult
{
    public function __construct(
        private readonly int $due,
        private readonly int $notified
    ) {
    }

    /**
     * Number of risks found with a review date passed or within the reminder window.
     */
    public function getDue(): int
    {
        return $this->due;
    }

    /**
     * Number of those risks for which NotificationEvent::raiseEvent() actually fired (GLPI
     * notifications enabled and at least one active Notification configured for the event).
     */
    public function getNotified(): int
    {
        return $this->notified;
    }
}
