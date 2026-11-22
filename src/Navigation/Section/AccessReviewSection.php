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
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\PeriodicReview;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\ShareAccess;

/**
 * Issue #1038 : qui a accès à quoi (revues des accès, hygiène des comptes, MFA, partages). Le
 * personnel et la sensibilisation sont dans PeopleSection. Les écrans de crise et de continuité
 * partent dans Assistance (lot 4, #1041) : ils restent déclarés ici pour que ce lot les déplace avec
 * l'option « from ».
 */
final class AccessReviewSection extends AbstractSection
{
    public static function parent(): string
    {
        return AccessReview::class;
    }

    public static function after(): string
    {
        return ProcessingActivity::class;
    }

    public static function title(): string
    {
        return __('Access and accounts', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Who has access to what: access reviews, periodic reviews, account hygiene, multi-factor '
                . 'authentication and file share permissions.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['access_review', 'periodic_review'];
    }

    public static function pages(): array
    {
        return [
            PeriodicReview::class,
            AccountHygiene::class,
            // Issue #852 : couverture de l'authentification multifacteur par compte (A.8.5).
            MfaCoverage::class,
            // Issue #846 : droits d'accès aux partages de fichiers Windows (A.5.15, A.5.18, A.8.3).
            ShareAccess::class,
            CrisisExercise::class,
            // Issue #377 : processus métier et analyses d'impact sur l'activité (BIA).
            BusinessProcess::class,
            // Issue #776 : scénarios de crise sécurité de l'information du PCA (A.5.29).
            CrisisScenario::class,
        ];
    }
}
