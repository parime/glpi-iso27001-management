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
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlBlocker;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\ImpactAnalysis;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\SecureDevelopment;
use GlpiPlugin\Grcmanager\SoaScoping;

/**
 * Issue #1038 : les mesures de l'Annexe A et la déclaration d'applicabilité (SoA). Les autres écrans
 * de l'ancienne rubrique « Conformité » sont rangés dans Politiques et procédures, Audits et preuves,
 * Réglementations, Vie privée et Organisation du SMSI. Sécurité physique, développement et code
 * partent dans Parc (lot 2, #1039), le registre DORA dans Gestion (lot 3, #1040) : ils restent
 * déclarés ici pour que ces lots les déplacent avec l'option « from ».
 */
final class ControlSection extends AbstractSection
{
    public static function parent(): string
    {
        return Control::class;
    }

    public static function after(): string
    {
        return ImpactAnalysis::class;
    }

    public static function title(): string
    {
        return __('Security controls (SoA)', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'The security measures of Annex A and the Statement of Applicability: which measures apply, what '
                . 'blocks them and whether they work.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['control_test', 'soa_approval'];
    }

    public static function pages(): array
    {
        return [
            // Issue #873 : cadrage guidé de la SoA par questions (applicabilité et justifications).
            SoaScoping::class,
            // Issue #867 : mesures bloquées (ce qui empêche chaque mesure d'être conforme).
            ControlBlocker::class,
            ControlTest::class,
            // Issue #778 : sécurité physique des sites (A.7.1 à A.7.14), comparaison entre sites.
            PhysicalSecurity::class,
            // Issue #777 : développement sécurisé (registre des applications, mises en production).
            SecureDevelopment::class,
            // Issue #858 : sécurité du code lue dans GitHub et GitLab (A.8.28, A.8.29).
            CodeSecurity::class,
            // Issue #540 : registre d'informations DORA (référentiel DORA activé).
            DoraRegister::class,
        ];
    }
}
