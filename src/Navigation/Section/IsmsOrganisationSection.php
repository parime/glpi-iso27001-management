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

use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\PersonnelCompliance;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;

/**
 * Issue #1038 : l'organisation du SMSI (clauses 4 à 10) : exigences, contexte, responsabilités,
 * décisions déléguées (#618, jusqu'ici sans entrée de menu), contacts officiels et obligations.
 */
final class IsmsOrganisationSection extends AbstractSection
{
    public static function parent(): string
    {
        return ContextItem::class;
    }

    public static function after(): string
    {
        return PersonnelCompliance::class;
    }

    public static function title(): string
    {
        return __('ISMS organisation', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'How the management system is organised: the requirements of clauses 4 to 10, the context, the '
                . 'responsibilities, the decisions delegated during an absence, the official contacts and the '
                . 'obligations.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['obligation_review'];
    }

    public static function pages(): array
    {
        return [
            IsmsRequirement::class,
            // Issue #612 : matrice des responsabilités du SMSI (article 5.3, mesure A.5.3).
            ResponsibilityActivity::class,
            // Issue #618 : décisions prises par un suppléant pendant l'absence du titulaire.
            Delegation::class,
            // Issue #771 : registre des contacts officiels (A.5.5, A.5.6).
            OfficialContact::class,
            ComplianceObligation::class,
        ];
    }
}
