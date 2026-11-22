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

/** Issue #603 : libellés de l'échelle de maturité par mesure. */
final class MaturityScaleBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'frameworks';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_maturity_scale.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-maturity-scale-config'];
    }

    public static function data(): array
    {
        return [
            'maturity_scale_custom'     => \GlpiPlugin\Grcmanager\MaturityCampaign::customScaleLabels(),
            'maturity_scale_defaults'   => \GlpiPlugin\Grcmanager\MaturityCampaign::defaultScaleLabels(),
            'maturity_label_max_length' => \GlpiPlugin\Grcmanager\Services\Maturity\MaturityGapRules::LABEL_MAX_LENGTH,
            'maturity_campaigns_url'    => \GlpiPlugin\Grcmanager\MaturityCampaign::getSearchURL(),
        ];
    }
}
