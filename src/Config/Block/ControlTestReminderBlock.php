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

/** Issue #380 : rappel des tests d'efficacité. */
final class ControlTestReminderBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'controls';
    }

    public static function order(): int
    {
        return 20;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_controltest_reminder.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-controltest-reminder'];
    }

    public static function data(): array
    {
        return [
            'controltest_ciso_mode'    => \GlpiPlugin\Grcmanager\ControlTestReminder::cisoMode(),
            'controltest_ciso_modes'   => \GlpiPlugin\Grcmanager\ControlTestReminder::getCisoModes(),
            'controltest_brand_color'  => \GlpiPlugin\Grcmanager\ControlTestReminder::brandColor(),
            'controltest_cga_branding' => \GlpiPlugin\Grcmanager\ControlTestReminder::isCgaActive(),
            'ciso_groups_id'           => \GlpiPlugin\Grcmanager\AuditTeam::getCisoGroupId(),
        ];
    }
}
