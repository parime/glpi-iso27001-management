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

use GlpiPlugin\Grcmanager\CausalLink;
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\ImpactAnalysis;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskIndicator;

/**
 * Issue #1038 : analyses approfondies des risques (analyse d'impact, effet domino, EBIOS RM,
 * indicateurs), sorties de « Risques » pour garder 7 sous-pages au plus par rubrique.
 */
final class RiskAnalysisSection extends AbstractSection
{
    public static function parent(): string
    {
        return ImpactAnalysis::class;
    }

    public static function after(): string
    {
        return Risk::class;
    }

    public static function title(): string
    {
        return __('Risk analyses', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Go deeper into the risks: impact analyses, domino effects between risks, EBIOS Risk Manager studies '
                . 'and key risk indicators.',
            'grcmanager'
        );
    }

    public static function pages(): array
    {
        return [
            CausalLink::class,
            // Issue #376 : études EBIOS Risk Manager.
            EbiosStudy::class,
            RiskIndicator::class,
        ];
    }
}
