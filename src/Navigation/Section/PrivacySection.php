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

use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\DataSubjectRequest;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Referentials;

/**
 * Issue #1038 : la vie privée (RGPD), rubrique du DPO : registre des traitements, demandes
 * d'exercice des droits et violations de données (droit dédié plugin_grcmanager_gdpr).
 */
final class PrivacySection extends AbstractSection
{
    public static function parent(): string
    {
        return ProcessingActivity::class;
    }

    public static function after(): string
    {
        return Referentials::class;
    }

    public static function title(): string
    {
        return __('Privacy (GDPR)', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Personal data: the record of processing activities, the requests from the people concerned and the '
                . 'data breaches.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['data_subject_request'];
    }

    public static function pages(): array
    {
        return [
            DataSubjectRequest::class,
            // Issue #620 : registre des violations de données personnelles.
            DataBreach::class,
        ];
    }
}
