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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #371 : surveillance des accès au module des incidents. Compare deux instantanés
 * (droits de chaque profil sur les incidents, utilisateurs qui détiennent ces profils) et liste
 * les écarts : droit accordé, étendu, réduit ou retiré, profil attribué ou retiré. Sans base :
 * testable seul.
 *
 * Instantané : ['profiles' => [profiles_id => droits (bits)],
 * 'users' => ["users_id:profiles_id:entities_id", ...]].
 */
final class AccessSnapshot
{
    /**
     * @param array{profiles?: array<int|string, int>, users?: list<string>} $before
     * @param array{profiles?: array<int|string, int>, users?: list<string>} $after
     * @return list<array{type: string, profiles_id: int, users_id: int, entities_id: int, before: int, after: int}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        $old = array_map('intval', $before['profiles'] ?? []);
        $new = array_map('intval', $after['profiles'] ?? []);
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $profileId) {
            $was = $old[$profileId] ?? 0;
            $now = $new[$profileId] ?? 0;
            if ($was === $now) {
                continue;
            }
            $type = match (true) {
                $was === 0       => 'right_granted',
                $now === 0       => 'right_removed',
                ($now & ~$was) !== 0 => 'right_extended',
                default          => 'right_reduced',
            };
            $changes[] = [
                'type' => $type, 'profiles_id' => (int) $profileId, 'users_id' => 0, 'entities_id' => 0,
                'before' => $was, 'after' => $now,
            ];
        }

        $oldUsers = array_flip($before['users'] ?? []);
        $newUsers = array_flip($after['users'] ?? []);
        foreach (array_keys($newUsers + $oldUsers) as $key) {
            if (isset($oldUsers[$key]) === isset($newUsers[$key])) {
                continue;
            }
            [$usersId, $profilesId, $entitiesId] = array_map('intval', explode(':', (string) $key) + [0, 0, 0]);
            $changes[] = [
                'type' => isset($newUsers[$key]) ? 'profile_assigned' : 'profile_unassigned',
                'profiles_id' => $profilesId, 'users_id' => $usersId, 'entities_id' => $entitiesId,
                'before' => 0, 'after' => 0,
            ];
        }

        return $changes;
    }

    /**
     * Écarts qui élargissent l'accès (à signaler en priorité).
     *
     * @param list<array{type: string}> $changes
     */
    public static function widensAccess(array $changes): bool
    {
        foreach ($changes as $change) {
            if (in_array($change['type'], ['right_granted', 'right_extended', 'profile_assigned'], true)) {
                return true;
            }
        }

        return false;
    }
}
