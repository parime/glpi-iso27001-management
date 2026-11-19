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

namespace GlpiPlugin\Grcmanager\Services\Responsibility;

/**
 * Issue #612 : activités du SMSI proposées par défaut dans la matrice des responsabilités.
 *
 * Une activité par défaut est enregistrée avec sa clé et un nom vide : son libellé est traduit à
 * l'affichage, dans la langue de chaque personne. Dès que quelqu'un lui donne un nom, ce nom est
 * conservé tel quel ; une mise à jour du plugin n'ajoute que les activités qui n'ont jamais été
 * proposées (création de la table) et ne touche jamais aux activités existantes.
 */
final class DefaultActivities
{
    /**
     * Clé => mesures de l'Annexe A concernées et activité sensible (séparation des tâches).
     *
     * @var array<string, array{codes: list<string>, sensitive: bool}>
     */
    public const CATALOG = [
        'risk_assessment'          => ['codes' => [], 'sensitive' => false],
        'risk_acceptance'          => ['codes' => [], 'sensitive' => true],
        'policy_approval'          => ['codes' => ['A.5.1'], 'sensitive' => false],
        'asset_inventory'          => ['codes' => ['A.5.9'], 'sensitive' => false],
        'access_rights'            => ['codes' => ['A.5.15', 'A.5.18'], 'sensitive' => true],
        'access_review'            => ['codes' => ['A.5.18'], 'sensitive' => true],
        'privileged_access'        => ['codes' => ['A.8.2'], 'sensitive' => true],
        'change_management'        => ['codes' => ['A.8.32'], 'sensitive' => true],
        'backups'                  => ['codes' => ['A.8.13'], 'sensitive' => false],
        'log_review'               => ['codes' => ['A.8.15', 'A.8.16'], 'sensitive' => true],
        'vulnerability_management' => ['codes' => ['A.8.8'], 'sensitive' => false],
        'incident_management'      => ['codes' => ['A.5.24', 'A.5.26'], 'sensitive' => false],
        'supplier_security'        => ['codes' => ['A.5.19', 'A.5.20'], 'sensitive' => false],
        'awareness_training'       => ['codes' => ['A.6.3'], 'sensitive' => false],
        'business_continuity'      => ['codes' => ['A.5.29', 'A.5.30'], 'sensitive' => false],
        'internal_audit'           => ['codes' => [], 'sensitive' => true],
        'management_review'        => ['codes' => [], 'sensitive' => false],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }
}
