<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Training;

use Glpi\DBAL\QueryExpression;
use NotificationEvent;
use GlpiPlugin\Grcmanager\Training;

/**
 * Finds trainings that currently have at least one participant overdue for renewal (a completed
 * participation whose `completion_date` + the training's own `renewal_period_months` has passed,
 * see \GlpiPlugin\Grcmanager\Training::getOverdueParticipants() for the shared definition), and raises
 * GLPI's native NotificationEvent once per such training (see
 * inc/notificationtargettraining.class.php for the NotificationTarget, which resolves the actual
 * per-training recipient list from the same getOverdueParticipants() helper, and
 * src/Install/Installer.php for the seeded Notification/NotificationTemplate rows). Driven by the
 * daily Cron task \GlpiPlugin\Grcmanager\Training::cronRenewaldue() (Sprint 6). Mirrors
 * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService's own structure.
 *
 * NOTE: depends on GLPI's legacy global-namespace CommonDBTM class (\GlpiPlugin\Grcmanager\Training), its
 * runtime global $DB, and the NotificationEvent core class, not unit-tested in isolation, same
 * exclusion rationale as the sibling ReviewReminderService/OverdueCapaService, see
 * phpstan.neon.dist.
 */
final class TrainingRenewalService
{
    public const EVENT = 'training_renewal_due';

    public function notify(): TrainingRenewalResult
    {
        global $DB;

        $trainingsTable    = \GlpiPlugin\Grcmanager\Training::getTable();
        $participantsTable = 'glpi_plugin_grcmanager_trainings_users';

        $rows = $DB->request([
            'SELECT'     => [new QueryExpression('DISTINCT t.id AS id')],
            'FROM'       => $trainingsTable . ' AS t',
            'INNER JOIN' => [
                $participantsTable . ' AS links' => [
                    'FKEY' => ['t' => 'id', 'links' => 'plugin_grcmanager_trainings_id'],
                ],
            ],
            'WHERE'      => [
                'links.completion_status'  => 'completed',
                't.renewal_period_months'  => ['>', 0],
                new QueryExpression(
                    'DATE_ADD(links.completion_date, INTERVAL t.renewal_period_months MONTH) < CURDATE()'
                ),
            ],
        ]);

        $due      = 0;
        $notified = 0;

        foreach ($rows as $row) {
            $due++;

            $item = new \GlpiPlugin\Grcmanager\Training();
            if (!$item->getFromDB((int) $row['id'])) {
                continue;
            }

            if (NotificationEvent::raiseEvent(self::EVENT, $item)) {
                $notified++;
            }
        }

        return new TrainingRenewalResult($due, $notified);
    }
}
