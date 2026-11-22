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

/** Issue #1053 : réglementations européennes ; NIS2 avec ses déclinaisons nationales (ReCyF, ACN). */
final class FrameworksEuropeBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 30;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_europe.html.twig';
    }

    public static function label(): string
    {
        return __('European regulations', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-building-bank';
    }

    public static function members(): array
    {
        return [
            [
                'group'    => 'nis2',
                'parent'   => FrameworkSelection::NIS2,
                'children' => [FrameworkSelection::RECYF, FrameworkSelection::ACN_IT],
            ],
            FrameworkSelection::DORA,
            FrameworkSelection::AI_ACT,
        ];
    }

    public static function groupTexts(): array
    {
        return ['nis2' => [
            'title'          => 'NIS2',
            'hint'           => __(
                'European directive (EU) 2022/2555: the common obligations. A national implementation adds its own '
                    . 'measures, register and score, shown under NIS2; it is active only while NIS2 is.',
                'grcmanager'
            ),
            'children_label' => __('National implementation', 'grcmanager'),
            'labels'         => [
                FrameworkSelection::RECYF  => __('France (ReCyF)', 'grcmanager'),
                FrameworkSelection::ACN_IT => __('Italy (ACN)', 'grcmanager'),
            ],
        ]];
    }
}
