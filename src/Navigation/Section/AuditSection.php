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
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationSampling;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\GapStockReport;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Objective;

/**
 * Contrôle et amélioration continue (ISO 27001 chapitres 9 et 10).
 */
final class AuditSection extends AbstractSection
{
    public static function parent(): string
    {
        return Audit::class;
    }

    public static function after(): string
    {
        return Control::class;
    }

    public static function pages(): array
    {
        return [
            // Issue #773 : programme d'audit pluriannuel (ISO 19011).
            AuditProgramme::class,
            Nonconformity::class,
            // Issue #870 : rapport du stock d'écarts (clauses 10.1 et 10.2).
            GapStockReport::class,
            // Issue #772 : constats des audits de certification et état de leur levée.
            CertificationFinding::class,
            // Issue #772, lot 2 : plan d'audit de l'organisme (échantillonnage sur le cycle).
            CertificationSampling::class,
            Objective::class,
            ManagementReview::class,
        ];
    }
}
