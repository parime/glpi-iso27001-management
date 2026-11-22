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
use GlpiPlugin\Grcmanager\Config\Tab\GeneralTab;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\SupplierEvaluationLink;
use Session;

/**
 * Issue #865, lot 2 : lien sécurisé de réponse du fournisseur. Issue #1040 : le bloc enregistre
 * lui-même le réglage (il passait par front/supplierevaluationlink.php, page d'exploitation) ;
 * l'ancien envoi de cette page reste accepté pendant une version (handleLegacyPost()).
 */
final class SupplierPortalBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-supplier-portal-config';

    public static function tab(): string
    {
        return 'general';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_supplier_portal.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        return [
            'supplier_portal' => SupplierEvaluationLink::configData(),
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_portal_config'])) {
            return false;
        }
        // Réglage global (toutes les entités) qui ouvre une page publique : droit de configuration de
        // GLPI exigé en plus du droit du plugin, qu'un gestionnaire limité à une entité peut avoir.
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        Session::checkRight('config', UPDATE);
        SupplierEvaluationLink::setEnabled(!empty($_POST['supplier_portal_enabled']));
        Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/supplierevaluationlink.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['update_portal_config'])) {
            Session::checkRight(Installer::RIGHT_NAME, UPDATE);
            Session::checkRight('config', UPDATE);
            MovedSettings::notice(GeneralTab::label());
            self::handle();
        }
    }
}
