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
use GlpiPlugin\Grcmanager\PrivilegedVault;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultConfig;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules;
use Session;
use User;

/**
 * Issue #859 : délai de renouvellement attendu des secrets du coffre-fort (comptes partagés, mots de
 * passe des équipements). Une règle de la politique d'authentification, valable pour toutes les
 * sources (Bitwarden, Vaultwarden, solution PAM), d'où l'onglet « Personnes et politiques ».
 */
final class PrivilegedVaultPolicyBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-vault-rotation-config';

    public static function tab(): string
    {
        return 'people';
    }

    public static function order(): int
    {
        return 27;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_privileged_vault_policy.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return PrivilegedVault::canView();
    }

    public static function data(): array
    {
        return [
            'vault_policy' => [
                'months' => PrivilegedVaultConfig::rotationMonths(),
                'min'    => PrivilegedVaultRules::MIN_ROTATION_MONTHS,
                'max'    => PrivilegedVaultRules::MAX_ROTATION_MONTHS,
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_vault_rotation'])) {
            return false;
        }
        Session::checkRight(PrivilegedVault::RIGHTNAME, UPDATE);
        Session::checkRight(User::$rightname, READ);
        $before = PrivilegedVaultConfig::rotationMonths();
        $after = PrivilegedVaultConfig::saveRotationMonths($_POST['vault_rotation_months'] ?? '');
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => [PrivilegedVaultConfig::ROTATION_KEY => $after],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }
}
