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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

/**
 * Issue #1083 : modèle d'un nouveau plan (réglable : Configuration > GRC Manager > Incidents et
 * continuité). Des trames courtes, écrites avec nos mots, qui disent quoi écrire dans chaque partie ;
 * l'utilisateur les remplace. Les plans informatiques (PCI, PRI) parlent de systèmes et de données,
 * les plans métier (PCA, PRA) d'activités et de personnes.
 */
final class ContinuityPlanDefaults
{
    /**
     * Textes proposés pour un nouveau plan de ce type, par champ.
     *
     * @return array<string, string>
     */
    public static function texts(string $type): array
    {
        $it = ContinuityPlanRules::isItPlan($type);
        $recovery = in_array($type, [ContinuityPlanRules::TYPE_PRA, ContinuityPlanRules::TYPE_PRI], true);

        return [
            'scope'                => $it
                ? __('Systems, applications and data covered; hosting sites; entities served.', 'grcmanager')
                : __('Activities, teams and sites covered; entities concerned.', 'grcmanager'),
            'activation_criteria'  => $recovery
                ? __(
                    'Loss of a site, a system or a supplier expected to last longer than the RTO of a '
                        . 'covered process.',
                    'grcmanager'
                )
                : __(
                    'Disruption that threatens a critical activity: unavailability, cyberattack, loss of '
                        . 'staff or premises.',
                    'grcmanager'
                ),
            'activation_authority' => __(
                'Crisis manager (and deputy), after an assessment with the CISO and the IT department.',
                'grcmanager'
            ),
            'procedures'           => $it
                ? __(
                    "1. Assess the incident and activate the plan.\n2. Isolate the affected systems.\n3. "
                        . "Restore in the recovery order, from verified backups.\n4. Check integrity and security "
                        . "before reopening.\n5. Return to normal and hold the lessons learned review.",
                    'grcmanager'
                )
                : __(
                    "1. Activate the plan and gather the crisis team.\n2. Switch the critical activities to "
                        . "degraded mode.\n3. Inform staff, customers and partners.\n4. Follow the recovery and "
                        . "check the objectives.\n5. Return to normal and hold the lessons learned review.",
                    'grcmanager'
                ),
            'fallback'             => $it
                ? __('Backup site or cloud environment, spare equipment, degraded mode for users.', 'grcmanager')
                : __('Alternative premises, remote work, manual procedures, temporary staff.', 'grcmanager'),
            'communication'        => __(
                'Who informs whom, through which channel, with which crisis message template.',
                'grcmanager'
            ),
        ];
    }
}
