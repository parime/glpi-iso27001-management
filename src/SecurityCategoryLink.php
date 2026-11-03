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

namespace GlpiPlugin\Grcmanager;

use Config;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityCategoryMap;
use ITILCategory;
use Plugin;

/**
 * Issue #372 : catégories de tickets « Sécurité SI » installées par le plugin Configuration GLPI
 * Auto, réutilisées pour les incidents de sécurité au lieu d'en créer d'autres, sans dépendre de
 * son code.
 *
 * Ce plugin enregistre l'identifiant de la branche et de chaque sous-catégorie dans la
 * configuration de GLPI (contexte `plugin:configurationglpiauto`, clés `<clé>_itilcategories_id`,
 * par exemple `security_malware_itilcategories_id`). On les lit seulement s'il est actif ; sans
 * lui, rien ne change et ce plugin ne crée aucune catégorie. Aucun droit n'est élargi : une
 * catégorie n'est proposée que si elle est utilisable dans l'entité du ticket.
 */
final class SecurityCategoryLink
{
    public const CGA_CONTEXT = 'plugin:configurationglpiauto';

    public const KEYS = [
        'security', 'security_endpoint', 'security_phishing', 'security_malware', 'security_account',
        'security_data_breach', 'security_certificates', 'security_removable', 'security_vulnerabilities',
    ];

    /** Pour les tests : force la présence de Configuration GLPI Auto (null = détection réelle). */
    public static ?bool $forceCgaActive = null;

    /**
     * Identifiants des catégories « Sécurité SI » encore existantes, par clé ; vide sans CGA.
     *
     * @return array<string, int>
     */
    public static function ids(): array
    {
        if (!(self::$forceCgaActive ?? Plugin::isPluginActive('configurationglpiauto'))) {
            return [];
        }
        $names = array_map(static fn (string $key): string => $key . '_itilcategories_id', self::KEYS);
        $values = Config::getConfigurationValues(self::CGA_CONTEXT, $names);
        $ids = [];
        foreach (self::KEYS as $key) {
            $id = (int) ($values[$key . '_itilcategories_id'] ?? 0);
            if ($id > 0 && (new ITILCategory())->getFromDB($id)) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    /**
     * Catégorie du ticket ouvert depuis un incident : celle de l'incident, sinon celle de CGA qui
     * correspond à sa classification ; 0 si aucune n'est utilisable dans l'entité.
     */
    public static function forIncident(int $itilcategoriesId, string $classification, int $entitiesId): int
    {
        if ($itilcategoriesId > 0 && self::usableIn($itilcategoriesId, $entitiesId)) {
            return $itilcategoriesId;
        }
        $ids = self::ids();
        // Sous-catégorie de la classification, sinon la branche.
        foreach ([SecurityCategoryMap::categoryKey($classification), 'security'] as $key) {
            if (isset($ids[$key]) && self::usableIn($ids[$key], $entitiesId)) {
                return $ids[$key];
            }
        }

        return 0;
    }

    /**
     * Classification déduite de la catégorie d'un ticket qualifié en incident, null si cette
     * catégorie n'est pas une sous-catégorie « Sécurité SI » sans ambiguïté.
     */
    public static function classificationFor(int $itilcategoriesId): ?string
    {
        if ($itilcategoriesId <= 0) {
            return null;
        }
        $ids = self::ids();
        $key = array_search($itilcategoriesId, $ids, true);
        // Avant la scission (Configuration GLPI Auto 1.16), « security_account » désignait
        // « Compte compromis / Fuite de données » : accès ou fuite, ambigu.
        if ($key === 'security_account' && !isset($ids['security_data_breach'])) {
            return null;
        }

        return is_string($key) ? SecurityCategoryMap::classification($key) : null;
    }

    /**
     * Catégorie d'incident visible dans l'entité : la sienne, ou une entité parente si elle est
     * récursive.
     */
    private static function usableIn(int $itilcategoriesId, int $entitiesId): bool
    {
        $category = new ITILCategory();
        if (!$category->getFromDB($itilcategoriesId) || (int) $category->fields['is_incident'] !== 1) {
            return false;
        }
        $categoryEntity = (int) $category->fields['entities_id'];
        if ($categoryEntity === $entitiesId) {
            return true;
        }

        return (int) $category->fields['is_recursive'] === 1
            && in_array($categoryEntity, array_map('intval', getAncestorsOf('glpi_entities', $entitiesId)), true);
    }
}
