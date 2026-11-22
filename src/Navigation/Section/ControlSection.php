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

use GlpiPlugin\Grcmanager\CodeSecurity;
use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlBlocker;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\CoverageRule;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\DataSubjectRequest;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\EvidencePack;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\OperatingProcedure;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\PolicyTemplate;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Referentials;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\SecureDevelopment;
use GlpiPlugin\Grcmanager\SoaScoping;

/**
 * Conformité : SoA, référentiels, exigences du SMSI, obligations, politiques, preuves.
 */
final class ControlSection extends AbstractSection
{
    public static function parent(): string
    {
        return Control::class;
    }

    public static function after(): ?string
    {
        return Risk::class;
    }

    public static function pages(): array
    {
        return [
            // Issue #867 : mesures bloquées (ce qui empêche chaque mesure d'être conforme).
            ControlBlocker::class,
            // Issue #873 : cadrage guidé de la SoA par questions (applicabilité et justifications).
            SoaScoping::class,
            Referentials::class,
            CustomFramework::class,
            IsmsRequirement::class,
            // Issue #608 : contexte de l'organisation (enjeux, parties intéressées).
            ContextItem::class,
            // Issue #771 : registre des contacts officiels (A.5.5, A.5.6).
            OfficialContact::class,
            // Issue #612 : matrice des responsabilités du SMSI (article 5.3, mesure A.5.3).
            ResponsibilityActivity::class,
            ComplianceObligation::class,
            ProcessingActivity::class,
            DataSubjectRequest::class,
            // Issue #620 : registre des violations de données personnelles.
            DataBreach::class,
            Policy::class,
            // Issue #879 : mesures couvertes par les textes de la bibliothèque des politiques.
            PolicyCoverage::class,
            // Issue #545 : modèles de politiques prêts à adapter, importés en brouillon.
            PolicyTemplate::class,
            // Issue #775 : registre des procédures d'exploitation et couverture (A.5.37).
            OperatingProcedure::class,
            // Issue #778 : sécurité physique des sites (A.7.1 à A.7.14), comparaison entre sites.
            PhysicalSecurity::class,
            // Issue #777 : développement sécurisé (registre des applications, mises en production).
            SecureDevelopment::class,
            // Issue #858 : sécurité du code lue dans GitHub et GitLab (A.8.28, A.8.29).
            CodeSecurity::class,
            Evidence::class,
            // Issue #868 : dossier de preuves exportable pour l'auditeur (archive ZIP par périmètre).
            EvidencePack::class,
            CoverageRule::class,
            ControlTest::class,
            // Issue #540 : registre d'informations DORA (référentiel DORA activé).
            DoraRegister::class,
        ];
    }
}
