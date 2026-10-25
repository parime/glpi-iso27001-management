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

namespace GlpiPlugin\Grcmanager\Services\Exercise;

/**
 * Issue #185 : reprise d'un élément touché pendant une crise, comparée à son objectif de temps
 * de reprise (RTO, en heures) compté depuis l'activation de la cellule de crise.
 */
final class RecoveryTracker
{
    public const RESTORED_ON_TIME = 'restored_on_time';
    public const RESTORED_LATE = 'restored_late';
    public const RESTORED = 'restored';
    public const RUNNING = 'running';
    public const OVERDUE = 'overdue';
    public const NO_RTO = 'no_rto';

    /**
     * @return array{state: string, hours: ?float, due: ?string}
     *     hours : durée de la reprise si l'élément est rétabli, sinon temps écoulé ; due : échéance
     *     du RTO (null sans RTO).
     */
    public static function status(string $startedAt, int $rtoHours, ?string $restoredAt, string $now): array
    {
        $start = strtotime($startedAt);
        if ($start === false) {
            return ['state' => self::NO_RTO, 'hours' => null, 'due' => null];
        }
        $restored = $restoredAt !== null && $restoredAt !== '' ? strtotime($restoredAt) : false;
        $end = $restored !== false ? $restored : strtotime($now);
        $hours = $end === false ? null : round(max(0, $end - $start) / 3600, 1);
        $due = $rtoHours > 0 ? date('Y-m-d H:i:s', $start + $rtoHours * 3600) : null;

        if ($restored !== false) {
            if ($rtoHours <= 0) {
                $state = self::RESTORED;
            } else {
                $state = $restored - $start <= $rtoHours * 3600 ? self::RESTORED_ON_TIME : self::RESTORED_LATE;
            }
        } elseif ($rtoHours <= 0) {
            $state = self::NO_RTO;
        } else {
            $state = $end !== false && $end - $start > $rtoHours * 3600 ? self::OVERDUE : self::RUNNING;
        }

        return ['state' => $state, 'hours' => $hours, 'due' => $due];
    }
}
