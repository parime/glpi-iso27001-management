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
use GlpiPlugin\Grcmanager\AssetSecurity;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\IsmsFunction;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\PersonnelCompliance;
use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\Training;

/**
 * Issue #1038 : le personnel et la sensibilisation (chapitre 7, A.6) : conformité de chaque
 * collaborateur, formations, hameçonnage, compétences, et comptes exposés dans des fuites de
 * données (déplacés de « Sécurité technique »).
 */
final class PeopleSection extends AbstractSection
{
    public static function parent(): string
    {
        return PersonnelCompliance::class;
    }

    public static function after(): string
    {
        return AccessReview::class;
    }

    public static function title(): string
    {
        return __('People and awareness', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'The staff side of security: the compliance of each person, training, phishing simulations, skills '
                . 'and the accounts exposed in data leaks.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['training', 'training_renewal'];
    }

    public static function pages(): array
    {
        return [
            Training::class,
            // Issue #604 : simulations d'hameçonnage (preuve de sensibilisation, 7.3 et A.6.3).
            PhishingCampaign::class,
            // Issue #774 : compétences requises par fonction, acquises et écarts (article 7.2).
            IsmsFunction::class,
            BreachExposure::class => ['from' => AssetSecurity::class],
        ];
    }
}
