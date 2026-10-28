<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Capa;

use NotificationEvent;
use GlpiPlugin\Grcmanager\Nonconformity;

/**
 * Finds non-conformities whose due date has passed and that are not yet closed/verified, and
 * raises GLPI's native NotificationEvent for each one (see
 * inc/notificationtargetnonconformity.class.php for the NotificationTarget, and
 * src/Install/Installer.php for the seeded Notification/NotificationTemplate rows). Driven by the
 * daily Cron task \GlpiPlugin\Grcmanager\Nonconformity::cronOverduecapa() (Sprint 4). Mirrors
 * GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService's own structure exactly.
 *
 * NOTE: depends on GLPI's legacy global-namespace CommonDBTM class (\GlpiPlugin\Grcmanager\Nonconformity)
 * and the NotificationEvent core class, not unit-tested in isolation, same exclusion rationale as
 * ReviewReminderService, see phpstan.neon.dist.
 */
final class OverdueCapaService
{
    public const EVENT = 'capa_overdue';

    public function notify(): OverdueCapaResult
    {
        $nonconformity = new \GlpiPlugin\Grcmanager\Nonconformity();

        $overdue = $nonconformity->find([
            'status'   => ['NOT IN', \GlpiPlugin\Grcmanager\Nonconformity::CLOSED_STATUSES],
            'due_date' => ['<', date('Y-m-d')],
            ['NOT' => ['due_date' => null]],
        ]);

        $notified = 0;

        foreach ($overdue as $row) {
            $item = new \GlpiPlugin\Grcmanager\Nonconformity();
            if (!$item->getFromDB((int) $row['id'])) {
                continue;
            }

            if (NotificationEvent::raiseEvent(self::EVENT, $item)) {
                $notified++;
            }
        }

        return new OverdueCapaResult(count($overdue), $notified);
    }
}
