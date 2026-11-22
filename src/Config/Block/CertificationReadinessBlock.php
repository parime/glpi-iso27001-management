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

use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use Html;
use Session;

/**
 * Issue #1045 : réglages de la préparation à la certification (issue #761), venus de
 * front/certificationreadiness.php. Lecture et enregistrement avec les droits d'origine (lire,
 * puis modifier le plugin). Les réglages ne changent que les échéances conseillées et l'alerte.
 */
final class CertificationReadinessBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-readiness-settings';

    public static function tab(): string
    {
        return 'controls';
    }

    public static function order(): int
    {
        return 50;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_certification_readiness.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return CertificationReadiness::canView();
    }

    public static function data(): array
    {
        return [
            'readiness'          => CertificationReadiness::settingsData(),
            'readiness_can_edit' => CertificationReadiness::canUpdate(),
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_readiness_settings'])) {
            return false;
        }
        Session::checkRight(CertificationReadiness::RIGHTNAME, UPDATE);
        CertificationReadiness::saveSettings($_POST);
        Session::addMessageAfterRedirect(__('Certification readiness settings saved.', 'grcmanager'));
        Html::back(); // never : redirige vers l'onglet
    }
}
