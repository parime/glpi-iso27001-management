<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

use NotificationEvent;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;

/**
 * Finds risk treatment actions (issue #31, ISO 27001 clause 8.3/6.1.3) whose due date has passed
 * and that are not yet marked done, and raises GLPI's native NotificationEvent for each one (see
 * inc/notificationtargetrisktreatmentaction.class.php for the NotificationTarget, and
 * src/Install/Installer.php for the seeded Notification/NotificationTemplate rows). Driven by the
 * daily Cron task \GlpiPlugin\Grcmanager\RiskTreatmentAction::cronOverduetreatmentaction(). Mirrors
 * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService's own structure exactly (same "one action
 * per row, own due date/status" shape as a non-conformity's CAPA, except a risk can have several).
 *
 * NOTE: depends on GLPI's legacy global-namespace CommonDBTM class
 * (\GlpiPlugin\Grcmanager\RiskTreatmentAction) and the NotificationEvent core class, not unit-tested in
 * isolation, same exclusion rationale as OverdueCapaService, see phpstan.neon.dist.
 */
final class OverdueTreatmentActionService
{
    public const EVENT = 'treatment_action_overdue';

    public function notify(): OverdueTreatmentActionResult
    {
        $action = new \GlpiPlugin\Grcmanager\RiskTreatmentAction();

        $overdue = $action->find([
            'status'   => ['<>', TreatmentPlanRules::STATUS_DONE],
            'due_date' => ['<', date('Y-m-d')],
            ['NOT' => ['due_date' => null]],
        ]);

        $notified = 0;

        foreach ($overdue as $row) {
            $item = new \GlpiPlugin\Grcmanager\RiskTreatmentAction();
            if (!$item->getFromDB((int) $row['id'])) {
                continue;
            }

            if (NotificationEvent::raiseEvent(self::EVENT, $item)) {
                $notified++;
            }
        }

        return new OverdueTreatmentActionResult(count($overdue), $notified);
    }
}
