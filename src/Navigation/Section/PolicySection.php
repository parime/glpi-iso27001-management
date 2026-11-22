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
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\OperatingProcedure;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\PolicyTemplate;

/**
 * Issue #1038 : les règles écrites de l'organisation (politiques, modèles, procédures) et les
 * applications approuvées (A.5.23, #622), sorties de « Conformité » et de « Risques ».
 */
final class PolicySection extends AbstractSection
{
    public static function parent(): string
    {
        return Policy::class;
    }

    public static function after(): string
    {
        return Control::class;
    }

    public static function title(): string
    {
        return __('Policies and procedures', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'The written rules of the organisation: policies, ready-to-adapt templates, operating procedures and '
                . 'the online services approved for use.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['policy_review', 'policy_approval', 'policy_acknowledgement'];
    }

    public static function pages(): array
    {
        return [
            // Issue #545 : modèles de politiques prêts à adapter, importés en brouillon.
            PolicyTemplate::class,
            // Issue #775 : registre des procédures d'exploitation et couverture (A.5.37).
            OperatingProcedure::class,
            // Issue #879 : mesures couvertes par les textes de la bibliothèque des politiques.
            PolicyCoverage::class,
            // Issue #622 : applications et services en ligne approuvés (A.5.23).
            ApprovedApplication::class,
        ];
    }
}
