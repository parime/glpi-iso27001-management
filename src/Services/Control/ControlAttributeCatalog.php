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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #610 : attributs des 93 mesures de l'Annexe A (type de mesure, propriétés de sécurité,
 * concepts de cybersécurité, capacités opérationnelles, domaines de sécurité), tels que l'ISO/IEC
 * 27002:2022 les associe à chaque mesure. Seuls les identifiants des mesures et les valeurs des
 * attributs figurent ici, jamais le texte de la norme ; les libellés traduits sont dans
 * \GlpiPlugin\Grcmanager\Control::getAttributeValues().
 *
 * PHP pur, sans dépendance à GLPI : semé à l'installation et complété à chaque mise à jour
 * (Installer::seedControlAttributes()), sans toucher aux valeurs qu'un administrateur a modifiées
 * ni aux statuts de la SoA. Chaque attribut est stocké dans sa colonne de
 * glpi_plugin_grcmanager_controls, ses valeurs séparées par des virgules.
 */
final class ControlAttributeCatalog
{
    public const CONTROL_TYPES = 'control_types';
    public const SECURITY_PROPERTIES = 'security_properties';
    public const CYBERSECURITY_CONCEPTS = 'cybersecurity_concepts';
    public const OPERATIONAL_CAPABILITIES = 'operational_capabilities';
    public const SECURITY_DOMAINS = 'security_domains';

    /**
     * Attribut (nom de colonne) => valeurs possibles, dans l'ordre d'affichage. Aucune valeur n'est
     * contenue dans une autre du même attribut : le filtre de la recherche cherche la valeur dans
     * la liste stockée (vérifié par le test unitaire).
     *
     * @var array<string, list<string>>
     */
    public const VALUES = [
        self::CONTROL_TYPES => ['preventive', 'detective', 'corrective'],
        self::SECURITY_PROPERTIES => ['confidentiality', 'integrity', 'availability'],
        self::CYBERSECURITY_CONCEPTS => ['identify', 'protect', 'detect', 'respond', 'recover'],
        self::OPERATIONAL_CAPABILITIES => [
            'governance', 'asset_management', 'information_protection', 'human_resource_security',
            'physical_security', 'system_network_security', 'application_security',
            'secure_configuration', 'identity_access_management', 'threat_vulnerability_management',
            'continuity', 'supplier_relationships_security', 'legal_compliance', 'event_management',
            'assurance',
        ],
        self::SECURITY_DOMAINS => ['governance_ecosystem', 'protection', 'defence', 'resilience'],
    ];

    private const CIA = 'confidentiality,integrity,availability';

    /**
     * Code de la mesure => valeurs des cinq attributs, dans l'ordre de VALUES.
     *
     * @var array<string, array{string, string, string, string, string}>
     */
    public const CONTROLS = [
        // Mesures organisationnelles (A.5)
        'A.5.1'  => ['preventive', self::CIA, 'identify', 'governance', 'governance_ecosystem,resilience'],
        'A.5.2'  => ['preventive', self::CIA, 'identify', 'governance', 'governance_ecosystem,protection,resilience'],
        'A.5.3'  => [
            'preventive', self::CIA, 'protect',
            'governance,identity_access_management', 'governance_ecosystem',
        ],
        'A.5.4'  => ['preventive', self::CIA, 'identify', 'governance', 'governance_ecosystem'],
        'A.5.5'  => [
            'preventive,corrective', self::CIA, 'identify,protect,respond,recover', 'governance',
            'defence,resilience',
        ],
        'A.5.6'  => ['preventive,corrective', self::CIA, 'protect,respond,recover', 'governance', 'defence'],
        'A.5.7'  => [
            'preventive,detective,corrective', self::CIA, 'identify,detect,respond',
            'threat_vulnerability_management', 'defence,resilience',
        ],
        'A.5.8'  => ['preventive', self::CIA, 'identify,protect', 'governance', 'governance_ecosystem,protection'],
        'A.5.9'  => ['preventive', self::CIA, 'identify', 'asset_management', 'governance_ecosystem,protection'],
        'A.5.10' => [
            'preventive', self::CIA, 'protect', 'asset_management,information_protection',
            'governance_ecosystem,protection',
        ],
        'A.5.11' => ['preventive', self::CIA, 'protect', 'asset_management', 'protection'],
        'A.5.12' => ['preventive', self::CIA, 'identify', 'information_protection', 'protection,defence'],
        'A.5.13' => ['preventive', self::CIA, 'protect', 'information_protection', 'protection,defence'],
        'A.5.14' => ['preventive', self::CIA, 'protect', 'asset_management,information_protection', 'protection'],
        'A.5.15' => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.5.16' => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.5.17' => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.5.18' => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.5.19' => [
            'preventive', self::CIA, 'identify', 'supplier_relationships_security',
            'governance_ecosystem,protection',
        ],
        'A.5.20' => [
            'preventive', self::CIA, 'identify', 'supplier_relationships_security',
            'governance_ecosystem,protection',
        ],
        'A.5.21' => [
            'preventive', self::CIA, 'identify', 'supplier_relationships_security',
            'governance_ecosystem,protection',
        ],
        'A.5.22' => [
            'preventive', self::CIA, 'identify', 'supplier_relationships_security,assurance',
            'governance_ecosystem,protection,defence',
        ],
        'A.5.23' => [
            'preventive', self::CIA, 'protect', 'supplier_relationships_security',
            'governance_ecosystem,protection',
        ],
        'A.5.24' => ['corrective', self::CIA, 'respond,recover', 'governance,event_management', 'defence'],
        'A.5.25' => ['detective', self::CIA, 'detect,respond', 'event_management', 'defence'],
        'A.5.26' => ['corrective', self::CIA, 'respond,recover', 'event_management', 'defence'],
        'A.5.27' => ['preventive', self::CIA, 'identify,protect', 'event_management', 'defence'],
        'A.5.28' => ['corrective', self::CIA, 'detect,respond', 'event_management', 'defence'],
        'A.5.29' => ['preventive,corrective', self::CIA, 'protect,respond', 'continuity', 'protection,resilience'],
        'A.5.30' => ['corrective', 'availability', 'respond', 'continuity', 'resilience'],
        'A.5.31' => ['preventive', self::CIA, 'identify', 'legal_compliance', 'governance_ecosystem,protection'],
        'A.5.32' => ['preventive', self::CIA, 'identify', 'legal_compliance', 'governance_ecosystem'],
        'A.5.33' => [
            'preventive', self::CIA, 'identify,protect',
            'legal_compliance,asset_management,information_protection', 'defence',
        ],
        'A.5.34' => [
            'preventive', self::CIA, 'identify,protect',
            'information_protection,legal_compliance', 'protection',
        ],
        'A.5.35' => ['preventive,corrective', self::CIA, 'identify,protect', 'assurance', 'governance_ecosystem'],
        'A.5.36' => ['preventive', self::CIA, 'identify,protect', 'legal_compliance,assurance', 'governance_ecosystem'],
        'A.5.37' => [
            'preventive,corrective', self::CIA, 'protect,recover',
            'asset_management,physical_security,system_network_security,application_security,'
            . 'secure_configuration,identity_access_management,threat_vulnerability_management,continuity,'
            . 'event_management',
            'governance_ecosystem,protection,defence',
        ],

        // Mesures liées aux personnes (A.6)
        'A.6.1' => ['preventive', self::CIA, 'protect', 'human_resource_security', 'governance_ecosystem'],
        'A.6.2' => ['preventive', self::CIA, 'protect', 'human_resource_security', 'governance_ecosystem'],
        'A.6.3' => ['preventive', self::CIA, 'protect', 'human_resource_security', 'governance_ecosystem'],
        'A.6.4' => [
            'preventive,corrective', self::CIA, 'protect,respond',
            'human_resource_security', 'governance_ecosystem',
        ],
        'A.6.5' => [
            'preventive', self::CIA, 'protect', 'human_resource_security,asset_management',
            'governance_ecosystem',
        ],
        'A.6.6' => [
            'preventive', 'confidentiality', 'protect',
            'human_resource_security,information_protection,supplier_relationships_security',
            'governance_ecosystem',
        ],
        'A.6.7' => [
            'preventive', self::CIA, 'protect',
            'asset_management,information_protection,physical_security,system_network_security',
            'protection',
        ],
        'A.6.8' => ['detective', self::CIA, 'detect', 'event_management', 'defence'],

        // Mesures physiques (A.7)
        'A.7.1'  => ['preventive', self::CIA, 'protect', 'physical_security', 'protection'],
        'A.7.2'  => ['preventive', self::CIA, 'protect', 'physical_security,identity_access_management', 'protection'],
        'A.7.3'  => ['preventive', self::CIA, 'protect', 'physical_security,asset_management', 'protection'],
        'A.7.4'  => ['preventive,detective', self::CIA, 'protect,detect', 'physical_security', 'protection,defence'],
        'A.7.5'  => ['preventive', self::CIA, 'protect', 'physical_security', 'protection'],
        'A.7.6'  => ['preventive', self::CIA, 'protect', 'physical_security', 'protection'],
        'A.7.7'  => ['preventive', 'confidentiality', 'protect', 'physical_security', 'protection'],
        'A.7.8'  => ['preventive', self::CIA, 'protect', 'physical_security,asset_management', 'protection'],
        'A.7.9'  => ['preventive', self::CIA, 'protect', 'physical_security,asset_management', 'protection'],
        'A.7.10' => ['preventive', self::CIA, 'protect', 'physical_security,asset_management', 'protection'],
        'A.7.11' => [
            'preventive,detective', 'integrity,availability', 'protect,detect',
            'physical_security', 'protection',
        ],
        'A.7.12' => ['preventive', 'confidentiality,availability', 'protect', 'physical_security', 'protection'],
        'A.7.13' => [
            'preventive', self::CIA, 'protect', 'physical_security,asset_management',
            'protection,resilience',
        ],
        'A.7.14' => ['preventive', 'confidentiality', 'protect', 'physical_security,asset_management', 'protection'],

        // Mesures technologiques (A.8)
        'A.8.1'  => ['preventive', self::CIA, 'protect', 'asset_management,information_protection', 'protection'],
        'A.8.2'  => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.8.3'  => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.8.4'  => [
            'preventive', self::CIA, 'protect',
            'identity_access_management,application_security,secure_configuration', 'protection',
        ],
        'A.8.5'  => ['preventive', self::CIA, 'protect', 'identity_access_management', 'protection'],
        'A.8.6'  => [
            'preventive,detective', 'integrity,availability', 'identify,protect,detect', 'continuity',
            'governance_ecosystem,protection',
        ],
        'A.8.7'  => [
            'preventive,detective,corrective', self::CIA, 'protect,detect',
            'system_network_security,information_protection', 'protection,defence',
        ],
        'A.8.8'  => [
            'preventive', self::CIA, 'identify,protect', 'threat_vulnerability_management',
            'governance_ecosystem,protection,defence',
        ],
        'A.8.9'  => ['preventive', self::CIA, 'protect', 'secure_configuration', 'protection'],
        'A.8.10' => [
            'preventive', 'confidentiality', 'protect',
            'information_protection,legal_compliance', 'protection',
        ],
        'A.8.11' => ['preventive', 'confidentiality', 'protect', 'information_protection', 'protection'],
        'A.8.12' => [
            'preventive,detective', 'confidentiality', 'protect,detect',
            'information_protection', 'protection,defence',
        ],
        'A.8.13' => ['corrective', 'integrity,availability', 'recover', 'continuity', 'protection'],
        'A.8.14' => ['preventive', 'availability', 'protect', 'continuity,asset_management', 'protection,resilience'],
        'A.8.15' => ['detective', self::CIA, 'detect', 'event_management', 'protection,defence'],
        'A.8.16' => ['detective,corrective', self::CIA, 'detect,respond', 'event_management', 'defence'],
        'A.8.17' => ['detective', 'integrity', 'protect,detect', 'event_management', 'protection,defence'],
        'A.8.18' => [
            'preventive', self::CIA, 'protect',
            'system_network_security,secure_configuration,application_security', 'protection',
        ],
        'A.8.19' => ['preventive', self::CIA, 'protect', 'secure_configuration,application_security', 'protection'],
        'A.8.20' => ['preventive,detective', self::CIA, 'protect,detect', 'system_network_security', 'protection'],
        'A.8.21' => ['preventive', self::CIA, 'protect', 'system_network_security', 'protection'],
        'A.8.22' => ['preventive', self::CIA, 'protect', 'system_network_security', 'protection'],
        'A.8.23' => ['preventive', self::CIA, 'protect', 'system_network_security', 'protection'],
        'A.8.24' => ['preventive', self::CIA, 'protect', 'secure_configuration', 'protection'],
        'A.8.25' => ['preventive', self::CIA, 'protect', 'application_security,system_network_security', 'protection'],
        'A.8.26' => [
            'preventive', self::CIA, 'protect', 'application_security,system_network_security',
            'protection,defence',
        ],
        'A.8.27' => ['preventive', self::CIA, 'protect', 'application_security,system_network_security', 'protection'],
        'A.8.28' => ['preventive', self::CIA, 'protect', 'application_security,system_network_security', 'protection'],
        'A.8.29' => [
            'preventive', self::CIA, 'identify', 'application_security,assurance,system_network_security',
            'protection',
        ],
        'A.8.30' => [
            'preventive,detective', self::CIA, 'identify,protect,detect',
            'system_network_security,application_security,supplier_relationships_security',
            'governance_ecosystem,protection',
        ],
        'A.8.31' => ['preventive', self::CIA, 'protect', 'application_security,system_network_security', 'protection'],
        'A.8.32' => ['preventive', self::CIA, 'protect', 'application_security,system_network_security', 'protection'],
        'A.8.33' => ['preventive', 'confidentiality,integrity', 'protect', 'information_protection', 'protection'],
        'A.8.34' => [
            'preventive', self::CIA, 'protect', 'system_network_security,information_protection',
            'governance_ecosystem,protection',
        ],
    ];

    /**
     * @return list<string> les cinq attributs (noms de colonnes), dans l'ordre d'affichage
     */
    public static function attributes(): array
    {
        return array_keys(self::VALUES);
    }

    public static function isAttribute(string $attribute): bool
    {
        return isset(self::VALUES[$attribute]);
    }

    /**
     * Valeurs livrées pour une mesure, attribut => liste stockée ; tableau vide pour un code inconnu.
     *
     * @return array<string, string>
     */
    public static function defaultsFor(string $code): array
    {
        $values = self::CONTROLS[$code] ?? null;
        if ($values === null) {
            return [];
        }

        return array_combine(self::attributes(), $values);
    }

    /**
     * Liste stockée => valeurs, sans valeur inconnue ni doublon, dans l'ordre de VALUES.
     *
     * @return list<string>
     */
    public static function decode(string $attribute, ?string $stored): array
    {
        $chosen = array_map('trim', explode(',', (string) $stored));

        return array_values(array_intersect(self::VALUES[$attribute] ?? [], $chosen));
    }

    /**
     * Valeurs saisies (liste ou chaîne) => liste stockée, valeurs inconnues écartées.
     *
     * @param list<string>|string|null $values
     */
    public static function encode(string $attribute, array|string|null $values): string
    {
        if (!is_array($values)) {
            $values = explode(',', (string) $values);
        }

        return implode(',', self::decode($attribute, implode(',', array_map('strval', $values))));
    }

    /**
     * La liste stockée contient-elle la valeur ?
     */
    public static function has(string $attribute, ?string $stored, string $value): bool
    {
        return in_array($value, self::decode($attribute, $stored), true);
    }
}
