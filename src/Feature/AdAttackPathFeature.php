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

namespace GlpiPlugin\Grcmanager\Feature;

use GlpiPlugin\Grcmanager\AdAttackPath;
use GlpiPlugin\Grcmanager\AdSecurityAudit;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #835, lot 3 : chemins d'attaque de l'Active Directory (BloodHound CE, Adalanche). Source de
 * KRI et cadre de preuve des mesures A.5.15, A.5.18 et A.8.2, déclarés ici (voir FeatureRegistry).
 */
final class AdAttackPathFeature implements RiskIndicatorSourcesProvider, ControlEvidenceProvider
{
    public static function riskIndicatorSources(): array
    {
        return [
            // Plus grand nombre de comptes ayant un chemin vers Domain Admins (dernier import par domaine).
            'ad_attack_paths' => [
                'label'     => __('Accounts with an attack path to Domain Admins', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => [AdSecurityAudit::RIGHTNAME => READ],
                'global'    => false,
                'compute'   => [AdAttackPath::class, 'worstPathCount'],
                'after'     => 'ad_security_score',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Attack paths to Domain Admins', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_ad_attack_path_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return AdAttackPath::evidence($code);
    }
}
