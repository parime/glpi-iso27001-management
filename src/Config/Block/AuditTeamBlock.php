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

use Dropdown;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use Group;

/**
 * Issue #140 : groupe RSSI des audits internes. Issue #1054 : le groupe se choisit dans le bloc
 * « Rôles du SMSI » (onglet Personnes et politiques) ; ce bloc rappelle le groupe et y mène.
 */
final class AuditTeamBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'controls';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_audit_team.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-audit-config'];
    }

    public static function data(): array
    {
        $groupsId = AuditTeam::getCisoGroupId();

        return [
            'ciso_groups_id'  => $groupsId,
            'ciso_group_name' => $groupsId > 0 ? (string) Dropdown::getDropdownName(Group::getTable(), $groupsId) : '',
            'isms_roles_url'  => ConfigTabs::anchorUrl(IsmsRolesBlock::ANCHOR),
        ];
    }
}
