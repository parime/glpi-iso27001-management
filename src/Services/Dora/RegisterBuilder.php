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
 * Issue #540 : lignes de chaque modèle du registre d'informations DORA, à partir des données du
 * registre déjà lues (entité, prestataires, fonctions, accords). PHP pur, testé seul : la lecture
 * dans GLPI est faite par \GlpiPlugin\Grcmanager\DoraRegister::collect().
 *
 * Données attendues (clés des prestataires et des fonctions : identifiants GLPI) :
 *
 * - entity : lei, name, country, type, authority, currency, total_assets (?float), hierarchy,
 *   parent_lei, integration_date, last_update, reporting_date ;
 * - providers : key => code, code_type (déjà composé, « LEI » ou « FR_CRN »), additional_code,
 *   additional_code_type, legal_name, latin_name, person_type, hq_country, currency,
 *   annual_cost (?float), parent_code, parent_code_type ;
 * - functions : key => identifier, name, licensed_activity, criticality, reasons, assessment_date,
 *   rto, rpo, impact ;
 * - arrangements : liste de key, reference, type, overarching, currency, annual_cost, provider
 *   (clé, 0 sans prestataire), service_types, start_date, end_date, termination_reason,
 *   notice_entity, notice_provider, governing_law, provision_country, data_storage,
 *   storage_countries, processing_countries, sensitivity, reliance, substitutability,
 *   non_substitutable_reason, last_audit_date, exit_plan, reintegration, discontinuing_impact,
 *   alternatives, alternative_providers, functions (clés), subcontractors (provider, rank,
 *   recipient : clé du prestataire qui sous-traite, 0 pour le prestataire direct).
 *
 * Les valeurs des listes fermées sont écrites avec leur intitulé officiel ; les types de services
 * avec leur identifiant (S01 à S19), les pays et devises avec leur code ISO, les dates au format
 * ISO 8601 et les montants en unités, comme le demande le règlement d'exécution.
 */
final class RegisterBuilder
{
    /**
     * @param array<string, mixed> $register
     * @return array<string, list<array<string, string>>> modèle => lignes (colonne => valeur)
     */
    public static function build(array $register): array
    {
        $entity = (array) ($register['entity'] ?? []);
        /** @var array<int, array<string, mixed>> $providers */
        $providers = (array) ($register['providers'] ?? []);
        /** @var array<int, array<string, mixed>> $functions */
        $functions = (array) ($register['functions'] ?? []);
        /** @var list<array<string, mixed>> $arrangements */
        $arrangements = array_values((array) ($register['arrangements'] ?? []));
        $today = (string) ($register['today'] ?? date('Y-m-d'));
        $lei = (string) ($entity['lei'] ?? '');

        $rows = array_fill_keys(array_keys(RegisterTemplates::TEMPLATES), []);
        $rows['B_01.01'][] = [
            'c0010' => $lei,
            'c0020' => self::text($entity['name'] ?? ''),
            'c0030' => (string) ($entity['country'] ?? ''),
            'c0040' => self::label(RegisterTemplates::ENTITY_TYPES, $entity['type'] ?? 0),
            'c0050' => self::text($entity['authority'] ?? ''),
            'c0060' => (string) ($entity['reporting_date'] ?? $today),
        ];
        $totalAssets = $entity['total_assets'] ?? null;
        $rows['B_01.02'][] = [
            'c0010' => $lei,
            'c0020' => self::text($entity['name'] ?? ''),
            'c0030' => (string) ($entity['country'] ?? ''),
            'c0040' => self::label(RegisterTemplates::ENTITY_TYPES, $entity['type'] ?? 0),
            'c0050' => self::label(RegisterTemplates::HIERARCHIES, $entity['hierarchy'] ?? 0),
            'c0060' => (string) ($entity['parent_lei'] ?? ''),
            'c0070' => (string) ($entity['last_update'] ?? $today),
            'c0080' => (string) ($entity['integration_date'] ?? $today),
            'c0090' => RegisterTemplates::OPEN_DATE,
            'c0100' => $totalAssets === null ? '' : (string) ($entity['currency'] ?? ''),
            'c0110' => self::amount($totalAssets),
        ];

        $usedProviders = [];
        $usedFunctions = [];
        $directCosts = [];
        foreach ($arrangements as $arrangement) {
            $reference = (string) ($arrangement['reference'] ?? '');
            $provider = $providers[(int) ($arrangement['provider'] ?? 0)] ?? null;
            $providerCode = $provider === null ? '' : (string) $provider['code'];
            $providerType = $provider === null ? '' : (string) $provider['code_type'];
            $types = array_values((array) ($arrangement['service_types'] ?? []));
            $types = $types === [] ? [''] : $types;
            $critical = self::supportsCritical($arrangement, $functions);
            $cost = $arrangement['annual_cost'] ?? null;
            if ($provider !== null) {
                $usedProviders[(int) $arrangement['provider']] = true;
                $directCosts[(int) $arrangement['provider']] = ($directCosts[(int) $arrangement['provider']] ?? 0.0)
                    + (float) ($cost ?? 0);
            }

            $rows['B_02.01'][] = [
                'c0010' => $reference,
                'c0020' => self::label(RegisterTemplates::ARRANGEMENT_TYPES, $arrangement['type'] ?? 0),
                'c0030' => (int) ($arrangement['type'] ?? 0) === RegisterTemplates::ARRANGEMENT_SUBSEQUENT
                    ? (string) ($arrangement['overarching'] ?? '') : '',
                'c0040' => (string) ($arrangement['currency'] ?? ''),
                'c0050' => self::amount($cost),
            ];
            $rows['B_03.01'][] = ['c0010' => $reference, 'c0020' => $lei];
            $rows['B_03.02'][] = ['c0010' => $reference, 'c0020' => $providerCode, 'c0030' => $providerType];
            $rows['B_04.01'][] = [
                'c0010' => $reference,
                'c0020' => $lei,
                'c0030' => RegisterTemplates::NATURES[RegisterTemplates::NATURE_NOT_BRANCH],
                'c0040' => '',
            ];

            // B_02.02 : une ligne par fonction, type de service et pays de stockage ou de traitement.
            $stores = (int) ($arrangement['data_storage'] ?? 0) === RegisterTemplates::YES;
            $storage = $stores ? array_values((array) ($arrangement['storage_countries'] ?? [])) : [];
            $processing = array_values((array) ($arrangement['processing_countries'] ?? []));
            $locations = max(1, count($storage), count($processing));
            $functionKeys = array_values(array_filter(
                array_map('intval', (array) ($arrangement['functions'] ?? [])),
                static fn (int $key): bool => isset($functions[$key])
            ));
            $ended = ($arrangement['end_date'] ?? null) !== null
                && (string) $arrangement['end_date'] < $today;
            foreach ($functionKeys === [] ? [0] : $functionKeys as $functionKey) {
                $usedFunctions[$functionKey] = true;
                foreach ($types as $type) {
                    for ($i = 0; $i < $locations; $i++) {
                        $rows['B_02.02'][] = [
                            'c0010' => $reference,
                            'c0020' => $lei,
                            'c0030' => $providerCode,
                            'c0040' => $providerType,
                            'c0050' => $functionKey > 0 ? (string) $functions[$functionKey]['identifier'] : '',
                            'c0060' => (string) $type,
                            'c0070' => (string) ($arrangement['start_date'] ?? ''),
                            'c0080' => (string) ($arrangement['end_date'] ?? ''),
                            'c0090' => $ended || (int) ($arrangement['termination_reason'] ?? 0) > 0
                                ? self::label(
                                    RegisterTemplates::TERMINATION_REASONS,
                                    $arrangement['termination_reason'] ?? 0
                                )
                                : '',
                            'c0100' => self::number($arrangement['notice_entity'] ?? null),
                            'c0110' => self::number($arrangement['notice_provider'] ?? null),
                            'c0120' => (string) ($arrangement['governing_law'] ?? ''),
                            'c0130' => (string) ($arrangement['provision_country'] ?? ''),
                            'c0140' => self::label(RegisterTemplates::YES_NO, $arrangement['data_storage'] ?? 0),
                            'c0150' => (string) ($storage[$i] ?? ($storage[0] ?? '')),
                            'c0160' => (string) ($processing[$i] ?? ($processing[0] ?? '')),
                            'c0170' => $stores
                                ? self::label(RegisterTemplates::SENSITIVENESS, $arrangement['sensitivity'] ?? 0) : '',
                            'c0180' => self::label(RegisterTemplates::RELIANCE, $arrangement['reliance'] ?? 0),
                        ];
                    }
                }
            }

            // B_05.02 : chaîne de sous-traitance, par type de service.
            $subcontractors = array_values((array) ($arrangement['subcontractors'] ?? []));
            foreach ($types as $type) {
                if ($provider !== null) {
                    $rows['B_05.02'][] = [
                        'c0010' => $reference,
                        'c0020' => (string) $type,
                        'c0030' => $providerCode,
                        'c0040' => $providerType,
                        'c0050' => '1',
                        'c0060' => '',
                        'c0070' => '',
                    ];
                }
                foreach ($subcontractors as $sub) {
                    $subProvider = $providers[(int) ($sub['provider'] ?? 0)] ?? null;
                    if ($subProvider === null) {
                        continue;
                    }
                    $usedProviders[(int) $sub['provider']] = true;
                    $recipient = (int) ($sub['recipient'] ?? 0) > 0
                        ? ($providers[(int) $sub['recipient']] ?? null)
                        : $provider;
                    $rows['B_05.02'][] = [
                        'c0010' => $reference,
                        'c0020' => (string) $type,
                        'c0030' => (string) $subProvider['code'],
                        'c0040' => (string) $subProvider['code_type'],
                        'c0050' => (string) max(2, (int) ($sub['rank'] ?? 2)),
                        'c0060' => $recipient === null ? '' : (string) $recipient['code'],
                        'c0070' => $recipient === null ? '' : (string) $recipient['code_type'],
                    ];
                }
            }

            // B_07.01 : évaluation des services qui soutiennent une fonction critique ou importante.
            if ($critical) {
                $substitutability = (int) ($arrangement['substitutability'] ?? 0);
                $alternatives = (int) ($arrangement['alternatives'] ?? 0);
                $audit = $arrangement['last_audit_date'] ?? null;
                foreach ($types as $type) {
                    $rows['B_07.01'][] = [
                        'c0010' => $reference,
                        'c0020' => $providerCode,
                        'c0030' => $providerType,
                        'c0040' => (string) $type,
                        'c0050' => self::label(RegisterTemplates::SUBSTITUTABILITY, $substitutability),
                        'c0060' => in_array($substitutability, [1, 2], true)
                            ? self::label(
                                RegisterTemplates::NON_SUBSTITUTABLE_REASONS,
                                $arrangement['non_substitutable_reason'] ?? 0
                            ) : '',
                        'c0070' => $audit === null || $audit === '' ? RegisterTemplates::OPEN_DATE : (string) $audit,
                        'c0080' => self::label(RegisterTemplates::YES_NO, $arrangement['exit_plan'] ?? 0),
                        'c0090' => self::label(RegisterTemplates::REINTEGRATION, $arrangement['reintegration'] ?? 0),
                        'c0100' => self::label(RegisterTemplates::IMPACTS, $arrangement['discontinuing_impact'] ?? 0),
                        'c0110' => self::label(RegisterTemplates::ALTERNATIVES, $alternatives),
                        'c0120' => $alternatives === RegisterTemplates::ALTERNATIVES_YES
                            ? self::text($arrangement['alternative_providers'] ?? '') : '',
                    ];
                }
            }
        }

        foreach (array_keys($usedProviders) as $key) {
            $provider = $providers[$key];
            $cost = $provider['annual_cost'] ?? null;
            if ($cost === null && isset($directCosts[$key])) {
                $cost = $directCosts[$key];
            }
            $parentCode = (string) ($provider['parent_code'] ?? '');
            $rows['B_05.01'][] = [
                'c0010' => (string) $provider['code'],
                'c0020' => (string) $provider['code_type'],
                'c0030' => (string) ($provider['additional_code'] ?? ''),
                'c0040' => (string) ($provider['additional_code'] ?? '') === ''
                    ? '' : (string) ($provider['additional_code_type'] ?? ''),
                'c0050' => self::text($provider['legal_name'] ?? ''),
                'c0060' => self::text(($provider['latin_name'] ?? '') !== ''
                    ? $provider['latin_name'] : ($provider['legal_name'] ?? '')),
                'c0070' => self::label(RegisterTemplates::PERSON_TYPES, $provider['person_type'] ?? 0),
                'c0080' => (string) ($provider['hq_country'] ?? ''),
                'c0090' => $cost === null ? '' : (string) ($provider['currency'] ?? ''),
                'c0100' => self::amount($cost),
                // Hors groupe : le code du prestataire est répété (B_05.01.0110 et 0120).
                'c0110' => $parentCode !== '' ? $parentCode : (string) $provider['code'],
                'c0120' => $parentCode !== ''
                    ? (string) ($provider['parent_code_type'] ?? '') : (string) $provider['code_type'],
            ];
        }

        foreach (array_keys($usedFunctions) as $key) {
            if (!isset($functions[$key])) {
                continue;
            }
            $function = $functions[$key];
            $criticality = (int) ($function['criticality'] ?? 0);
            $date = $function['assessment_date'] ?? null;
            $rows['B_06.01'][] = [
                'c0010' => (string) $function['identifier'],
                'c0020' => self::text($function['licensed_activity'] ?? ''),
                'c0030' => self::text($function['name'] ?? ''),
                'c0040' => $lei,
                'c0060' => self::label(RegisterTemplates::CRITICALITY, $criticality),
                'c0070' => self::text(mb_substr((string) ($function['reasons'] ?? ''), 0, 300)),
                'c0080' => $criticality === RegisterTemplates::NOT_ASSESSED || $date === null || $date === ''
                    ? ($criticality === RegisterTemplates::NOT_ASSESSED ? RegisterTemplates::OPEN_DATE : '')
                    : (string) $date,
                'c0090' => (string) max(0, (int) ($function['rto'] ?? 0)),
                'c0100' => (string) max(0, (int) ($function['rpo'] ?? 0)),
                'c0110' => self::label(RegisterTemplates::IMPACTS, $function['impact'] ?? 0),
            ];
        }

        foreach (RegisterTemplates::TERMINOLOGY_ROWS as $row) {
            $rows['B_99.01'][] = ['c0010' => $row[0], 'c0020' => $row[1], 'c0030' => $row[2], 'c0040' => ''];
        }

        return $rows;
    }

    /**
     * L'accord soutient-il au moins une fonction jugée critique ou importante ?
     *
     * @param array<string, mixed> $arrangement
     * @param array<int, array<string, mixed>> $functions
     */
    public static function supportsCritical(array $arrangement, array $functions): bool
    {
        foreach ((array) ($arrangement['functions'] ?? []) as $key) {
            if ((int) ($functions[(int) $key]['criticality'] ?? 0) === RegisterTemplates::CRITICAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $options
     */
    private static function label(array $options, mixed $value): string
    {
        return $options[is_numeric($value) ? (int) $value : 0] ?? '';
    }

    private static function amount(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $amount = round((float) $value, 2);

        return $amount === floor($amount) ? sprintf('%.0f', $amount) : sprintf('%.2f', $amount);
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? (string) (int) $value : '';
    }

    private static function text(mixed $value): string
    {
        return RegisterRules::csvText(trim(is_scalar($value) ? (string) $value : ''));
    }
}
