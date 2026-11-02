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
 * Issue #370 : phases de réponse à incident (NIST SP 800-61), horodatées. La détection est la date
 * d'ouverture de l'incident et la clôture sa date de clôture GLPI ; les quatre phases
 * intermédiaires sont saisies par l'équipe. Aucune base ni fonction GLPI : testable seul.
 */
final class IncidentPhases
{
    /** Phase => colonne de l'incident, dans l'ordre. */
    public const PHASES = [
        'detection'       => 'date',
        'acknowledgement' => 'acknowledged_at',
        'containment'     => 'contained_at',
        'eradication'     => 'eradicated_at',
        'recovery'        => 'recovered_at',
        'closure'         => 'closedate',
    ];

    /** Colonnes saisies dans la section « Phases de réponse ». */
    public const EDITABLE = ['acknowledged_at', 'contained_at', 'eradicated_at', 'recovered_at'];

    /**
     * @param array<string, mixed> $fields Champs de l'incident.
     * @return list<array{
     *     key: string, field: string, at: ?string, hours: ?int, done: bool, current: bool, out_of_order: bool
     * }>
     */
    public static function compute(array $fields): array
    {
        $start = self::timestamp($fields['date'] ?? null);
        $phases = [];
        $lastDone = -1;
        $previous = null;
        foreach (self::PHASES as $key => $field) {
            $time = self::timestamp($fields[$field] ?? null);
            $phases[] = [
                'key'          => $key,
                'field'        => $field,
                'at'           => $time === null ? null : date('Y-m-d H:i:s', $time),
                'hours'        => $time === null || $start === null ? null : (int) floor(max(0, $time - $start) / 3600),
                'done'         => $time !== null,
                'current'      => false,
                // Une phase datée avant une phase précédente : faute de saisie probable, signalée.
                'out_of_order' => $time !== null && $previous !== null && $time < $previous,
            ];
            if ($time !== null) {
                $lastDone = count($phases) - 1;
                $previous = $time;
            }
        }
        if ($lastDone >= 0) {
            $phases[$lastDone]['current'] = true;
        }

        return $phases;
    }

    /**
     * Normalise une saisie de formulaire (« 2026-10-18T20:00 » d'un champ datetime-local).
     * Vide => 'NULL' (efface la date) ; illisible => null (champ ignoré).
     */
    public static function normalizeInput(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'NULL') {
            return 'NULL';
        }
        $time = strtotime(str_replace('T', ' ', $value));

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'NULL') {
            return null;
        }
        $time = strtotime((string) $value);

        return $time === false ? null : $time;
    }
}
