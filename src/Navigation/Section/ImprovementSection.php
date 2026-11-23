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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\GapStockReport;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Objective;

/**
 * Issue #1038 : non-conformités et amélioration (clauses 6.2, 9.3 et 10) : non-conformités, stock des
 * écarts, objectifs de sécurité et revues de direction, sortis de « Audits et amélioration ».
 * Issue #1047 : nommée « Non-conformités et amélioration » (et non plus « Amélioration continue ») pour
 * qu'on sache, sans connaître la norme, qu'on y trouve les non-conformités.
 */
final class ImprovementSection extends AbstractSection
{
    public static function parent(): string
    {
        return Nonconformity::class;
    }

    public static function after(): string
    {
        return Audit::class;
    }

    public static function title(): string
    {
        return __('Nonconformities and improvement', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Handle what does not work and follow the progress: nonconformities, open gaps, security objectives '
                . 'and management reviews.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['nonconformity', 'objective'];
    }

    public static function pages(): array
    {
        return [
            // Issue #870 : rapport du stock d'écarts (clauses 10.1 et 10.2).
            GapStockReport::class,
            Objective::class,
            ManagementReview::class,
        ];
    }
}
