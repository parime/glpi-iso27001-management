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

use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\Maturity;
use GlpiPlugin\Grcmanager\MaturityCampaign;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Navigation\Sections;

/**
 * Pilotage : tableau de bord du SMSI, en tête du menu GRC (issue #247), et les écrans de suivi global.
 */
final class DashboardSection extends AbstractSection
{
    public static function parent(): string
    {
        return Dashboard::class;
    }

    public static function after(): string
    {
        return Sections::FIRST;
    }

    public static function pages(): array
    {
        return [
            Maturity::class,
            // Issue #603 : maturité par mesure (niveau actuel, niveau cible et écarts).
            MaturityCampaign::class,
            ComplianceEvent::class,
            // Issue #614 : contrôles de cohérence du SMSI avant un audit.
            ConsistencyCheck::class,
            // Issue #619 : préparation à la certification ISO 27001.
            CertificationReadiness::class,
        ];
    }
}
