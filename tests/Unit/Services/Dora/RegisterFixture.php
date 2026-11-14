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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Dora;

/**
 * Issue #540 : registre fictif complet (une banque, deux prestataires TIC dont un sous-traitant,
 * deux fonctions dont une critique, deux accords). Aucune entreprise réelle : LEI au préfixe 9999,
 * non attribué.
 */
final class RegisterFixture
{
    public const BANK_LEI = '9999000EXEMPLEBANQ61';
    public const CLOUD_LEI = '9999000NUAGEDEMO0132';
    public const HOSTING_LEI = '9999000HEBERGFICT174';

    /**
     * @return array<string, mixed>
     */
    public static function register(): array
    {
        return [
            'today'        => '2026-11-14',
            'entity'       => [
                'lei' => self::BANK_LEI, 'name' => 'Banque Exemple SA', 'country' => 'FR', 'type' => 1,
                'authority' => 'Autorité fictive', 'currency' => 'EUR', 'total_assets' => 1500000000.0,
                'hierarchy' => 4, 'parent_lei' => '', 'integration_date' => '2026-01-15',
                'last_update' => '2026-11-10', 'reporting_date' => '2026-11-14',
            ],
            'providers'    => [
                10 => [
                    'code' => self::CLOUD_LEI, 'code_type' => 'LEI', 'additional_code' => '',
                    'additional_code_type' => '', 'legal_name' => 'Nuage Démo SAS', 'latin_name' => '',
                    'person_type' => 1, 'hq_country' => 'FR', 'currency' => 'EUR', 'annual_cost' => null,
                    'parent_code' => '', 'parent_code_type' => '',
                ],
                11 => [
                    'code' => self::HOSTING_LEI, 'code_type' => 'LEI', 'additional_code' => 'FR12345678901',
                    'additional_code_type' => 'VAT', 'legal_name' => 'Hébergement Fictif SARL',
                    'latin_name' => 'Hebergement Fictif SARL', 'person_type' => 1, 'hq_country' => 'DE',
                    'currency' => 'EUR', 'annual_cost' => 5000.0, 'parent_code' => '', 'parent_code_type' => '',
                ],
            ],
            'functions'    => [
                5 => [
                    'identifier' => 'F5', 'name' => 'Paiements clients', 'licensed_activity' => 'Payment services',
                    'criticality' => 1, 'reasons' => '=Interruption bloquante', 'assessment_date' => '2026-06-30',
                    'rto' => 4, 'rpo' => 1, 'impact' => 3,
                ],
                6 => [
                    'identifier' => 'F6', 'name' => 'Paie', 'licensed_activity' => 'support functions',
                    'criticality' => 2, 'reasons' => '', 'assessment_date' => '2026-06-30',
                    'rto' => 72, 'rpo' => 24, 'impact' => 1,
                ],
            ],
            'arrangements' => [
                [
                    'key' => 100, 'reference' => 'CTR-2026-01', 'type' => 1, 'overarching' => '', 'currency' => 'EUR',
                    'annual_cost' => 120000.5, 'provider' => 10, 'service_types' => ['S17', 'S19'],
                    'start_date' => '2026-01-01', 'end_date' => '2029-01-01', 'termination_reason' => 0,
                    'notice_entity' => 90, 'notice_provider' => 180, 'governing_law' => 'FR',
                    'provision_country' => 'FR', 'data_storage' => 1, 'storage_countries' => ['FR', 'DE'],
                    'processing_countries' => ['FR'], 'sensitivity' => 3, 'reliance' => 4, 'substitutability' => 2,
                    'non_substitutable_reason' => 2, 'last_audit_date' => null, 'exit_plan' => 1,
                    'reintegration' => 3, 'discontinuing_impact' => 3, 'alternatives' => 1,
                    'alternative_providers' => 'Autre nuage fictif', 'functions' => [5],
                    'subcontractors' => [['provider' => 11, 'rank' => 2, 'recipient' => 0]],
                ],
                [
                    'key' => 101, 'reference' => 'CTR-2026-02', 'type' => 1, 'overarching' => '', 'currency' => 'EUR',
                    'annual_cost' => 8000.0, 'provider' => 11, 'service_types' => ['S03'],
                    'start_date' => '2025-01-01', 'end_date' => '9999-12-31', 'termination_reason' => 0,
                    'notice_entity' => null, 'notice_provider' => null, 'governing_law' => '',
                    'provision_country' => '', 'data_storage' => 0, 'storage_countries' => [],
                    'processing_countries' => [], 'sensitivity' => 0, 'reliance' => 0, 'substitutability' => 0,
                    'non_substitutable_reason' => 0, 'last_audit_date' => null, 'exit_plan' => 0,
                    'reintegration' => 0, 'discontinuing_impact' => 0, 'alternatives' => 0,
                    'alternative_providers' => '', 'functions' => [6], 'subcontractors' => [],
                ],
            ],
        ];
    }
}
