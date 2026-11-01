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

use GlpiPlugin\Grcmanager\Install\Installer;
use Notification;

/**
 * Issue #363 : à la mise à jour, les notifications d'incident gardant leur nom d'origine (avec un
 * tiret cadratin) sont renommées ; un nom modifié par l'administrateur est conservé.
 */
final class EmDashRenameTest extends GrcmanagerIntegrationTestCase
{
    public function testOriginalNamesAreRenamedAndCustomNamesKept(): void
    {
        global $DB;

        $itemtype = \PluginGrcmanagerSecurityIncident::class;
        $rows = [];
        foreach (['new', 'update'] as $event) {
            $notification = new Notification();
            $this->assertTrue($notification->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event]), $event);
            $rows[$event] = [(int) $notification->getID(), (string) $notification->fields['name']];
        }
        try {
            $table = Notification::getTable();
            $DB->update($table, ['name' => "Security incident \u{2014} new"], ['id' => $rows['new'][0]]);
            $DB->update($table, ['name' => "Mon nom \u{2014} incident"], ['id' => $rows['update'][0]]);

            $seed = new \ReflectionMethod(Installer::class, 'seedSecurityIncidentNotifications');
            $seed->invoke(new Installer());

            $notification = new Notification();
            $notification->getFromDB($rows['new'][0]);
            $this->assertSame('Security incident - new', $notification->fields['name']);
            $notification->getFromDB($rows['update'][0]);
            $this->assertSame("Mon nom \u{2014} incident", $notification->fields['name'], 'Nom personnalisé conservé.');
        } finally {
            foreach ($rows as [$id, $name]) {
                $DB->update(Notification::getTable(), ['name' => $name], ['id' => $id]);
            }
        }
    }
}
