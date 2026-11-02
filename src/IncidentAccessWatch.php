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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use CronTask;
use GlpiPlugin\Grcmanager\Services\Incident\AccessSnapshot;
use NotificationEvent;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #371 : alertes au groupe RSSI et à l'administrateur GLPI quand l'accès au module des
 * incidents de sécurité change : droits d'un profil accordés ou étendus, profil donnant accès
 * attribué, module désactivé. Chaque écart est aussi inscrit dans le journal d'audit. Rien n'est
 * modifié automatiquement : l'alerte laisse la décision au RSSI.
 */
class IncidentAccessWatch extends CommonGLPI
{
    public const ACCESS_CHANGED_EVENT = 'access_changed';
    public const MODULE_DISABLED_EVENT = 'module_disabled';

    private const CONFIG_TABLE = 'glpi_plugin_grcmanager_securityincidentconfig';

    public static function getTypeName($nb = 0)
    {
        return __('Access to the security incidents module', 'grcmanager');
    }

    /**
     * @return array{profiles: array<int, int>, users: list<string>}
     */
    public static function currentSnapshot(): array
    {
        global $DB;

        $profiles = [];
        $rows = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => PluginGrcmanagerSecurityIncident::$rightname, 'rights' => ['>', 0]],
        ]);
        foreach ($rows as $row) {
            $profiles[(int) $row['profiles_id']] = (int) $row['rights'];
        }
        $users = [];
        if ($profiles !== []) {
            $rows = $DB->request([
                'SELECT' => ['users_id', 'profiles_id', 'entities_id'],
                'FROM'   => 'glpi_profiles_users',
                'WHERE'  => ['profiles_id' => array_keys($profiles)],
            ]);
            foreach ($rows as $row) {
                $users[] = sprintf('%d:%d:%d', $row['users_id'], $row['profiles_id'], $row['entities_id']);
            }
        }
        sort($users);

        return ['profiles' => $profiles, 'users' => $users];
    }

    /**
     * Null avant le premier passage.
     *
     * @return array{profiles?: array<int|string, int>, users?: list<string>}|null
     */
    public static function storedSnapshot(): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['access_snapshot'],
            'FROM'   => self::CONFIG_TABLE,
            'LIMIT'  => 1,
        ])->current();
        $decoded = json_decode((string) ($row['access_snapshot'] ?? ''), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{profiles: array<int, int>, users: list<string>} $snapshot
     */
    public static function storeSnapshot(array $snapshot): void
    {
        global $DB;

        $DB->update(self::CONFIG_TABLE, ['access_snapshot' => json_encode($snapshot)], ['id' => 1]);
    }

    /**
     * Compare l'instantané courant au précédent, trace et signale les écarts.
     *
     * @return list<array{
     *     type: string, profiles_id: int, users_id: int, entities_id: int, before: int, after: int
     * }>
     */
    public static function check(): array
    {
        $current = self::currentSnapshot();
        $previous = self::storedSnapshot();
        self::storeSnapshot($current);
        if ($previous === null) {
            // Premier passage : référence, rien à signaler.
            return [];
        }
        $changes = AccessSnapshot::diff($previous, $current);
        foreach ($changes as $change) {
            AuditLog::record('rights_change', 'Profile', $change['profiles_id'], 0, 'success', $change);
        }
        if ($changes !== []) {
            NotificationEvent::raiseEvent(self::ACCESS_CHANGED_EVENT, new self(), [
                'changes' => $changes,
                'widens'  => AccessSnapshot::widensAccess($changes),
            ]);
        }

        return $changes;
    }

    /**
     * Module désactivé dans la configuration : alerte immédiate.
     */
    public static function moduleDisabled(): void
    {
        NotificationEvent::raiseEvent(self::MODULE_DISABLED_EVENT, new self(), ['changes' => []]);
    }

    public static function cronAccesswatch(CronTask $task): int
    {
        $changes = self::check();
        $task->addVolume(count($changes));

        return $changes === [] ? 0 : 1;
    }

    /**
     * Texte lisible d'un écart, pour la notification.
     *
     * @param array{type: string, profiles_id: int, users_id: int, entities_id: int} $change
     */
    public static function describe(array $change): string
    {
        $profile = \Dropdown::getDropdownName('glpi_profiles', $change['profiles_id']);
        $user = $change['users_id'] > 0 ? getUserName($change['users_id']) : '';
        $entity = \Dropdown::getDropdownName('glpi_entities', $change['entities_id']);

        $profileTexts = [
            'right_granted'  => __('Profile %s now has access to the module', 'grcmanager'),
            'right_extended' => __('Rights of profile %s on the module extended', 'grcmanager'),
            'right_reduced'  => __('Rights of profile %s on the module reduced', 'grcmanager'),
            'right_removed'  => __('Profile %s no longer has access to the module', 'grcmanager'),
        ];
        $userTexts = [
            'profile_assigned'   => __('%1$s received profile %2$s (entity %3$s)', 'grcmanager'),
            'profile_unassigned' => __('%1$s lost profile %2$s (entity %3$s)', 'grcmanager'),
        ];
        if (isset($profileTexts[$change['type']])) {
            return sprintf($profileTexts[$change['type']], $profile);
        }
        if (isset($userTexts[$change['type']])) {
            return sprintf($userTexts[$change['type']], $user, $profile, $entity);
        }

        return $change['type'];
    }
}
