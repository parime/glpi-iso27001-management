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
 * Issue #865 : aide de la page d'aperçu d'un fichier de réponses du fournisseur.
 */
final class SupplierAnswerFileFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'supplierevaluationfile' => [
                'purpose'   => __(
                    'Shows the answers found in the questionnaire file returned by the supplier, question by '
                        . 'question, next to the current answers, with the points to check (unknown question, '
                        . 'answer outside the list, missing question).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Check the answers, add the documents sent by the supplier, then save: nothing is recorded '
                        . 'before this confirmation. Then review the evaluation and mark it as completed.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.19 to A.5.22', 'grcmanager'),
            ],
        ];
    }
}
