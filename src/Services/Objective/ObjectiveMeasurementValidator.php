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
 * Business rule behind `\GlpiPlugin\Grcmanager\ObjectiveMeasurement` (issue #32, ISO 27001 clause 6.2):
 * extracted as pure PHP so it is unit-testable without a running GLPI instance, same
 * "logique pure-PHP testable sous src/Services/" convention as
 * GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService.
 *
 * An objective's target is not always a single number (see \GlpiPlugin\Grcmanager\Objective's own
 * docblock: `target_value` is nullable, `target_description` carries a free-text qualitative
 * target alongside/instead of it). A measurement logged against a QUANTITATIVE objective
 * (`target_value` set) must itself carry a numeric value: otherwise there is nothing to plot on
 * the trajectory the objective exists to track. A measurement logged against a QUALITATIVE-ONLY
 * objective has no number to compare against, so its value stays optional, but the comment then
 * becomes the only record of progress and cannot be left empty (an empty comment AND no value
 * would be a measurement recording literally nothing).
 */
final class ObjectiveMeasurementValidator
{
    public static function isValid(bool $hasNumericTarget, ?float $value, string $comment): bool
    {
        if ($hasNumericTarget) {
            return $value !== null;
        }

        return $value !== null || trim($comment) !== '';
    }
}
