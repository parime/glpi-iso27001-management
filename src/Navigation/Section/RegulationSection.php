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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\AcnRequirement;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\RecyfRequirement;
use GlpiPlugin\Grcmanager\Referentials;

/**
 * Issue #1038 : les référentiels autres que l'ISO 27001 (correspondances, évaluation, référentiels
 * importés) et les registres des déclinaisons nationales de NIS2 (ReCyF, ACN), seulement si leur
 * référentiel est activé : ils n'étaient plus joignables que depuis l'évaluation (#1046).
 */
final class RegulationSection extends AbstractSection
{
    /**
     * Registres sans entrée de menu propre : clés de menu de leur page front/. Absents du menu tant que
     * leur référentiel est désactivé (canView() de RecyfRequirement et AcnRequirement le vérifie).
     */
    public const RECYF = 'recyfregister';
    public const ACN = 'acnregister';

    public static function parent(): string
    {
        return Referentials::class;
    }

    public static function after(): string
    {
        return Nonconformity::class;
    }

    public static function title(): string
    {
        return __('Regulations', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'The laws and standards that apply besides ISO 27001 (NIS2 and its national versions, DORA, other '
                . 'frameworks) and how far you comply with them.',
            'grcmanager'
        );
    }

    public static function pages(): array
    {
        return [
            FrameworkAssessment::class,
            CustomFramework::class,
            self::RECYF => [
                'title'     => RecyfRequirement::getTypeName(2),
                'page'      => '/plugins/grcmanager/front/recyfrequirement.php',
                'icon'      => 'ti ti-flag',
                'rights_of' => RecyfRequirement::class,
            ],
            self::ACN => [
                'title'     => AcnRequirement::getTypeName(2),
                'page'      => '/plugins/grcmanager/front/acnrequirement.php',
                'icon'      => 'ti ti-flag',
                'rights_of' => AcnRequirement::class,
            ],
        ];
    }
}
