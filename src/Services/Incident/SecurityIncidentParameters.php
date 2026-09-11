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

namespace GlpiPlugin\Grcmanager\Services\Incident;

use Glpi\ContentTemplates\Parameters\CommonITILObjectParameters;
use PluginGrcmanagerSecurityIncident;

/**
 * Content-template parameters for PluginGrcmanagerSecurityIncident items — same minimal shape as
 * GLPI core's own `ChangeParameters`, required by
 * `PluginGrcmanagerSecurityIncident::getContentTemplatesParametersClassInstance()`. Ported from the
 * absorbed glpi-security-incidents plugin (ROADMAP.md "Version 2.0").
 */
class SecurityIncidentParameters extends CommonITILObjectParameters
{
    public static function getDefaultNodeName(): string
    {
        return 'securityincident';
    }

    public static function getObjectLabel(): string
    {
        return PluginGrcmanagerSecurityIncident::getTypeName(1);
    }

    protected function getTargetClasses(): array
    {
        return [PluginGrcmanagerSecurityIncident::class];
    }
}
