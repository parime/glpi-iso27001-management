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

use GlpiPlugin\Grcmanager\HardwareModelEol;

/**
 * Issue #1039 : Parc > Sécurité du parc. Aide de la page d'accueil et de la saisie groupée de la fin
 * de vie constructeur, ancien lien du bloc de la configuration redirigé vers cette saisie.
 */
final class AssetSecurityFeature implements PageHelpProvider, MovedConfigAnchorsProvider
{
    public static function pageHelp(): array
    {
        return [
            'assetsecurity'    => [
                'purpose'  => __(
                    'Everything about the security of your assets in one place: vulnerabilities, end of life, '
                        . 'patches, hardening, monitoring, backups, sites and development. Each card opens a '
                        . 'screen; only the screens your profile can open are shown.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Start with the vulnerabilities and the end of life of software and hardware, then check '
                        . 'that the expected protections (encryption, patches, logs, backups) cover the whole fleet.',
                    'grcmanager'
                ),
            ],
            'hardwaremodeleol' => [
                'purpose'   => __(
                    'Some manufacturers announce the date after which a model no longer receives repairs, spare '
                        . 'parts or firmware updates. Enter it here for all the models in service, or on the form '
                        . 'of each model (tab "Manufacturer end of life").',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enter the known dates, then follow the hardware whose end of life is reached or near in the '
                        . '"Hardware warranty" card of the fleet vulnerabilities. Nothing is changed in the GLPI '
                        . 'models.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.7.13 and A.8.9', 'grcmanager'),
            ],
        ];
    }

    public static function movedConfigAnchors(): array
    {
        return [HardwareModelEol::OLD_ANCHOR => HardwareModelEol::getSearchURL(false)];
    }
}
