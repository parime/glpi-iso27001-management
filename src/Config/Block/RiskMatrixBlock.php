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

/** Matrice des risques, taille (issue #706) et libellés des niveaux. */
final class RiskMatrixBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'risks';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_risk_matrix.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-risk-matrix-config'];
    }

    public static function data(): array
    {
        return [
            'risk_matrix'                => \GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig::load(),
            'risk_probabilities'         => \GlpiPlugin\Grcmanager\Risk::getProbabilities(),
            'risk_impacts'               => \GlpiPlugin\Grcmanager\Risk::getImpacts(),
            'risk_levels'                => \GlpiPlugin\Grcmanager\Risk::getRiskLevels(),
            'risk_scale_size'            => \GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig::size(),
            'risk_scale_sizes'           => \GlpiPlugin\Grcmanager\RiskScaleChange::sizeLabels(),
            'risk_scale_url'             => \GlpiPlugin\Grcmanager\RiskScaleChange::getPageURL(),
            'risk_scale_labels'          => \GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig::labels(),
            'risk_default_probabilities' => \GlpiPlugin\Grcmanager\Risk::getDefaultProbabilityLabels(),
            'risk_default_impacts'       => \GlpiPlugin\Grcmanager\Risk::getDefaultImpactLabels(),
            'risk_label_max_length'      => \GlpiPlugin\Grcmanager\Services\Risk\RiskScale::LABEL_MAX_LENGTH,
        ];
    }
}
