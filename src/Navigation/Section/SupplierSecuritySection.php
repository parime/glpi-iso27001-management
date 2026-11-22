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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate;
use GlpiPlugin\Grcmanager\SupplierRisk;

/**
 * Issue #1040 : « Gestion > Sécurité des fournisseurs », juste après « Fournisseurs ». L'évaluation
 * des tiers va là où sont les fournisseurs et les contrats : le registre des risques fournisseurs
 * (venu de la rubrique Risques), la bibliothèque des questionnaires (autrefois sans entrée de menu)
 * et le registre d'informations DORA (venu des mesures, option « from », montré seulement si le
 * référentiel DORA est activé). Page d'accueil à cartes (front/section.php?section=suppliersecurity).
 *
 * Le lien sécurisé et le fichier de réponses du fournisseur sont des onglets de chaque évaluation :
 * leurs pages (front/supplierevaluationlink.php, front/supplierevaluationfile.php) portent le fil
 * d'Ariane de la rubrique sans être des sous-pages (elles n'ont de sens que pour une évaluation).
 * La fiche Fournisseur de GLPI a un onglet « Sécurité » (SupplierSecurityTab).
 */
final class SupplierSecuritySection extends AbstractSection
{
    public static function sector(): string
    {
        return 'management';
    }

    public static function parent(): string
    {
        return SupplierRisk::class;
    }

    public static function after(): string
    {
        return \Supplier::class;
    }

    public static function title(): string
    {
        return __('Supplier security', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Assess the security of your suppliers and service providers: the supplier risks, the security '
                . 'questionnaires they answer and, when DORA applies, the register of information.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['supplier_review'];
    }

    public static function pages(): array
    {
        return [
            // Issue #864 : modèles de questionnaire fournisseur (sécurité, données personnelles, IA...).
            SupplierQuestionnaireTemplate::class,
            // Issue #540 : registre d'informations DORA (référentiel DORA activé).
            DoraRegister::class => ['from' => Control::class],
        ];
    }
}
