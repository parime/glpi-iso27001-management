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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

/**
 * Issue #142, phase 2 : rappel au responsable avant un événement du calendrier de conformité.
 */
final class ComplianceEventReminderTest extends GrcmanagerIntegrationTestCase
{
    private const MARK = '2020-01-01 00:00:00';

    public function testReminderIsSentOnceAndAgainWhenTheEventMoves(): void
    {
        global $DB;

        $this->assertSame(1, countElementsInTable('glpi_notifications', [
            'itemtype' => \PluginGrcmanagerComplianceEvent::class,
            'event'    => \PluginGrcmanagerComplianceEvent::REMINDER_EVENT,
        ]), 'Notification créée à l\'installation.');
        $this->assertSame(1, countElementsInTable('glpi_crontasks', [
            'itemtype' => \PluginGrcmanagerComplianceEvent::class,
            'name'     => 'complianceeventreminder',
        ]));

        $day = static fn (int $days): string => date('Y-m-d', strtotime('+' . $days . ' days'));
        $event = new \PluginGrcmanagerComplianceEvent();
        $id = (int) $event->add([
            'name'       => 'PHPUnit pentest annuel',
            'event_type' => 'pentest',
            'date_begin' => $day(10) . ' 09:00:00',
            'date_end'   => $day(10) . ' 17:00:00',
            'users_id'   => (int) \Session::getLoginUserID(),
        ]);
        try {
            $this->assertGreaterThan(0, $id);
            \PluginGrcmanagerComplianceEvent::runReminders(date('Y-m-d'));
            $this->assertNotNull($this->notifiedAt($id), 'Rappel envoyé 10 jours avant.');

            // Déjà prévenu : la tâche ne renvoie rien.
            $DB->update($event->getTable(), ['date_notified' => self::MARK], ['id' => $id]);
            \PluginGrcmanagerComplianceEvent::runReminders(date('Y-m-d'));
            $this->assertSame(self::MARK, $this->notifiedAt($id));

            // Événement déplacé : un nouveau rappel sera envoyé.
            $event->getFromDB($id);
            $event->update(['id' => $id, 'date_begin' => $day(12) . ' 09:00:00', 'date_end' => $day(12) . ' 17:00:00']);
            $this->assertNull($this->notifiedAt($id));
            \PluginGrcmanagerComplianceEvent::runReminders(date('Y-m-d'));
            $this->assertNotSame(self::MARK, $this->notifiedAt($id));
            $this->assertNotNull($this->notifiedAt($id));
        } finally {
            $event->delete(['id' => $id], true);
            $DB->delete('glpi_logs', ['itemtype' => \PluginGrcmanagerComplianceEvent::class, 'items_id' => $id]);
        }
    }

    private function notifiedAt(int $id): ?string
    {
        $event = new \PluginGrcmanagerComplianceEvent();
        $event->getFromDB($id);

        return $event->fields['date_notified'] !== null ? (string) $event->fields['date_notified'] : null;
    }
}
