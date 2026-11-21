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
 * Issue #865, lot 2 : aide de la page de relecture des réponses reçues par lien sécurisé.
 */
final class SupplierPortalFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'supplierevaluationlink' => [
                'purpose'   => __(
                    'Shows the answers sent by the supplier through the secure link, question by question, next '
                        . 'to the current answers, with the documents received. They are not part of the evaluation yet.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Check the answers, then save them (the documents are checked again like any evidence) or '
                        . 'discard them. Then review the evaluation and mark it as completed.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.19 to A.5.22', 'grcmanager'),
            ],
        ];
    }
}
