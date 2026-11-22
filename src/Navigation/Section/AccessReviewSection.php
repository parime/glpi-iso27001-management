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

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AccountHygiene;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\IsmsFunction;
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\PeriodicReview;
use GlpiPlugin\Grcmanager\PersonnelCompliance;
use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\ShareAccess;
use GlpiPlugin\Grcmanager\Training;

/**
 * Opérations récurrentes : accès, revues, personnes, continuité.
 */
final class AccessReviewSection extends AbstractSection
{
    public static function parent(): string
    {
        return AccessReview::class;
    }

    public static function after(): string
    {
        return Audit::class;
    }

    /** Plus de 7 sous-pages en attendant son découpage (lot 1 : menu GRC en rubriques parlantes). */
    public static function overLimit(): string
    {
        return '#1038';
    }

    public static function pages(): array
    {
        return [
            AccountHygiene::class,
            // Issue #546 : fiche de conformité sécurité de chaque collaborateur.
            PersonnelCompliance::class,
            // Issue #852 : couverture de l'authentification multifacteur par compte (A.8.5).
            MfaCoverage::class,
            // Issue #846 : droits d'accès aux partages de fichiers Windows (A.5.15, A.5.18, A.8.3).
            ShareAccess::class,
            PeriodicReview::class,
            Training::class,
            // Issue #604 : simulations d'hameçonnage (preuve de sensibilisation, 7.3 et A.6.3).
            PhishingCampaign::class,
            // Issue #774 : compétences requises par fonction, acquises et écarts (article 7.2).
            IsmsFunction::class,
            CrisisExercise::class,
            // Issue #377 : processus métier et analyses d'impact sur l'activité (BIA).
            BusinessProcess::class,
            // Issue #776 : scénarios de crise sécurité de l'information du PCA (A.5.29).
            CrisisScenario::class,
        ];
    }
}
