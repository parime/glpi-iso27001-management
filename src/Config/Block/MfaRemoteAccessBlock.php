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
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Config\Tab\PeopleTab;
use GlpiPlugin\Grcmanager\MfaCoverage;
use Group;
use Session;
use User;

/**
 * Issue #1043 : groupes GLPI dont les membres ont un accès distant (VPN, bureau à distance), déplacés
 * de front/mfacoverage.php. Ils valent pour tous les fournisseurs d'identité (import CSV ou Microsoft
 * Graph), d'où l'onglet « Personnes et politiques » plutôt que le bloc d'un connecteur. Droits
 * d'origine : lecture du plugin et des utilisateurs pour voir, modification du plugin pour enregistrer.
 */
final class MfaRemoteAccessBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-mfa-remote-config';

    public static function tab(): string
    {
        return 'people';
    }

    public static function order(): int
    {
        return 25;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_mfa_remote_access.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return MfaCoverage::canView();
    }

    public static function data(): array
    {
        $groups = MfaCoverage::getRemoteGroups();

        return [
            'mfa_remote' => [
                'groups'  => $groups,
                'names'   => array_map(
                    static fn (int $id): string => (string) Dropdown::getDropdownName(Group::getTable(), $id),
                    $groups
                ),
                'mfa_url' => MfaCoverage::getSearchURL(),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_mfa_remote'])) {
            return false;
        }
        Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
        Session::checkRight(User::$rightname, READ);
        $before = MfaCoverage::getRemoteGroups();
        $groups = $_POST['mfa_remote_groups'] ?? [];
        $after = MfaCoverage::setRemoteGroups(is_array($groups) ? $groups : []);
        if ($after !== $before) {
            AuditLog::record(
                'config_change',
                'Config',
                0,
                0,
                'success',
                ['changes' => [MfaCoverage::REMOTE_GROUPS_KEY => $after]]
            );
        }
        Session::addMessageAfterRedirect(htmlescape(__('Remote access groups saved.', 'grcmanager')));
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/mfacoverage.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_mfa_remote'])) {
            Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
            MovedSettings::notice(PeopleTab::label());
            self::handle();
        }
    }
}
