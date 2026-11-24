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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\IsmsRoles;
use Session;

/**
 * Issue #1054 : rôles du SMSI, RSSI et DPO (groupe GLPI et adresse générique de chacun). Remplace
 * le contact du DPO en texte libre (#375) et le choix du groupe RSSI du bloc des audits internes.
 */
final class IsmsRolesBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-isms-roles-config';

    public static function tab(): string
    {
        return 'people';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_isms_roles.html.twig';
    }

    public static function anchors(): array
    {
        // Ancien bloc « Délégué à la protection des données » (#375).
        return [self::ANCHOR, 'grc-dpo-config'];
    }

    public static function data(): array
    {
        return [
            'isms_roles' => IsmsRoles::summary(),
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_isms_roles'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);

        $result = IsmsRoles::save($_POST);
        if ($result['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $result['changes']]);
        }
        foreach ($result['invalid'] as $label) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%s: the generic e-mail address is not valid, it was not saved.', 'grcmanager'),
                $label
            )), false, ERROR);
        }
        if ($result['invalid'] === []) {
            Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }
}
