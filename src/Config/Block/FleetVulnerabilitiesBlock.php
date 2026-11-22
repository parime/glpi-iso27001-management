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

/** Enrichissement NVD, tickets (issue #160), délais de correction (issue #601) et rapport (issue #485) des vulnérabilités du parc. */
final class FleetVulnerabilitiesBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'assets';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_fleet_vulnerabilities.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-nvd-config', 'grc-remediation-settings'];
    }

    public static function data(): array
    {
        return [
            'nvd_config'           => \GlpiPlugin\Grcmanager\Services\Cve\NvdConfig::load(),
            'park_thresholds'      => \GlpiPlugin\Grcmanager\ParkVulnerability::getTicketThresholds(),
            'digest'               => \GlpiPlugin\Grcmanager\VulnerabilityDigest::configData(),
            'remediation_settings' => \GlpiPlugin\Grcmanager\ParkRemediation::getSettings(),
            'remediation_levels'   => \GlpiPlugin\Grcmanager\ParkRemediation::getLevelLabels(),
            'remediation_context'  => \GlpiPlugin\Grcmanager\VulnerabilityContext::isEnabled(),
            'max_tickets'          => \GlpiPlugin\Grcmanager\ParkVulnerability::MAX_TICKETS_PER_RUN,
        ];
    }
}
