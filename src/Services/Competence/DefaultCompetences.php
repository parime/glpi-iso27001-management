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

namespace GlpiPlugin\Grcmanager\Services\Competence;

/**
 * Issue #774 : fonctions du SMSI et compétences proposées par défaut (bouton de la page des
 * compétences, jamais à l'installation). Elles sont enregistrées avec leur clé et leur nom dans la
 * langue de la personne qui les ajoute, pour rester lisibles dans les listes de GLPI. Un ajout ne
 * reprend que ce qui manque (clé déjà présente dans les entités visibles) et ne modifie jamais
 * l'existant.
 */
final class DefaultCompetences
{
    /** @var list<string> */
    public const COMPETENCES = [
        'awareness', 'isms_governance', 'risk_management', 'incident_response', 'access_management',
        'secure_administration', 'secure_development', 'data_protection', 'business_continuity',
        'internal_audit',
    ];

    /**
     * Fonction => compétence => [niveau exigé, façon de l'acquérir].
     *
     * @var array<string, array<string, array{0: int, 1: string}>>
     */
    public const FUNCTIONS = [
        'ciso' => [
            'isms_governance'   => [3, 'certification'],
            'risk_management'   => [3, 'training'],
            'incident_response' => [2, 'training'],
            'internal_audit'    => [2, 'training'],
            'awareness'         => [3, 'experience'],
        ],
        'dpo' => [
            'data_protection' => [3, 'certification'],
            'risk_management' => [2, 'training'],
            'awareness'       => [2, 'training'],
        ],
        'top_management' => [
            'isms_governance' => [1, 'training'],
            'risk_management' => [1, 'training'],
            'awareness'       => [1, 'training'],
        ],
        'sysadmin' => [
            'secure_administration' => [3, 'experience'],
            'access_management'     => [2, 'training'],
            'incident_response'     => [2, 'training'],
            'business_continuity'   => [2, 'training'],
            'awareness'             => [2, 'training'],
        ],
        'developer' => [
            'secure_development' => [2, 'training'],
            'awareness'          => [1, 'training'],
        ],
        'technician' => [
            'access_management' => [1, 'training'],
            'incident_response' => [1, 'training'],
            'awareness'         => [2, 'training'],
        ],
        'all_staff' => [
            'awareness' => [1, 'training'],
        ],
    ];

    public static function competenceExists(string $key): bool
    {
        return in_array($key, self::COMPETENCES, true);
    }

    public static function functionExists(string $key): bool
    {
        return isset(self::FUNCTIONS[$key]);
    }
}
