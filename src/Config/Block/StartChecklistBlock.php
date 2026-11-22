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
use GlpiPlugin\Grcmanager\Config\StartChecklist;
use GlpiPlugin\Grcmanager\Install\Installer;
use Session;

/**
 * Issue #1046 : liste de démarrage de l'administrateur, en tête de l'onglet Général. Lecture
 * seule : aucun formulaire, chaque étape mène à son réglage.
 */
final class StartChecklistBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'general';
    }

    public static function order(): int
    {
        return 5;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_start_checklist.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-start-checklist'];
    }

    /** Réservée à qui peut modifier la configuration du plugin : c'est sa liste de mise en route. */
    public static function canView(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, UPDATE);
    }

    public static function data(): array
    {
        return ['start_checklist' => StartChecklist::build()];
    }
}
