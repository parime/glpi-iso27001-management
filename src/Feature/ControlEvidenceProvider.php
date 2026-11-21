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
 * Fonctionnalité qui ajoute un cadre de preuve sur la fiche d'une mesure de la SoA
 * (control_form.html.twig, après les cadres existants).
 */
interface ControlEvidenceProvider
{
    /** Titre du cadre (traduit). */
    public static function controlEvidenceTitle(): string;

    /** Gabarit du cadre, par exemple « @grcmanager/_xxx_evidence.html.twig » ; il reçoit « evidence ». */
    public static function controlEvidenceTemplate(): string;

    /**
     * Données du cadre pour la mesure de ce code (A.8.5...), null si la mesure n'est pas concernée
     * ou si la personne ne peut pas les lire.
     *
     * @return array<string, mixed>|null
     */
    public static function controlEvidence(string $code): ?array;
}
