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

/**
 * Issue #618 : aide de la page du journal des décisions déléguées.
 */
final class DelegationFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'delegation' => [
                'purpose'   => __(
                    'Lists the decisions taken by a substitute on behalf of an absent person: who decided, for whom, '
                        . 'when, on what, and the absence period declared in GLPI at that moment.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Check that each delegated decision fits the absence and the delegable decision types. The '
                        . 'substitute gets no right: he only sees the decisions entrusted to the absent person, and '
                        . 'only until the end of the declared absence.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 5.3 and control A.5.3', 'grcmanager'),
            ],
        ];
    }
}
