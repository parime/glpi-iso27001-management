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

namespace GlpiPlugin\Grcmanager\Config\Block;

use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Services\Incident\CraReportingConfig;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;

/** Module des incidents de sécurité, groupe sécurité (issue #371) et signalement CRA (issue #600). */
final class SecurityIncidentsBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'incidents';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_security_incidents.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-securityincident-config'];
    }

    public static function data(): array
    {
        $rand = mt_rand();

        return [
            'securityincident_modules' => SecurityIncidentModuleConfig::load(),
            'cra_reporting_enabled'    => CraReportingConfig::isEnabled(),
            'security_group_rand'      => $rand,
            'security_group_dropdown'  => \Group::dropdown([
                'name'        => 'security_groups_id',
                'value'       => \GlpiPlugin\Grcmanager\IncidentSecurityGroup::groupId(),
                'entity'      => 0,
                'entity_sons' => true,
                'rand'        => $rand,
                'display'     => false,
                'width'       => '100%',
            ]),
        ];
    }
}
