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

namespace GlpiPlugin\Grcmanager\Services\Dora;

/**
 * Issue #540 : contrôle des champs obligatoires du registre d'informations DORA avant l'export,
 * selon les règles de remplissage du règlement d'exécution (UE) 2024/2956 (champ obligatoire,
 * obligatoire si le service soutient une fonction critique ou importante, si des données sont
 * stockées...). PHP pur, testé seul ; mêmes données que RegisterBuilder.
 *
 * Chaque anomalie : scope (entity, arrangement, provider, function), key (identifiant GLPI, 0 pour
 * l'entité), field (nom du champ dans le plugin), column (référence officielle de la colonne) et
 * kind (missing : absent ; invalid : présent mais au mauvais format).
 */
final class RegisterCompleteness
{
    public const MISSING = 'missing';
    public const INVALID = 'invalid';

    /**
     * @param array<string, mixed> $register
     * @return array{issues: list<array{scope: string, key: int, field: string, column: string, kind: string}>,
     *     checked: int, rate: int}
     */
    public static function check(array $register): array
    {
        $entity = (array) ($register['entity'] ?? []);
        /** @var array<int, array<string, mixed>> $providers */
        $providers = (array) ($register['providers'] ?? []);
        /** @var array<int, array<string, mixed>> $functions */
        $functions = (array) ($register['functions'] ?? []);
        $arrangements = array_values((array) ($register['arrangements'] ?? []));
        $today = (string) ($register['today'] ?? date('Y-m-d'));

        $checker = new self();
        $lei = (string) ($entity['lei'] ?? '');
        $checker->expect('entity', 0, 'lei', 'B_01.01.0010', $lei !== '', $lei === '' || RegisterRules::isLei($lei));
        $checker->expect('entity', 0, 'name', 'B_01.01.0020', trim((string) ($entity['name'] ?? '')) !== '');
        $checker->expect('entity', 0, 'country', 'B_01.01.0030', (string) ($entity['country'] ?? '') !== '');
        $type = (int) ($entity['type'] ?? 0);
        $checker->expect('entity', 0, 'type', 'B_01.01.0040', $type > 0);
        $checker->expect('entity', 0, 'authority', 'B_01.01.0050', trim((string) ($entity['authority'] ?? '')) !== '');
        $hierarchy = (int) ($entity['hierarchy'] ?? 0);
        $checker->expect('entity', 0, 'hierarchy', 'B_01.02.0050', $hierarchy > 0);
        if (in_array($hierarchy, [2, 3], true)) {
            $parent = (string) ($entity['parent_lei'] ?? '');
            $checker->expect(
                'entity',
                0,
                'parent_lei',
                'B_01.02.0060',
                $parent !== '',
                $parent === '' || RegisterRules::isLei($parent)
            );
        }
        if ($type > 0 && $type <= RegisterTemplates::LAST_FINANCIAL_TYPE) {
            $checker->expect('entity', 0, 'total_assets', 'B_01.02.0110', ($entity['total_assets'] ?? null) !== null);
            $checker->expect('entity', 0, 'currency', 'B_01.02.0100', (string) ($entity['currency'] ?? '') !== '');
        }

        $checkedProviders = [];
        $checkedFunctions = [];
        foreach ($arrangements as $arrangement) {
            $key = (int) ($arrangement['key'] ?? 0);
            $checker->expect(
                'arrangement',
                $key,
                'reference',
                'B_02.01.0010',
                (string) ($arrangement['reference'] ?? '') !== ''
            );
            $arrangementType = (int) ($arrangement['type'] ?? 0);
            $checker->expect('arrangement', $key, 'type', 'B_02.01.0020', $arrangementType > 0);
            if ($arrangementType === RegisterTemplates::ARRANGEMENT_SUBSEQUENT) {
                $checker->expect(
                    'arrangement',
                    $key,
                    'overarching',
                    'B_02.01.0030',
                    (string) ($arrangement['overarching'] ?? '') !== ''
                );
            }
            $checker->expect(
                'arrangement',
                $key,
                'currency',
                'B_02.01.0040',
                (string) ($arrangement['currency'] ?? '') !== ''
            );
            $checker->expect(
                'arrangement',
                $key,
                'annual_cost',
                'B_02.01.0050',
                ($arrangement['annual_cost'] ?? null) !== null
            );
            $providerKey = (int) ($arrangement['provider'] ?? 0);
            $checker->expect('arrangement', $key, 'provider', 'B_02.02.0030', isset($providers[$providerKey]));
            $checker->expect(
                'arrangement',
                $key,
                'service_types',
                'B_02.02.0060',
                (array) ($arrangement['service_types'] ?? []) !== []
            );
            $linked = array_filter(
                array_map('intval', (array) ($arrangement['functions'] ?? [])),
                static fn (int $f): bool => isset($functions[$f])
            );
            $checker->expect('arrangement', $key, 'functions', 'B_02.02.0050', $linked !== []);
            $checker->expect(
                'arrangement',
                $key,
                'start_date',
                'B_02.02.0070',
                ($arrangement['start_date'] ?? null) !== null
            );
            $end = $arrangement['end_date'] ?? null;
            if ($end !== null && (string) $end < $today) {
                $checker->expect(
                    'arrangement',
                    $key,
                    'termination_reason',
                    'B_02.02.0090',
                    (int) ($arrangement['termination_reason'] ?? 0) > 0
                );
            }

            if (RegisterBuilder::supportsCritical($arrangement, $functions)) {
                $checker->expect(
                    'arrangement',
                    $key,
                    'notice_entity',
                    'B_02.02.0100',
                    ($arrangement['notice_entity'] ?? null) !== null
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'notice_provider',
                    'B_02.02.0110',
                    ($arrangement['notice_provider'] ?? null) !== null
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'governing_law',
                    'B_02.02.0120',
                    (string) ($arrangement['governing_law'] ?? '') !== ''
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'provision_country',
                    'B_02.02.0130',
                    (string) ($arrangement['provision_country'] ?? '') !== ''
                );
                $storage = (int) ($arrangement['data_storage'] ?? 0);
                $checker->expect('arrangement', $key, 'data_storage', 'B_02.02.0140', $storage > 0);
                if ($storage === RegisterTemplates::YES) {
                    $checker->expect(
                        'arrangement',
                        $key,
                        'storage_countries',
                        'B_02.02.0150',
                        (array) ($arrangement['storage_countries'] ?? []) !== []
                    );
                    $checker->expect(
                        'arrangement',
                        $key,
                        'sensitivity',
                        'B_02.02.0170',
                        (int) ($arrangement['sensitivity'] ?? 0) > 0
                    );
                }
                $checker->expect(
                    'arrangement',
                    $key,
                    'processing_countries',
                    'B_02.02.0160',
                    (array) ($arrangement['processing_countries'] ?? []) !== []
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'reliance',
                    'B_02.02.0180',
                    (int) ($arrangement['reliance'] ?? 0) > 0
                );
                $substitutability = (int) ($arrangement['substitutability'] ?? 0);
                $checker->expect('arrangement', $key, 'substitutability', 'B_07.01.0050', $substitutability > 0);
                if (in_array($substitutability, [1, 2], true)) {
                    $checker->expect(
                        'arrangement',
                        $key,
                        'non_substitutable_reason',
                        'B_07.01.0060',
                        (int) ($arrangement['non_substitutable_reason'] ?? 0) > 0
                    );
                }
                $checker->expect(
                    'arrangement',
                    $key,
                    'exit_plan',
                    'B_07.01.0080',
                    (int) ($arrangement['exit_plan'] ?? 0) > 0
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'reintegration',
                    'B_07.01.0090',
                    (int) ($arrangement['reintegration'] ?? 0) > 0
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'discontinuing_impact',
                    'B_07.01.0100',
                    (int) ($arrangement['discontinuing_impact'] ?? 0) > 0
                );
                $checker->expect(
                    'arrangement',
                    $key,
                    'alternatives',
                    'B_07.01.0110',
                    (int) ($arrangement['alternatives'] ?? 0) > 0
                );
            }

            $chain = array_merge(
                $providerKey > 0 ? [$providerKey] : [],
                array_map(
                    static fn ($s): int => (int) ($s['provider'] ?? 0),
                    (array) ($arrangement['subcontractors'] ?? [])
                )
            );
            foreach ($chain as $index => $chainKey) {
                if (!isset($providers[$chainKey]) || isset($checkedProviders[$chainKey])) {
                    continue;
                }
                $checkedProviders[$chainKey] = true;
                self::checkProvider($checker, $chainKey, $providers[$chainKey], $index === 0 && $providerKey > 0);
            }
            foreach ($linked as $functionKey) {
                if (isset($checkedFunctions[$functionKey])) {
                    continue;
                }
                $checkedFunctions[$functionKey] = true;
                $function = $functions[$functionKey];
                $checker->expect(
                    'function',
                    $functionKey,
                    'licensed_activity',
                    'B_06.01.0020',
                    trim((string) ($function['licensed_activity'] ?? '')) !== ''
                );
                $criticality = (int) ($function['criticality'] ?? 0);
                $checker->expect('function', $functionKey, 'criticality', 'B_06.01.0060', $criticality > 0);
                if (in_array($criticality, [1, 2], true)) {
                    $checker->expect(
                        'function',
                        $functionKey,
                        'assessment_date',
                        'B_06.01.0080',
                        (string) ($function['assessment_date'] ?? '') !== ''
                    );
                }
            }
        }

        return $checker->result();
    }

    /**
     * @param array<string, mixed> $provider
     */
    private static function checkProvider(self $checker, int $key, array $provider, bool $direct): void
    {
        $code = (string) ($provider['code'] ?? '');
        $type = (string) ($provider['code_type'] ?? '');
        $checker->expect(
            'provider',
            $key,
            'code',
            'B_05.01.0010',
            $code !== '',
            $code === '' || $type === ''
                || RegisterRules::isProviderCodeValid($code, $type, (int) ($provider['person_type'] ?? 0))
        );
        $checker->expect('provider', $key, 'code_type', 'B_05.01.0020', $type !== '');
        $checker->expect(
            'provider',
            $key,
            'legal_name',
            'B_05.01.0050',
            trim((string) ($provider['legal_name'] ?? '')) !== ''
        );
        $checker->expect('provider', $key, 'person_type', 'B_05.01.0070', (int) ($provider['person_type'] ?? 0) > 0);
        $checker->expect(
            'provider',
            $key,
            'hq_country',
            'B_05.01.0080',
            (string) ($provider['hq_country'] ?? '') !== ''
        );
        if ($direct) {
            $checker->expect(
                'provider',
                $key,
                'currency',
                'B_05.01.0090',
                (string) ($provider['currency'] ?? '') !== ''
            );
        }
    }

    /** @var list<array{scope: string, key: int, field: string, column: string, kind: string}> */
    private array $issues = [];
    private int $checked = 0;

    private function expect(
        string $scope,
        int $key,
        string $field,
        string $column,
        bool $present,
        bool $valid = true
    ): void {
        $this->checked++;
        if (!$present || !$valid) {
            $this->issues[] = [
                'scope'  => $scope,
                'key'    => $key,
                'field'  => $field,
                'column' => $column,
                'kind'   => $present ? self::INVALID : self::MISSING,
            ];
        }
    }

    /**
     * @return array{issues: list<array{scope: string, key: int, field: string, column: string, kind: string}>,
     *     checked: int, rate: int}
     */
    private function result(): array
    {
        $rate = $this->checked === 0
            ? 100
            : (int) floor(100 * ($this->checked - count($this->issues)) / $this->checked);

        return ['issues' => $this->issues, 'checked' => $this->checked, 'rate' => $rate];
    }
}
