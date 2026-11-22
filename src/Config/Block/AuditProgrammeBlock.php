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

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Training;
use Html;
use Session;

/**
 * Issue #1045 : réglages du programme d'audit interne (issue #773), venus de
 * front/auditprogramme.php : cycle de certification par défaut et formations qui qualifient un
 * auditeur interne. Lecture avec le droit de lire les audits, enregistrement avec celui de les
 * modifier (contrôles d'origine).
 */
final class AuditProgrammeBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-audit-programme-settings';

    public static function tab(): string
    {
        return 'controls';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_audit_programme.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return AuditProgramme::canView();
    }

    public static function data(): array
    {
        $trainings = Training::getMainTrainings();
        unset($trainings[0]);

        return [
            'programme_start_choices' => AuditProgramme::startChoices(),
            'programme_start'         => AuditProgramme::cycleStart(),
            'programme_trainings'     => $trainings,
            'programme_qualifying'    => AuditProgramme::qualifyingTrainingIds(),
            'programme_can_edit'      => AuditProgramme::canUpdate(),
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_audit_programme'])) {
            return false;
        }
        Session::checkRight(Audit::$rightname, UPDATE);
        AuditProgramme::saveSettings($_POST);
        Session::addMessageAfterRedirect(__('Audit programme settings saved.', 'grcmanager'));
        Html::back(); // never : redirige vers l'onglet
    }
}
