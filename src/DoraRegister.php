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

use BusinessCriticity;
use CommonDBTM;
use CommonGLPI;
use Config;
use Contract;
use Contract_Supplier;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Continuity\BusinessImpactRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterBuilder;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterCompleteness;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterCsv;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterRules;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use Session;
use Supplier;

/**
 * Issue #540 : registre d'informations DORA des prestataires de services TIC (article 28,
 * paragraphe 3, du règlement (UE) 2022/2554 ; modèles du règlement d'exécution (UE) 2024/2956).
 *
 * Construit depuis les objets de GLPI, sans les dupliquer : fournisseurs (prestataires TIC et
 * sous-traitants), contrats (accords contractuels : numéro, dates, durée, préavis, coûts),
 * processus métier du plugin et leur BIA (fonctions soutenues, RTO, RPO, impact d'un arrêt). Les
 * seuls champs propres à DORA sont saisis dans un onglet « Registre DORA » du fournisseur, du
 * contrat et du processus (DoraProvider, DoraArrangement, DoraFunction) ; l'entité qui tient le
 * registre est réglée sur la page du registre. La page montre les champs obligatoires manquants
 * et exporte un CSV par modèle.
 *
 * Visible seulement si le référentiel DORA est activé, avec le droit de lecture du plugin ;
 * modifiable avec son droit de modification. Seuls les contrats, fournisseurs et processus que la
 * personne peut lire sont repris (droits et entités de GLPI).
 */
class DoraRegister extends CommonGLPI
{
    public const CONFIG_KEY = 'dora_register';

    public const TAB_PROVIDER = 1;
    public const TAB_ARRANGEMENT = 2;
    public const TAB_FUNCTION = 3;

    public static function getTypeName($nb = 0)
    {
        return __('DORA register of information', 'grcmanager');
    }

    public static function getMenuName()
    {
        return __('DORA register', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-bank';
    }

    public static function getMenuContent()
    {
        if (!self::isAvailable()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => self::getRegisterURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function getRegisterURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/doraregister.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/doraregister.form.php';
    }

    public static function getExportURL(string $template = ''): string
    {
        return self::getRegisterURL() . '?export=' . ($template === '' ? 'zip' : rawurlencode($template));
    }

    /** Le référentiel DORA est activé et la personne lit le plugin. */
    public static function isAvailable(): bool
    {
        return FrameworkConfig::isEnabled(FrameworkSelection::DORA)
            && Session::haveRight(DoraArrangement::RIGHTNAME, READ);
    }

    public static function canEdit(): bool
    {
        return self::isAvailable() && Session::haveRight(DoraArrangement::RIGHTNAME, UPDATE);
    }

    // ------------------------------------------------------------------ Entité qui tient le registre

    /**
     * @return array{lei: string, name: string, country: string, type: int, authority: string, currency: string,
     *     total_assets: ?float, hierarchy: int, parent_lei: string, integration_date: ?string}
     */
    public static function getSettings(): array
    {
        $values = Config::getConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [self::CONFIG_KEY]);
        $stored = json_decode((string) ($values[self::CONFIG_KEY] ?? ''), true);

        return self::cleanSettings(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function saveSettings(array $input): void
    {
        Config::setConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [
            self::CONFIG_KEY => (string) json_encode(self::cleanSettings($input)),
        ]);
        self::resetCache();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{lei: string, name: string, country: string, type: int, authority: string, currency: string,
     *     total_assets: ?float, hierarchy: int, parent_lei: string, integration_date: ?string}
     */
    private static function cleanSettings(array $input): array
    {
        return [
            'lei'              => self::cleanLei($input['lei'] ?? ''),
            'name'             => mb_substr(trim((string) ($input['name'] ?? '')), 0, 255),
            'country'          => RegisterRules::country($input['country'] ?? ''),
            'type'             => RegisterRules::option($input['type'] ?? 0, RegisterTemplates::ENTITY_TYPES),
            'authority'        => mb_substr(trim((string) ($input['authority'] ?? '')), 0, 255),
            'currency'         => RegisterRules::currency($input['currency'] ?? '') ?: 'EUR',
            'total_assets'     => RegisterRules::amount($input['total_assets'] ?? null),
            'hierarchy'        => RegisterRules::option($input['hierarchy'] ?? 4, RegisterTemplates::HIERARCHIES),
            'parent_lei'       => self::cleanLei($input['parent_lei'] ?? ''),
            'integration_date' => RegisterRules::date($input['integration_date'] ?? ''),
        ];
    }

    /** LEI saisi : sans espace, en majuscules, 20 caractères au plus (vérifié par le contrôle). */
    private static function cleanLei(mixed $value): string
    {
        return strtoupper(str_replace(' ', '', mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, 20)));
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    // ------------------------------------------------------------------ Libellés

    /**
     * Options traduites d'une liste fermée, dans l'ordre du texte officiel.
     *
     * @return array<int|string, string>
     */
    public static function options(string $list): array
    {
        return match ($list) {
            'entity_type' => [
                1  => __('Credit institution', 'grcmanager'),
                2  => __('Payment institution', 'grcmanager'),
                3  => __('Account information service provider', 'grcmanager'),
                4  => __('Electronic money institution', 'grcmanager'),
                5  => __('Investment firm', 'grcmanager'),
                6  => __('Crypto-asset service provider', 'grcmanager'),
                7  => __('Issuer of asset-referenced tokens', 'grcmanager'),
                8  => __('Central securities depository', 'grcmanager'),
                9  => __('Central counterparty', 'grcmanager'),
                10 => __('Trading venue', 'grcmanager'),
                11 => __('Trade repository', 'grcmanager'),
                12 => __('Manager of alternative investment funds', 'grcmanager'),
                13 => __('Management company', 'grcmanager'),
                14 => __('Data reporting service provider', 'grcmanager'),
                15 => __('Insurance or reinsurance undertaking', 'grcmanager'),
                16 => __('Insurance or reinsurance intermediary', 'grcmanager'),
                17 => __('Institution for occupational retirement provision', 'grcmanager'),
                18 => __('Credit rating agency', 'grcmanager'),
                19 => __('Administrator of critical benchmarks', 'grcmanager'),
                20 => __('Crowdfunding service provider', 'grcmanager'),
                21 => __('Securitisation repository', 'grcmanager'),
                22 => __('Other financial entity', 'grcmanager'),
            ],
            'hierarchy' => [
                1 => __('Ultimate parent undertaking of the group', 'grcmanager'),
                2 => __('Parent undertaking of a sub-group', 'grcmanager'),
                3 => __('Subsidiary in the group', 'grcmanager'),
                4 => __('Not part of a group', 'grcmanager'),
                5 => __('Service provider to which all operational activities are outsourced', 'grcmanager'),
            ],
            'arrangement_type' => [
                1 => __('Standalone arrangement', 'grcmanager'),
                2 => __('Overarching or master arrangement', 'grcmanager'),
                3 => __('Subsequent or associated arrangement', 'grcmanager'),
            ],
            'termination_reason' => [
                1 => __('Expired and not renewed', 'grcmanager'),
                2 => __('Terminated: breach of law, regulation or contract by the provider', 'grcmanager'),
                3 => __('Terminated: impediments that could alter the supported function', 'grcmanager'),
                4 => __('Terminated: weaknesses in the security of sensitive data', 'grcmanager'),
                5 => __('Terminated at the request of a competent authority', 'grcmanager'),
                6 => __('Terminated for another reason', 'grcmanager'),
            ],
            'yes_no' => [1 => __('Yes', 'grcmanager'), 2 => __('No', 'grcmanager')],
            'sensitivity', 'impact_level' => [
                1 => __('Low', 'grcmanager'),
                2 => __('Medium', 'grcmanager'),
                3 => __('High', 'grcmanager'),
            ],
            'impact' => [
                1 => __('Low', 'grcmanager'),
                2 => __('Medium', 'grcmanager'),
                3 => __('High', 'grcmanager'),
                4 => __('Assessment not performed', 'grcmanager'),
            ],
            'reliance' => [
                1 => __('Not significant', 'grcmanager'),
                2 => __('Low reliance: quickly resolved, minimal impact', 'grcmanager'),
                3 => __('Material reliance: significant impact after a few minutes or hours', 'grcmanager'),
                4 => __('Full reliance: immediate and severe interruption', 'grcmanager'),
            ],
            'person_type' => [
                1 => __('Legal person', 'grcmanager'),
                2 => __('Individual acting in a business capacity', 'grcmanager'),
            ],
            'criticality' => [
                1 => __('Critical or important', 'grcmanager'),
                2 => __('Not critical or important', 'grcmanager'),
                3 => __('Assessment not performed', 'grcmanager'),
            ],
            'substitutability' => [
                1 => __('Not substitutable', 'grcmanager'),
                2 => __('Highly complex to substitute', 'grcmanager'),
                3 => __('Medium complexity to substitute', 'grcmanager'),
                4 => __('Easily substitutable', 'grcmanager'),
            ],
            'non_substitutable_reason' => [
                1 => __('No real alternative on the market', 'grcmanager'),
                2 => __('Difficult migration of data and workloads', 'grcmanager'),
                3 => __('Both reasons', 'grcmanager'),
            ],
            'reintegration' => [
                1 => __('Easy', 'grcmanager'),
                2 => __('Difficult', 'grcmanager'),
                3 => __('Highly complex', 'grcmanager'),
            ],
            'alternatives' => [
                1 => __('Yes', 'grcmanager'),
                2 => __('No', 'grcmanager'),
                7 => __('Assessment not performed', 'grcmanager'),
            ],
            'code_type' => [
                'LEI'  => __('LEI (legal entity identifier)', 'grcmanager'),
                'EUID' => __('EUID (European unique identifier)', 'grcmanager'),
                'CRN'  => __('Company registration number', 'grcmanager'),
                'VAT'  => __('VAT number', 'grcmanager'),
                'PNR'  => __('Passport number', 'grcmanager'),
                'NIN'  => __('National identity number', 'grcmanager'),
            ],
            'service_type' => [
                'S01' => __('ICT project management', 'grcmanager'),
                'S02' => __('ICT development', 'grcmanager'),
                'S03' => __('ICT help desk and first level support', 'grcmanager'),
                'S04' => __('ICT security management services', 'grcmanager'),
                'S05' => __('Provision of data', 'grcmanager'),
                'S06' => __('Data analysis', 'grcmanager'),
                'S07' => __('ICT, facilities and hosting services (excluding cloud)', 'grcmanager'),
                'S08' => __('Computation', 'grcmanager'),
                'S09' => __('Non-cloud data storage', 'grcmanager'),
                'S10' => __('Telecom carrier', 'grcmanager'),
                'S11' => __('Network infrastructure', 'grcmanager'),
                'S12' => __('Hardware and physical devices', 'grcmanager'),
                'S13' => __('Software licensing (excluding SaaS)', 'grcmanager'),
                'S14' => __('ICT operation management (including maintenance)', 'grcmanager'),
                'S15' => __('ICT consulting', 'grcmanager'),
                'S16' => __('ICT risk management', 'grcmanager'),
                'S17' => __('Cloud services: IaaS', 'grcmanager'),
                'S18' => __('Cloud services: PaaS', 'grcmanager'),
                'S19' => __('Cloud services: SaaS', 'grcmanager'),
            ],
            default => [],
        };
    }

    /**
     * Libellé d'un champ contrôlé (RegisterCompleteness), par portée.
     */
    public static function fieldLabel(string $scope, string $field): string
    {
        $labels = [
            'entity.lei'                           => __('LEI of the financial entity', 'grcmanager'),
            'entity.name'                          => __('Legal name of the financial entity', 'grcmanager'),
            'entity.country'                       => __('Country of the financial entity', 'grcmanager'),
            'entity.type'                          => __('Type of financial entity', 'grcmanager'),
            'entity.authority'                     => __('Competent authority', 'grcmanager'),
            'entity.hierarchy'                     => __('Position in the group', 'grcmanager'),
            'entity.parent_lei'                    => __('LEI of the direct parent undertaking', 'grcmanager'),
            'entity.total_assets'                  => __('Value of total assets', 'grcmanager'),
            'entity.currency'                      => __('Currency', 'grcmanager'),
            'arrangement.reference'                => __('Contract reference number', 'grcmanager'),
            'arrangement.type'                     => __('Type of contractual arrangement', 'grcmanager'),
            'arrangement.overarching'              => __('Reference of the overarching arrangement', 'grcmanager'),
            'arrangement.currency'                 => __('Currency', 'grcmanager'),
            'arrangement.annual_cost'              => __('Annual cost for the past year', 'grcmanager'),
            'arrangement.provider'                 => __('ICT third-party service provider', 'grcmanager'),
            'arrangement.service_types'            => __('Type of ICT services', 'grcmanager'),
            'arrangement.functions'                => __('Supported functions', 'grcmanager'),
            'arrangement.start_date'               => __('Start date of the contract', 'grcmanager'),
            'arrangement.termination_reason'       => __('Reason for the end of the contract', 'grcmanager'),
            'arrangement.notice_entity'            => __('Notice period for the financial entity', 'grcmanager'),
            'arrangement.notice_provider'          => __('Notice period for the provider', 'grcmanager'),
            'arrangement.governing_law'            => __('Country of the governing law', 'grcmanager'),
            'arrangement.provision_country'        => __('Country of provision of the services', 'grcmanager'),
            'arrangement.data_storage'             => __('Storage of data', 'grcmanager'),
            'arrangement.storage_countries'        => __('Location of the data at rest', 'grcmanager'),
            'arrangement.processing_countries'     => __('Location of data processing', 'grcmanager'),
            'arrangement.sensitivity'              => __('Sensitiveness of the data', 'grcmanager'),
            'arrangement.reliance'                 => __('Level of reliance on the service', 'grcmanager'),
            'arrangement.substitutability'         => __('Substitutability of the provider', 'grcmanager'),
            'arrangement.non_substitutable_reason' => __('Reason why it is hard to substitute', 'grcmanager'),
            'arrangement.exit_plan'                => __('Exit plan', 'grcmanager'),
            'arrangement.reintegration'            => __('Possibility of reintegration', 'grcmanager'),
            'arrangement.discontinuing_impact'     => __('Impact of discontinuing the services', 'grcmanager'),
            'arrangement.alternatives'             => __('Alternative providers identified', 'grcmanager'),
            'provider.code'                        => __('Identification code of the provider', 'grcmanager'),
            'provider.code_type'                   => __('Type of identification code', 'grcmanager'),
            'provider.legal_name'                  => __('Legal name of the provider', 'grcmanager'),
            'provider.person_type'                 => __('Type of person', 'grcmanager'),
            'provider.hq_country'                  => __('Country of the headquarters', 'grcmanager'),
            'provider.currency'                    => __('Currency', 'grcmanager'),
            'function.licensed_activity'           => __('Licensed activity', 'grcmanager'),
            'function.criticality'                 => __('Critical or important function', 'grcmanager'),
            'function.assessment_date'             => __('Date of the last criticality assessment', 'grcmanager'),
        ];

        return $labels[$scope . '.' . $field] ?? $field;
    }

    /**
     * Ce que contient chaque modèle, en une phrase, et son remplissage par le plugin.
     *
     * @return array<string, array{code: string, label: string, coverage: string, file: string}>
     */
    public static function templateSummaries(): array
    {
        $labels = [
            'B_01.01' => __('Entity maintaining the register', 'grcmanager'),
            'B_01.02' => __('Entities within the scope of the register', 'grcmanager'),
            'B_01.03' => __('Branches outside the home country', 'grcmanager'),
            'B_02.01' => __('Contractual arrangements: general information', 'grcmanager'),
            'B_02.02' => __('Contractual arrangements: services, functions and data locations', 'grcmanager'),
            'B_02.03' => __('Intra-group contractual arrangements', 'grcmanager'),
            'B_03.01' => __('Entities signing the arrangements', 'grcmanager'),
            'B_03.02' => __('Providers signing the arrangements', 'grcmanager'),
            'B_03.03' => __('Entities providing ICT services within the group', 'grcmanager'),
            'B_04.01' => __('Entities making use of the ICT services', 'grcmanager'),
            'B_05.01' => __('ICT third-party service providers', 'grcmanager'),
            'B_05.02' => __('ICT service supply chains (subcontracting)', 'grcmanager'),
            'B_06.01' => __('Functions supported', 'grcmanager'),
            'B_07.01' => __('Assessment of the services supporting critical functions', 'grcmanager'),
            'B_99.01' => __('Definitions used by the entity', 'grcmanager'),
        ];
        $summaries = [];
        foreach (RegisterTemplates::TEMPLATES as $code => $template) {
            $summaries[$code] = [
                'code'     => $code,
                'label'    => $labels[$code] ?? $template['name'],
                'coverage' => $template['coverage'],
                'file'     => RegisterTemplates::fileName($code),
            ];
        }

        return $summaries;
    }

    // ------------------------------------------------------------------ Lecture du registre

    /**
     * Registre lisible par la personne : données attendues par RegisterBuilder et
     * RegisterCompleteness, plus les noms et adresses des objets GLPI (meta) pour l'affichage.
     *
     * @return array{register: array<string, mixed>, meta: array<string, array<int, array<string, mixed>>>}
     */
    public static function collect(): array
    {
        return self::$collected ??= self::read();
    }

    /** Relit le registre à la prochaine demande (après une modification). */
    public static function resetCache(): void
    {
        self::$collected = null;
    }

    /** @var array{register: array<string, mixed>, meta: array<string, array<int, array<string, mixed>>>}|null */
    private static ?array $collected = null;

    /**
     * @return array{register: array<string, mixed>, meta: array<string, array<int, array<string, mixed>>>}
     */
    private static function read(): array
    {
        global $DB;

        $settings = self::getSettings();
        $today = date('Y-m-d');
        $meta = ['arrangements' => [], 'providers' => [], 'functions' => []];
        $arrangements = [];
        $supplierIds = [];
        $processIds = [];
        $lastUpdate = null;
        $firstDate = null;

        if (Contract::canView()) {
            $rows = $DB->request([
                'SELECT'     => [
                    DoraArrangement::getTable() . '.*',
                    Contract::getTable() . '.name AS contract_name',
                    Contract::getTable() . '.num AS contract_num',
                    Contract::getTable() . '.begin_date AS contract_begin',
                    Contract::getTable() . '.duration AS contract_duration',
                ],
                'FROM'       => DoraArrangement::getTable(),
                'INNER JOIN' => [
                    Contract::getTable() => [
                        'FKEY' => [Contract::getTable() => 'id', DoraArrangement::getTable() => 'contracts_id'],
                    ],
                ],
                'WHERE'      => [Contract::getTable() . '.is_deleted' => 0]
                    + getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
                'ORDER'      => Contract::getTable() . '.name',
            ]);
            foreach ($rows as $row) {
                $contractsId = (int) $row['contracts_id'];
                $contractSuppliers = self::contractSupplierIds($contractsId);
                $provider = (int) $row['suppliers_id'];
                if (!in_array($provider, $contractSuppliers, true)) {
                    $provider = count($contractSuppliers) === 1 ? $contractSuppliers[0] : 0;
                }
                $subcontractors = DoraArrangement::getSubcontractors($contractsId);
                $ranks = DoraArrangement::ranks($subcontractors);
                $functions = DoraArrangement::getFunctionIds($contractsId);
                $supplierIds = array_merge($supplierIds, [$provider], array_column($subcontractors, 'suppliers_id'));
                $processIds = array_merge($processIds, $functions);
                $begin = RegisterRules::date((string) ($row['contract_begin'] ?? ''));
                $cost = $row['annual_cost'] === null
                    ? self::contractCost($contractsId, $today)
                    : (float) $row['annual_cost'];
                $arrangements[] = [
                    'key'                      => $contractsId,
                    'reference'                => (string) $row['reference'] !== ''
                        ? (string) $row['reference'] : trim((string) $row['contract_num']),
                    'type'                     => (int) $row['arrangement_type'],
                    'overarching'              => (string) $row['overarching_reference'],
                    'currency'                 => (string) $row['currency'] !== ''
                        ? (string) $row['currency'] : $settings['currency'],
                    'annual_cost'              => $cost,
                    'provider'                 => $provider,
                    'service_types'            => RegisterRules::serviceTypes((string) $row['service_types']),
                    'start_date'               => $begin,
                    'end_date'                 => RegisterRules::endDate($begin, (int) $row['contract_duration']),
                    'termination_reason'       => (int) $row['termination_reason'],
                    'notice_entity'            => self::nullableInt($row['notice_entity_days']),
                    'notice_provider'          => self::nullableInt($row['notice_provider_days']),
                    'governing_law'            => (string) $row['governing_law_country'],
                    'provision_country'        => (string) $row['provision_country'],
                    'data_storage'             => (int) $row['data_storage'],
                    'storage_countries'        => RegisterRules::countries((string) $row['storage_countries']),
                    'processing_countries'     => RegisterRules::countries((string) $row['processing_countries']),
                    'sensitivity'              => (int) $row['data_sensitivity'],
                    'reliance'                 => (int) $row['reliance'],
                    'substitutability'         => (int) $row['substitutability'],
                    'non_substitutable_reason' => (int) $row['non_substitutable_reason'],
                    'last_audit_date'          => $row['last_audit_date'] === null
                        ? null : (string) $row['last_audit_date'],
                    'exit_plan'                => (int) $row['exit_plan'],
                    'reintegration'            => (int) $row['reintegration'],
                    'discontinuing_impact'     => (int) $row['discontinuing_impact'],
                    'alternatives'             => (int) $row['alternatives'],
                    'alternative_providers'    => (string) ($row['alternative_providers'] ?? ''),
                    'functions'                => $functions,
                    'subcontractors'           => array_map(static fn (array $sub): array => [
                        'provider'  => $sub['suppliers_id'],
                        'rank'      => $ranks[$sub['suppliers_id']] ?? 2,
                        'recipient' => $sub['recipient'],
                    ], $subcontractors),
                ];
                $meta['arrangements'][$contractsId] = [
                    'name'          => (string) $row['contract_name'],
                    'url'           => self::tabURL(Contract::getFormURLWithID($contractsId), self::TAB_ARRANGEMENT),
                    'cost_computed' => $row['annual_cost'] === null,
                ];
                $lastUpdate = max($lastUpdate ?? '', substr((string) $row['date_mod'], 0, 10));
                $created = substr((string) $row['date_creation'], 0, 10);
                $firstDate = $firstDate === null || ($created !== '' && $created < $firstDate) ? $created : $firstDate;
            }
        }

        $providers = [];
        foreach (self::readableSuppliers(array_values(array_unique(array_filter($supplierIds)))) as $supplier) {
            $id = (int) $supplier['id'];
            $providers[$id] = self::providerData($supplier, DoraProvider::forSupplier($id), $settings['currency']);
            $meta['providers'][$id] = [
                'name' => (string) $supplier['name'],
                'url'  => self::tabURL(Supplier::getFormURLWithID($id), self::TAB_PROVIDER),
            ];
        }

        $functions = [];
        foreach (self::readableProcesses(array_values(array_unique($processIds))) as $process) {
            $id = (int) $process['id'];
            $functions[$id] = self::functionData($process, DoraFunction::forProcess($id));
            $meta['functions'][$id] = [
                'name' => (string) $process['name'],
                'url'  => self::tabURL(BusinessProcess::getFormURLWithID($id), self::TAB_FUNCTION),
            ];
        }

        return [
            'register' => [
                'today'        => $today,
                'entity'       => array_merge($settings, [
                    'last_update'      => $lastUpdate ?: $today,
                    'reporting_date'   => $today,
                    'integration_date' => $settings['integration_date'] ?? ($firstDate ?: $today),
                ]),
                'providers'    => $providers,
                'functions'    => $functions,
                'arrangements' => $arrangements,
            ],
            'meta'     => $meta,
        ];
    }

    /**
     * @return list<int>
     */
    public static function contractSupplierIds(int $contractsId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['suppliers_id'],
                'FROM'   => Contract_Supplier::getTable(),
                'WHERE'  => ['contracts_id' => $contractsId],
                'ORDER'  => 'id',
            ]) as $row
        ) {
            $ids[] = (int) $row['suppliers_id'];
        }

        return $ids;
    }

    /**
     * Coût annuel du contrat sur les douze derniers mois : somme des coûts du contrat GLPI
     * (onglet Coûts) dont la période les recoupe. Null si aucun coût n'est saisi.
     */
    public static function contractCost(int $contractsId, string $today): ?float
    {
        global $DB;

        $yearAgo = date('Y-m-d', (int) strtotime($today . ' -1 year'));
        $total = null;
        foreach (
            $DB->request([
                'SELECT' => ['cost', 'begin_date', 'end_date'],
                'FROM'   => 'glpi_contractcosts',
                'WHERE'  => ['contracts_id' => $contractsId],
            ]) as $row
        ) {
            $begin = (string) ($row['begin_date'] ?? '');
            $end = (string) ($row['end_date'] ?? '');
            if (($begin !== '' && $begin > $today) || ($end !== '' && $end < $yearAgo)) {
                continue;
            }
            $total = ($total ?? 0.0) + (float) $row['cost'];
        }

        return $total === null ? null : round($total, 2);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function readableSuppliers(array $ids): array
    {
        global $DB;

        if ($ids === [] || !Supplier::canView()) {
            return [];
        }

        return array_values(iterator_to_array($DB->request([
            'FROM'  => Supplier::getTable(),
            'WHERE' => ['id' => $ids, 'is_deleted' => 0]
                + getEntitiesRestrictCriteria(Supplier::getTable(), '', '', true),
            'ORDER' => 'name',
        ]), false));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function readableProcesses(array $ids): array
    {
        global $DB;

        if ($ids === [] || !BusinessProcess::canView()) {
            return [];
        }

        return array_values(iterator_to_array($DB->request([
            'FROM'  => BusinessProcess::getTable(),
            'WHERE' => ['id' => $ids] + getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true),
            'ORDER' => 'name',
        ]), false));
    }

    /**
     * Prestataire tel que l'écrit le registre : champs DORA saisis, sinon repris du fournisseur
     * GLPI (nom, pays s'il est un code ISO, numéro d'immatriculation s'il est un LEI).
     *
     * @param array<string, mixed> $supplier ligne du fournisseur GLPI
     * @param array<string, mixed> $dora ligne de DoraProvider ([] si aucune)
     * @return array<string, mixed>
     */
    public static function providerData(array $supplier, array $dora, string $currency): array
    {
        $registration = strtoupper(str_replace(' ', '', (string) ($supplier['registration_number'] ?? '')));
        $code = (string) ($dora['code'] ?? '');
        $type = (string) ($dora['code_type'] ?? '');
        if ($code === '' && $type === '' && RegisterRules::isLei($registration)) {
            $code = $registration;
            $type = RegisterTemplates::CODE_LEI;
        }
        $parentCode = (string) ($dora['parent_code'] ?? '');

        return [
            'code'                 => $code,
            'code_type'            => RegisterRules::codeType($type, (string) ($dora['code_country'] ?? '')),
            'additional_code'      => (string) ($dora['additional_code'] ?? ''),
            'additional_code_type' => (string) ($dora['additional_code_type'] ?? ''),
            'legal_name'           => (string) ($dora['legal_name'] ?? '') !== ''
                ? (string) $dora['legal_name'] : (string) ($supplier['name'] ?? ''),
            'latin_name'           => (string) ($dora['latin_name'] ?? ''),
            'person_type'          => (int) ($dora['person_type'] ?? RegisterTemplates::LEGAL_PERSON),
            'hq_country'           => (string) ($dora['hq_country'] ?? '') !== ''
                ? (string) $dora['hq_country'] : RegisterRules::country($supplier['country'] ?? ''),
            'currency'             => (string) ($dora['currency'] ?? '') !== ''
                ? (string) $dora['currency'] : $currency,
            'annual_cost'          => isset($dora['annual_cost']) ? (float) $dora['annual_cost'] : null,
            'parent_code'          => $parentCode,
            'parent_code_type'     => $parentCode === '' ? '' : RegisterRules::codeType(
                (string) ($dora['parent_code_type'] ?? ''),
                (string) ($dora['parent_code_country'] ?? '')
            ),
        ];
    }

    /**
     * Fonction telle que l'écrit le registre : nom, RTO et RPO du processus, date de la BIA par
     * défaut, impact d'un arrêt déduit du pire impact de la BIA.
     *
     * @param array<string, mixed> $process ligne du processus métier
     * @param array<string, mixed> $dora ligne de DoraFunction ([] si aucune)
     * @return array<string, mixed>
     */
    public static function functionData(array $process, array $dora): array
    {
        $id = (int) $process['id'];
        $grid = BusinessImpactRules::grid(BusinessImpact::getRows($id));
        $worst = max([0, ...array_values(BusinessImpactRules::worstByHorizon($grid))]);
        $date = $dora['assessment_date'] ?? null;

        return [
            'identifier'        => DoraFunction::identifier($id),
            'name'              => (string) $process['name'],
            'licensed_activity' => (string) ($dora['licensed_activity'] ?? ''),
            'criticality'       => (int) ($dora['criticality'] ?? 0),
            'reasons'           => (string) ($dora['criticality_reasons'] ?? ''),
            'assessment_date'   => $date !== null && $date !== '' ? (string) $date
                : (($process['bia_date'] ?? null) !== null ? (string) $process['bia_date'] : null),
            'rto'               => (int) ($process['rto_hours'] ?? 0),
            'rpo'               => (int) ($process['rpo_hours'] ?? 0),
            'impact'            => RegisterRules::impactFromBia((int) $worst),
            'unacceptable_after' => BusinessImpactRules::firstUnacceptableHorizon($grid),
        ];
    }

    /**
     * Adresse de la fiche ouverte sur l'onglet « Registre DORA ».
     */
    private static function tabURL(string $url, int $tab): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'forcetab=' . rawurlencode(self::class . '$' . $tab);
    }

    /**
     * Anomalies du registre, avec libellé, objet concerné et lien pour les corriger.
     *
     * @param array{register: array<string, mixed>, meta: array<string, array<int, array<string, mixed>>>} $data
     * @return array{issues: list<array<string, mixed>>, checked: int, rate: int}
     */
    public static function completeness(array $data): array
    {
        $result = RegisterCompleteness::check($data['register']);
        $objects = [
            'arrangement' => $data['meta']['arrangements'],
            'provider'    => $data['meta']['providers'],
            'function'    => $data['meta']['functions'],
        ];
        $types = [
            'entity'      => __('Financial entity', 'grcmanager'),
            'arrangement' => Contract::getTypeName(1),
            'provider'    => Supplier::getTypeName(1),
            'function'    => BusinessProcess::getTypeName(1),
        ];
        $issues = [];
        foreach ($result['issues'] as $issue) {
            $object = $objects[$issue['scope']][$issue['key']] ?? null;
            $issues[] = $issue + [
                'label'       => self::fieldLabel($issue['scope'], $issue['field']),
                'object_type' => $types[$issue['scope']] ?? '',
                'object_name' => $issue['scope'] === 'entity'
                    ? (string) ($data['register']['entity']['name'] ?? '') : (string) ($object['name'] ?? ''),
                'url'         => $issue['scope'] === 'entity'
                    ? self::getRegisterURL() . '#grc-dora-settings' : (string) ($object['url'] ?? ''),
            ];
        }

        return ['issues' => $issues, 'checked' => $result['checked'], 'rate' => $result['rate']];
    }

    /**
     * Fichiers de l'export : un CSV par modèle.
     *
     * @return array<string, string>
     */
    public static function exportFiles(): array
    {
        return RegisterCsv::files(RegisterBuilder::build(self::collect()['register']));
    }

    /**
     * Contrats lisibles des fournisseurs déjà décrits comme prestataires TIC, pas encore dans le
     * registre : à examiner.
     *
     * @return list<array{name: string, supplier: string, url: string}>
     */
    public static function suggestions(): array
    {
        global $DB;

        if (!Contract::canView() || !Supplier::canView()) {
            return [];
        }
        $suggestions = [];
        $rows = $DB->request([
            'SELECT'     => [
                Contract::getTable() . '.id', Contract::getTable() . '.name',
                Supplier::getTable() . '.name AS supplier',
            ],
            'FROM'       => Contract::getTable(),
            'INNER JOIN' => [
                Contract_Supplier::getTable() => [
                    'FKEY' => [Contract_Supplier::getTable() => 'contracts_id', Contract::getTable() => 'id'],
                ],
                DoraProvider::getTable()      => [
                    'FKEY' => [
                        DoraProvider::getTable()      => 'suppliers_id',
                        Contract_Supplier::getTable() => 'suppliers_id',
                    ],
                ],
                Supplier::getTable()          => [
                    'FKEY' => [Supplier::getTable() => 'id', Contract_Supplier::getTable() => 'suppliers_id'],
                ],
            ],
            'LEFT JOIN'  => [
                DoraArrangement::getTable() => [
                    'FKEY' => [DoraArrangement::getTable() => 'contracts_id', Contract::getTable() => 'id'],
                ],
            ],
            'WHERE'      => [
                Contract::getTable() . '.is_deleted' => 0,
                DoraArrangement::getTable() . '.id'  => null,
            ] + getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
            'ORDER'      => Contract::getTable() . '.name',
            'LIMIT'      => 50,
        ]);
        foreach ($rows as $row) {
            $suggestions[] = [
                'name'     => (string) $row['name'],
                'supplier' => (string) $row['supplier'],
                'url'      => self::tabURL(Contract::getFormURLWithID((int) $row['id']), self::TAB_ARRANGEMENT),
            ];
        }

        return $suggestions;
    }

    /**
     * Un élément supprimé définitivement disparaît du registre (setup.php, Hooks::ITEM_PURGE).
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        $id = (int) $item->getID();
        if ($item instanceof Contract && $DB->tableExists(DoraArrangement::getTable())) {
            DoraArrangement::exclude($id);
            $DB->delete(DoraArrangement::FUNCTIONS_TABLE, ['contracts_id' => $id]);
            $DB->delete(DoraArrangement::SUBCONTRACTORS_TABLE, ['contracts_id' => $id]);
        }
        if ($item instanceof Supplier && $DB->tableExists(DoraProvider::getTable())) {
            $DB->delete(DoraProvider::getTable(), ['suppliers_id' => $id]);
            $DB->delete(DoraArrangement::SUBCONTRACTORS_TABLE, ['suppliers_id' => $id]);
            $DB->update(
                DoraArrangement::SUBCONTRACTORS_TABLE,
                ['suppliers_id_recipient' => 0],
                ['suppliers_id_recipient' => $id]
            );
            $DB->update(DoraArrangement::getTable(), ['suppliers_id' => 0], ['suppliers_id' => $id]);
        }
        if ($item instanceof BusinessProcess && $DB->tableExists(DoraFunction::getTable())) {
            $DB->delete(DoraFunction::getTable(), ['plugin_grcmanager_businessprocesses_id' => $id]);
            $DB->delete(DoraArrangement::FUNCTIONS_TABLE, ['plugin_grcmanager_businessprocesses_id' => $id]);
        }
    }

    // ------------------------------------------------------------------ Onglets

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !self::isAvailable()) {
            return '';
        }
        $tab = self::tabFor($item);
        if ($tab === 0) {
            return '';
        }
        $label = $tab === self::TAB_FUNCTION ? __('DORA function', 'grcmanager') : __('DORA register', 'grcmanager');
        $count = match ($tab) {
            self::TAB_ARRANGEMENT => DoraArrangement::forContract((int) $item->getID()) === [] ? 0 : 1,
            default               => 0,
        };

        return [$tab => self::createTabEntry($label, $count, null, self::getIcon())];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::isAvailable() || !$item->canViewItem()
            || self::tabFor($item) !== (int) $tabnum
        ) {
            return false;
        }
        match ((int) $tabnum) {
            self::TAB_PROVIDER    => self::showProvider($item),
            self::TAB_ARRANGEMENT => self::showArrangement($item),
            default               => self::showFunction($item),
        };

        return true;
    }

    private static function tabFor(CommonDBTM $item): int
    {
        return match (true) {
            $item instanceof Supplier        => self::TAB_PROVIDER,
            $item instanceof Contract        => self::TAB_ARRANGEMENT,
            $item instanceof BusinessProcess => self::TAB_FUNCTION,
            default                          => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function commonTemplateData(): array
    {
        return [
            'action'   => self::getFormURL(),
            'can_edit' => self::canEdit(),
            'register_url' => self::getRegisterURL(),
            'its_url'  => RegisterTemplates::ITS_URL,
        ];
    }

    /**
     * Anomalies d'un objet seulement (onglet).
     *
     * @return list<array<string, mixed>>
     */
    private static function issuesFor(string $scope, int $key): array
    {
        $completeness = self::completeness(self::collect());

        return array_values(array_filter(
            $completeness['issues'],
            static fn (array $issue): bool => $issue['scope'] === $scope && (int) $issue['key'] === $key
        ));
    }

    private static function showProvider(CommonDBTM $supplier): void
    {
        $id = (int) $supplier->getID();
        $dora = DoraProvider::forSupplier($id);
        $settings = self::getSettings();
        $data = self::providerData($supplier->fields, $dora, $settings['currency']);
        $contracts = [];
        if (Contract::canView()) {
            global $DB;
            foreach (
                $DB->request([
                    'SELECT'     => [Contract::getTable() . '.id', Contract::getTable() . '.name'],
                    'FROM'       => Contract::getTable(),
                    'INNER JOIN' => [
                        Contract_Supplier::getTable() => [
                            'FKEY' => [Contract_Supplier::getTable() => 'contracts_id', Contract::getTable() => 'id'],
                        ],
                    ],
                    'WHERE'      => [
                        Contract_Supplier::getTable() . '.suppliers_id' => $id,
                        Contract::getTable() . '.is_deleted'            => 0,
                    ] + getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
                    'ORDER'      => Contract::getTable() . '.name',
                ]) as $row
            ) {
                $contracts[] = [
                    'name'        => (string) $row['name'],
                    'url'         => self::tabURL(Contract::getFormURLWithID((int) $row['id']), self::TAB_ARRANGEMENT),
                    'in_register' => DoraArrangement::forContract((int) $row['id']) !== [],
                ];
            }
        }

        $template = '@grcmanager/dora/provider_tab.html.twig';
        TemplateRenderer::getInstance()->display($template, self::commonTemplateData() + [
            'supplier_id'   => $id,
            'dora'          => $dora,
            'data'          => $data,
            'is_new'        => $dora === [],
            'supplier_name' => (string) $supplier->fields['name'],
            'code_types'    => self::options('code_type'),
            'person_types'  => self::options('person_type'),
            'currency'      => $settings['currency'],
            'contracts'     => $contracts,
            'issues'        => self::issuesFor('provider', $id),
            'computed_cost' => self::providerComputedCost($id),
        ]);
    }

    /**
     * Somme des coûts annuels des accords dont le fournisseur est le prestataire direct.
     */
    private static function providerComputedCost(int $suppliersId): ?float
    {
        $data = self::collect()['register'];
        $total = null;
        foreach ($data['arrangements'] as $arrangement) {
            if ((int) $arrangement['provider'] === $suppliersId && $arrangement['annual_cost'] !== null) {
                $total = ($total ?? 0.0) + (float) $arrangement['annual_cost'];
            }
        }

        return $total;
    }

    private static function showArrangement(CommonDBTM $contract): void
    {
        $id = (int) $contract->getID();
        $dora = DoraArrangement::forContract($id);
        $settings = self::getSettings();
        $supplierIds = self::contractSupplierIds($id);
        $suppliers = [];
        foreach (self::readableSuppliers($supplierIds) as $row) {
            $suppliers[(int) $row['id']] = (string) $row['name'];
        }

        $vars = self::commonTemplateData() + [
            'contract_id'   => $id,
            'contract_name' => (string) $contract->fields['name'],
            'in_register'   => $dora !== [],
        ];
        if ($dora === []) {
            TemplateRenderer::getInstance()->display('@grcmanager/dora/arrangement_tab.html.twig', $vars);

            return;
        }

        $begin = RegisterRules::date((string) ($contract->fields['begin_date'] ?? ''));
        $provider = (int) $dora['suppliers_id'];
        if (!isset($suppliers[$provider])) {
            $provider = count($supplierIds) === 1 ? $supplierIds[0] : 0;
        }
        $today = date('Y-m-d');

        $functions = [];
        $functionIds = DoraArrangement::getFunctionIds($id);
        $criticalities = self::options('criticality');
        foreach (self::readableProcesses($functionIds) as $process) {
            $data = self::functionData($process, DoraFunction::forProcess((int) $process['id']));
            $functions[] = [
                'id'          => (int) $process['id'],
                'identifier'  => $data['identifier'],
                'name'        => (string) $process['name'],
                'url'         => self::tabURL(
                    BusinessProcess::getFormURLWithID((int) $process['id']),
                    self::TAB_FUNCTION
                ),
                'criticality' => (int) $data['criticality'],
                'criticality_label' => $criticalities[(int) $data['criticality']]
                    ?? __('Not assessed yet', 'grcmanager'),
            ];
        }
        $hiddenFunctions = count($functionIds) - count($functions);

        // Processus qui dépendent d'un fournisseur du contrat (onglet « Dépendances ») : proposés.
        $proposalIds = [];
        $links = (new BusinessProcessItem())->find(['itemtype' => Supplier::class, 'items_id' => $supplierIds ?: [0]]);
        foreach ($links as $link) {
            $processId = (int) $link['plugin_grcmanager_businessprocesses_id'];
            if (!in_array($processId, $functionIds, true)) {
                $proposalIds[] = $processId;
            }
        }
        $proposedProcesses = [];
        foreach (self::readableProcesses(array_values(array_unique($proposalIds))) as $process) {
            $proposedProcesses[] = ['id' => (int) $process['id'], 'name' => (string) $process['name']];
        }

        $subcontractors = DoraArrangement::getSubcontractors($id);
        $ranks = DoraArrangement::ranks($subcontractors);
        $subIds = array_column($subcontractors, 'suppliers_id');
        $names = [];
        foreach (self::readableSuppliers(array_values(array_unique(array_merge($subIds, $supplierIds)))) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        $chain = [];
        foreach ($subcontractors as $sub) {
            $chain[] = [
                'id'        => $sub['suppliers_id'],
                'name'      => $names[$sub['suppliers_id']] ?? __('Supplier not visible', 'grcmanager'),
                'url'       => self::tabURL(Supplier::getFormURLWithID($sub['suppliers_id']), self::TAB_PROVIDER),
                'rank'      => $ranks[$sub['suppliers_id']] ?? 2,
                'recipient' => $sub['recipient'] > 0
                    ? ($names[$sub['recipient']] ?? '')
                    : ($suppliers[$provider] ?? __('Direct provider', 'grcmanager')),
            ];
        }
        usort($chain, static fn (array $a, array $b): int => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);
        $recipients = [0 => sprintf(__('%s (direct provider)', 'grcmanager'), $suppliers[$provider] ?? '-')];
        foreach ($subcontractors as $sub) {
            $recipients[$sub['suppliers_id']] = $names[$sub['suppliers_id']] ?? ('#' . $sub['suppliers_id']);
        }

        $notice = (int) ($contract->fields['notice'] ?? 0);
        $end = RegisterRules::endDate($begin, (int) ($contract->fields['duration'] ?? 0));
        $collected = self::collect();
        $critical = false;
        foreach ($collected['register']['arrangements'] as $arrangement) {
            if ((int) $arrangement['key'] === $id) {
                $critical = RegisterBuilder::supportsCritical($arrangement, $collected['register']['functions']);
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/dora/arrangement_tab.html.twig', $vars + [
            'dora'             => $dora,
            'suppliers'        => $suppliers,
            'provider'         => $provider,
            'contract_num'     => trim((string) ($contract->fields['num'] ?? '')),
            'begin_date'       => $begin,
            'end_date'         => $end,
            'is_open_ended'    => $end === RegisterTemplates::OPEN_DATE,
            'has_ended'        => $end !== null && $end < $today,
            'contract_notice'  => $notice,
            'currency'         => $settings['currency'],
            'computed_cost'    => self::contractCost($id, $today),
            'service_types'    => self::options('service_type'),
            'selected_types'   => RegisterRules::serviceTypes((string) $dora['service_types']),
            'options'          => [
                'arrangement_type'         => self::options('arrangement_type'),
                'termination_reason'       => self::options('termination_reason'),
                'yes_no'                   => self::options('yes_no'),
                'sensitivity'              => self::options('sensitivity'),
                'reliance'                 => self::options('reliance'),
                'substitutability'         => self::options('substitutability'),
                'non_substitutable_reason' => self::options('non_substitutable_reason'),
                'reintegration'            => self::options('reintegration'),
                'impact'                   => self::options('impact'),
                'alternatives'             => self::options('alternatives'),
            ],
            'functions'        => $functions,
            'hidden_functions' => $hiddenFunctions,
            'proposed'         => $proposedProcesses,
            'can_link_functions' => BusinessProcess::canView(),
            'process_dropdown' => BusinessProcess::canView() ? BusinessProcess::dropdown([
                'name'    => 'plugin_grcmanager_businessprocesses_id',
                'display' => false,
                'used'    => $functionIds,
                'width'   => '100%',
                'rand'    => mt_rand(),
            ]) : '',
            'chain'            => $chain,
            'recipients'       => $recipients,
            'supplier_dropdown' => Supplier::dropdown([
                'name'    => 'suppliers_id',
                'display' => false,
                'used'    => array_merge($subIds, $provider > 0 ? [$provider] : []),
                'width'   => '100%',
                'rand'    => mt_rand(),
            ]),
            'is_critical'      => $critical,
            'issues'           => self::issuesFor('arrangement', $id),
            'provider_issues'  => $provider > 0 ? self::issuesFor('provider', $provider) : [],
            'provider_url'     => $provider > 0
                ? self::tabURL(Supplier::getFormURLWithID($provider), self::TAB_PROVIDER) : '',
        ]);
    }

    private static function showFunction(CommonDBTM $process): void
    {
        $id = (int) $process->getID();
        $dora = DoraFunction::forProcess($id);
        $data = self::functionData($process->fields, $dora);
        $durations = BusinessProcess::getDurationOptions();
        $contracts = [];
        if (Contract::canView()) {
            global $DB;
            $contractIds = DoraArrangement::getContractIdsForFunction($id);
            if ($contractIds !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['id', 'name'],
                        'FROM'   => Contract::getTable(),
                        'WHERE'  => ['id' => $contractIds, 'is_deleted' => 0]
                            + getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
                        'ORDER'  => 'name',
                    ]) as $row
                ) {
                    $contracts[] = [
                        'name' => (string) $row['name'],
                        'url'  => self::tabURL(Contract::getFormURLWithID((int) $row['id']), self::TAB_ARRANGEMENT),
                    ];
                }
            }
        }
        $criticity = (int) ($process->fields['businesscriticities_id'] ?? 0);

        $template = '@grcmanager/dora/function_tab.html.twig';
        TemplateRenderer::getInstance()->display($template, self::commonTemplateData() + [
            'process_id'     => $id,
            'dora'           => $dora,
            'data'           => $data,
            'criticalities'  => self::options('criticality'),
            'impacts'        => self::options('impact'),
            'rto_label'      => $durations[$data['rto']] ?? '',
            'rpo_label'      => $durations[$data['rpo']] ?? '',
            'unacceptable_label' => $data['unacceptable_after'] > 0
                ? ($durations[$data['unacceptable_after']] ?? '') : '',
            'bia_date'       => $process->fields['bia_date'] ?? null,
            'business_criticity' => $criticity > 0
                ? \Dropdown::getDropdownName(BusinessCriticity::getTable(), $criticity) : '',
            'support_functions' => DoraFunction::SUPPORT_FUNCTIONS,
            'contracts'      => $contracts,
            'issues'         => self::issuesFor('function', $id),
        ]);
    }

    // ------------------------------------------------------------------ Page du registre

    public static function showRegisterPage(): void
    {
        $data = self::collect();
        $completeness = self::completeness($data);
        $register = $data['register'];
        $settings = self::getSettings();

        $arrangements = [];
        $serviceLabels = self::options('service_type');
        foreach ($register['arrangements'] as $arrangement) {
            $key = (int) $arrangement['key'];
            $issues = array_filter(
                $completeness['issues'],
                static fn (array $i): bool => $i['scope'] === 'arrangement' && (int) $i['key'] === $key
            );
            $functionNames = [];
            foreach ($arrangement['functions'] as $functionKey) {
                if (isset($register['functions'][$functionKey])) {
                    $functionNames[] = [
                        'name'     => $data['meta']['functions'][$functionKey]['name'],
                        'url'      => $data['meta']['functions'][$functionKey]['url'],
                        'critical' => (int) $register['functions'][$functionKey]['criticality']
                            === RegisterTemplates::CRITICAL,
                    ];
                }
            }
            $arrangements[] = [
                'name'      => $data['meta']['arrangements'][$key]['name'],
                'url'       => $data['meta']['arrangements'][$key]['url'],
                'reference' => $arrangement['reference'],
                'provider'  => $data['meta']['providers'][$arrangement['provider']]['name'] ?? '',
                'provider_url' => $data['meta']['providers'][$arrangement['provider']]['url'] ?? '',
                'services'  => array_map(
                    static fn (string $code): string => $code . ' ' . ($serviceLabels[$code] ?? ''),
                    $arrangement['service_types']
                ),
                'functions' => $functionNames,
                'critical'  => RegisterBuilder::supportsCritical($arrangement, $register['functions']),
                'end_date'  => $arrangement['end_date'],
                'subcontractors' => count($arrangement['subcontractors']),
                'missing'   => count($issues),
            ];
        }

        $rows = RegisterBuilder::build($register);
        $templates = self::templateSummaries();
        foreach ($templates as $code => $template) {
            $templates[$code]['rows'] = count($rows[$code] ?? []);
            $templates[$code]['url'] = self::getExportURL($code);
        }

        TemplateRenderer::getInstance()->display('@grcmanager/dora/register.html.twig', self::commonTemplateData() + [
            'settings'       => $settings,
            'entity_types'   => self::options('entity_type'),
            'hierarchies'    => self::options('hierarchy'),
            'completeness'   => $completeness,
            'arrangements'   => $arrangements,
            'providers_count' => count($register['providers']),
            'functions_count' => count($register['functions']),
            'critical_count' => count(array_filter(
                $register['functions'],
                static fn (array $f): bool => (int) $f['criticality'] === RegisterTemplates::CRITICAL
            )),
            'suggestions'    => self::suggestions(),
            'templates'      => $templates,
            'zip_url'        => self::getExportURL(),
            'zip_available'  => class_exists(\ZipArchive::class),
            'dora_url'       => RegisterTemplates::DORA_URL,
            'lei_valid'      => $settings['lei'] === '' || RegisterRules::isLei($settings['lei']),
            'can_view_processes' => BusinessProcess::canView(),
            'can_view_contracts' => Contract::canView() && Supplier::canView(),
            'contract_url'   => Contract::getSearchURL(),
        ]);
    }

    /**
     * Archive ZIP de tous les modèles (fichier temporaire, supprimé après l'envoi par l'appelant).
     */
    public static function buildZip(): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $path = tempnam(GLPI_TMP_DIR, 'dora');
        if ($path === false) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        foreach (self::exportFiles() as $name => $content) {
            $zip->addFromString('reports/' . $name, $content);
        }
        $zip->close();

        return $path;
    }
}
