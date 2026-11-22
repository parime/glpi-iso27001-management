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
use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\Install\Installer;
use Html;
use Session;

/**
 * Issue #1083 : réglages des plans de continuité et de reprise : périodicité de revue par défaut,
 * rappel avant la date de revue, modèle proposé pour un nouveau plan. Lecture avec la lecture de la
 * configuration du plugin, enregistrement avec le droit de la modifier.
 */
final class ContinuityPlanBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-continuity-plan-settings';

    public static function tab(): string
    {
        return 'incidents';
    }

    public static function order(): int
    {
        return 60;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_continuity_plan.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        return ['continuity_plan' => ContinuityPlan::settings()];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_continuity_plan_settings'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        ContinuityPlan::saveSettings($_POST);
        Session::addMessageAfterRedirect(htmlescape(__(
            'Settings of the continuity and recovery plans saved.',
            'grcmanager'
        )));
        Html::back(); // never : redirige vers l'onglet
    }
}
