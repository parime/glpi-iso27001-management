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

namespace GlpiPlugin\Grcmanager\Feature;

use GlpiPlugin\Grcmanager\ProductSbom;

/**
 * Issue #869 : aide de l'onglet « SBOM et VEX » des applications et preuves des mesures A.5.21,
 * A.8.8 et A.8.28.
 */
final class ProductSbomFeature implements ControlEvidenceProvider, PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'productsbom' => [
                'purpose'   => __(
                    'Keeps the software bill of materials (SBOM) of each version of a product you build or '
                        . 'develop, compares its components with the vulnerabilities already known to the plugin, '
                        . 'and records your VEX statements: affected, not affected (with the reason), fixed or under '
                        . 'investigation. Customers and the Cyber Resilience Act expect these documents from a '
                        . 'manufacturer.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Import the CycloneDX or SPDX file (JSON) produced by your build for each released version, '
                        . 'then give a VEX status to each vulnerability found, or import the VEX document of your '
                        . 'scanner. Nothing is sent outside GLPI.',
                    'grcmanager'
                ),
                'reference' => __(
                    'ISO/IEC 27001, controls A.5.21, A.8.8 and A.8.28; Cyber Resilience Act, Annex I part II',
                    'grcmanager'
                ),
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Software bills of materials and VEX', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_product_sbom_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ProductSbom::evidence($code);
    }
}
