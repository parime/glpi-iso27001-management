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
 * Issue #370 : journal des actions d'investigation et de remédiation d'un incident. Synthèse et
 * empreinte du contenu pour le PDF « Journal des actions et remédiation ». Sans base : testable seul.
 */
final class ActionLog
{
    public const TOOLS = [
        'edr', 'powershell', 'active_directory', 'm365', 'firewall', 'backup', 'forensic', 'onsite', 'other',
    ];

    public const RESULTS = ['success', 'partial', 'failure', 'to_check'];

    /** Champs qui entrent dans l'empreinte, dans cet ordre. */
    private const HASHED = ['id', 'date', 'users_id', 'target', 'tool', 'command', 'finding', 'result'];

    /**
     * @param list<array<string, mixed>> $actions Lignes du journal.
     * @return array{count: int, first: ?string, last: ?string, results: array<string, int>, users: list<int>}
     */
    public static function summary(array $actions): array
    {
        $dates = array_filter(array_map(static fn (array $a): string => (string) ($a['date'] ?? ''), $actions));
        sort($dates);
        $results = array_fill_keys(self::RESULTS, 0);
        $users = [];
        foreach ($actions as $action) {
            $result = (string) ($action['result'] ?? '');
            if (isset($results[$result])) {
                $results[$result]++;
            }
            $user = (int) ($action['users_id'] ?? 0);
            if ($user > 0) {
                $users[$user] = $user;
            }
        }

        return [
            'count'   => count($actions),
            'first'   => $dates === [] ? null : reset($dates),
            'last'    => $dates === [] ? null : end($dates),
            'results' => $results,
            'users'   => array_values($users),
        ];
    }

    /**
     * Empreinte SHA-256 du contenu du journal (ordre chronologique, champs fixes) : imprimée dans le
     * PDF et consignée dans l'historique de l'incident, elle permet de vérifier qu'un PDF
     * correspond au journal à une date donnée.
     *
     * @param list<array<string, mixed>> $actions
     */
    public static function contentHash(array $actions): string
    {
        $key = static fn (array $action): array => [(string) ($action['date'] ?? ''), (int) ($action['id'] ?? 0)];
        usort($actions, static fn (array $a, array $b): int => $key($a) <=> $key($b));
        $canonical = [];
        foreach ($actions as $action) {
            $row = [];
            foreach (self::HASHED as $field) {
                $row[$field] = (string) ($action[$field] ?? '');
            }
            $canonical[] = $row;
        }

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
