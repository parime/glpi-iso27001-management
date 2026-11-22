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
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig;
use Session;

/** Issue #618 : décisions délégables à un suppléant pendant une absence. */
final class DelegationBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'people';
    }

    public static function order(): int
    {
        return 70;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_delegation.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-delegation-config'];
    }

    public static function data(): array
    {
        return [
            'delegation' => Delegation::configData(),
        ];
    }

    /**
     * Issue #1047 : types délégables enregistrés par la page de configuration (front/config.php),
     * plus par la page d'exploitation front/delegation.php. Réglage global : droit de modifier le
     * plugin ET la configuration de GLPI.
     */
    public static function handle(): bool
    {
        if (!isset($_POST['update_delegation_config'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        Session::checkRight('config', UPDATE);
        DelegationConfig::save((array) ($_POST['delegation_types'] ?? []));
        Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
        // Html::redirect() termine la requête : méthode séparée, pour garder « return true » (GLPI 11 et 12).
        MovedSettings::redirect('grc-delegation-config');

        return true;
    }
}
