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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\DoraArrangement;
use GlpiPlugin\Grcmanager\DoraRegister;
use Html;
use Session;

/**
 * Issue #1045 : entité financière qui tient le registre d'informations DORA (issue #540), venue
 * de front/doraregister.php. Onglet Référentiels, à côté de l'activation de DORA : le bloc n'est
 * montré que si DORA est activé et que la personne lit le plugin ; enregistrement avec le droit
 * de le modifier (contrôles d'origine de front/doraregister.form.php).
 */
final class DoraRegisterBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-dora-settings';

    public static function tab(): string
    {
        return 'frameworks';
    }

    public static function order(): int
    {
        return 35;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_dora_register.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return DoraRegister::isAvailable();
    }

    public static function data(): array
    {
        return DoraRegister::settingsData();
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_dora_entity'])) {
            return false;
        }
        Session::checkRight(DoraArrangement::RIGHTNAME, UPDATE);
        if (!DoraRegister::canEdit()) {
            throw new AccessDeniedHttpException();
        }
        DoraRegister::saveSettings($_POST);
        Session::addMessageAfterRedirect(__('The DORA register has been updated.', 'grcmanager'));
        Html::back(); // never : redirige vers l'onglet
    }
}
