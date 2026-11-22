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

use GlpiPlugin\Grcmanager\Config\FrameworkFamilyBlock;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/** Issue #1053 : guides nationaux ; un seul bloc ANSSI (hygiène, MonServiceSécurisé, ReCyF). */
final class FrameworksNationalBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_national.html.twig';
    }

    public static function label(): string
    {
        return __('National guides', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-map-pin';
    }

    public static function members(): array
    {
        return [
            [
                'group'    => 'anssi',
                'children' => [FrameworkSelection::ANSSI, FrameworkSelection::MSS, FrameworkSelection::RECYF],
            ],
        ];
    }

    public static function groupTexts(): array
    {
        return ['anssi' => [
            'title'  => 'ANSSI',
            'hint'   => __(
                'Complementary publications of the French cybersecurity agency, each with its own measures and score. '
                    . 'ReCyF is also the French implementation of NIS2: ticking it here or under NIS2 is the same setting.',
                'grcmanager'
            ),
            'labels' => [
                FrameworkSelection::ANSSI => __('IT hygiene guide', 'grcmanager'),
                FrameworkSelection::MSS   => 'MonServiceSécurisé',
                FrameworkSelection::RECYF => 'ReCyF',
            ],
        ]];
    }
}
