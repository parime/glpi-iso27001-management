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
 * Fonctionnalité qui ajoute des sources automatiques aux indicateurs de risque (KRI).
 */
interface RiskIndicatorSourcesProvider
{
    /**
     * Sources, par clé enregistrée en base : libellé traduit, unité (« % » ou vide), sens
     * (KriRules::HIGHER_IS_WORSE ou LOWER_IS_WORSE), droits natifs nécessaires pour la choisir,
     * global (true si la valeur ne dépend pas de l'entité), compute (callable qui reçoit la liste
     * des entités du périmètre et rend la valeur, null si non mesurable ; appelé aussi par la tâche
     * planifiée, sans session). Clé facultative « after » : source que celle-ci suit dans la liste
     * de choix, sinon elle va à la fin.
     *
     * @return array<string, array{label: string, unit: string, direction: string,
     *     rights: array<string, int>, global: bool, compute: callable(list<int>): ?float, after?: string}>
     */
    public static function riskIndicatorSources(): array;
}
