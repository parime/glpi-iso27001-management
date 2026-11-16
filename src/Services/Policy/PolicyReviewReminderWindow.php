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

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Pure "is this policy due for review" decision, extracted out of PolicyReviewReminderService so
 * it can be unit tested directly (tests/Unit/Services/Policy/PolicyReviewReminderWindowTest.php)
 * without a running GLPI instance, unlike the risk register's own
 * GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService, whose equivalent window check is
 * inlined into its `$prototype->find()` WHERE clause and documented as untestable in isolation
 * (see phpstan.neon.dist). A policy is flagged from REMINDER_WINDOW_DAYS before its
 * `next_review_date` and stays flagged for as long as it remains overdue, mirroring
 * ReviewReminderService::REMINDER_WINDOW_DAYS exactly (same 30-day lead time convention across
 * this plugin's review-reminder mechanisms).
 *
 * An `archived` policy is never due: it is a superseded version kept only for historical record
 * (ISO 27001 A.5.1.2 traceability), not a document anyone should still be reviewing - the
 * equivalent of the risk register's own `status <> 'closed'` exclusion.
 */
final class PolicyReviewReminderWindow
{
    public const REMINDER_WINDOW_DAYS = 30;

    public static function isDue(string $status, ?string $nextReviewDate, string $today): bool
    {
        if ($status === PolicyLifecycle::STATUS_ARCHIVED) {
            return false;
        }

        if ($nextReviewDate === null || $nextReviewDate === '') {
            return false;
        }

        $limit = date('Y-m-d', strtotime($today . ' +' . self::REMINDER_WINDOW_DAYS . ' days'));

        return $nextReviewDate <= $limit;
    }
}
