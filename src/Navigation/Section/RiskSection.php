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

use GlpiPlugin\Grcmanager\ApprovedApplication;
use GlpiPlugin\Grcmanager\CausalLink;
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\ImpactAnalysis;
use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\ReportedRisk;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskAppetite;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\Waiver;

/**
 * Appréciation et traitement des risques.
 */
final class RiskSection extends AbstractSection
{
    public static function parent(): string
    {
        return Risk::class;
    }

    public static function after(): ?string
    {
        return MySpace::class;
    }

    public static function pages(): array
    {
        return [
            SupplierRisk::class,
            ImpactAnalysis::class,
            CausalLink::class,
            Waiver::class,
            RiskAppetite::class,
            RiskIndicator::class,
            // Issue #376 : études EBIOS Risk Manager.
            EbiosStudy::class,
            // Issue #872 : risques signalés par les collaborateurs, à trier.
            ReportedRisk::class,
            // Issue #622 : applications et services en ligne approuvés (A.5.23).
            ApprovedApplication::class,
        ];
    }
}
