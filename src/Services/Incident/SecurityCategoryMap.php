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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #372 : correspondance entre la classification des incidents de sécurité de ce plugin
 * (SecurityIncidentRules::ALLOWED_CATEGORIES) et les catégories « Sécurité SI » du plugin
 * Configuration GLPI Auto, repérées par leurs clés stables (SecurityCategoryLink).
 *
 * Dans le sens incident vers catégorie, chaque classification a une catégorie (la branche quand
 * aucune sous-catégorie ne correspond). Dans l'autre sens, seule une sous-catégorie sans
 * ambiguïté donne une classification : « Compte compromis / Fuite de données » peut être un accès
 * non autorisé comme une fuite, et l'hameçonnage n'est pas une classification de ce plugin.
 */
final class SecurityCategoryMap
{
    /** Classification de l'incident => clé de la catégorie « Sécurité SI ». */
    private const CATEGORY_FOR_CLASSIFICATION = [
        'malware'             => 'security_malware',
        'data_breach'         => 'security_account',
        'unauthorized_access' => 'security_account',
        'availability'        => 'security',
        'other'               => 'security',
    ];

    /** Clé de la catégorie « Sécurité SI » => classification, quand elle est sans ambiguïté. */
    private const CLASSIFICATION_FOR_CATEGORY = [
        'security_malware'  => 'malware',
        'security_endpoint' => 'malware',
    ];

    public static function categoryKey(string $classification): string
    {
        return self::CATEGORY_FOR_CLASSIFICATION[$classification] ?? 'security';
    }

    public static function classification(string $categoryKey): ?string
    {
        return self::CLASSIFICATION_FOR_CATEGORY[$categoryKey] ?? null;
    }
}
