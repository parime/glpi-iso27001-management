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

namespace GlpiPlugin\Grcmanager\Services\Objective;

/**
 * Pure enum/decision logic behind `\GlpiPlugin\Grcmanager\Objective::status` (issue #32, ISO 27001 clause
 * 6.2), kept GLPI-independent (no $DB, no CommonDBTM, no __()) so it is unit-testable, same split
 * already used for the C/I/D classification register (ClassificationLevels): pure decision logic
 * here, thin $DB/HTML wrapper (labels/badges via __()) on the CommonDBTM class itself
 * (inc/objective.class.php).
 *
 * Five values rather than the risk register's four (identified/in_treatment/accepted/closed):
 * `not_started` (no measurement logged yet) is distinct from `on_track`/`at_risk` (in progress,
 * trajectory known), and `achieved`/`missed` are two different terminal outcomes an objective can
 * reach by its target_date, not a single generic "closed".
 */
final class ObjectiveStatuses
{
    public const NOT_STARTED = 'not_started';
    public const ON_TRACK    = 'on_track';
    public const AT_RISK     = 'at_risk';
    public const ACHIEVED    = 'achieved';
    public const MISSED      = 'missed';

    /**
     * @var array<int, string>
     */
    public const ALLOWED = [
        self::NOT_STARTED,
        self::ON_TRACK,
        self::AT_RISK,
        self::ACHIEVED,
        self::MISSED,
    ];

    /**
     * @var array<int, string> statuses that no longer call for a new measurement: the objective's
     *      outcome is already settled (achieved its target, or missed its deadline).
     */
    public const TERMINAL = [self::ACHIEVED, self::MISSED];

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::ALLOWED, true);
    }

    public static function isTerminal(?string $value): bool
    {
        return $value !== null && in_array($value, self::TERMINAL, true);
    }
}
