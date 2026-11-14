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

use Contract;
use GlpiPlugin\Grcmanager\Compatibility\Base\DoraArrangementBase;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterRules;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use Log;

/**
 * Issue #540 : accord contractuel du registre d'informations DORA, porté par un contrat GLPI
 * (modèles B_02.01, B_02.02, B_07.01). Seuls les champs propres à DORA sont ici : nom, numéro,
 * dates, durée, préavis et coûts restent ceux du contrat GLPI. Un contrat est dans le registre tant
 * que cette ligne existe.
 *
 * Fonctions soutenues (processus métier, B_02.02.0050) et sous-traitants (B_05.02) : tables de
 * liaison gérées ici. Historisé sur le contrat.
 */
class DoraArrangement extends DoraArrangementBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FUNCTIONS_TABLE = 'glpi_plugin_grcmanager_doraarrangements_functions';
    public const SUBCONTRACTORS_TABLE = 'glpi_plugin_grcmanager_dorasubcontractors';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_doraarrangements';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Contractual arrangement', 'Contractual arrangements', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    /**
     * @return array<string, mixed>
     */
    public static function forContract(int $contractsId): array
    {
        $item = new self();

        return $contractsId > 0 && $item->getFromDBByCrit(['contracts_id' => $contractsId]) ? $item->fields : [];
    }

    /**
     * Ajoute le contrat au registre. Le préavis de l'entité est proposé depuis celui du contrat
     * (mois de GLPI comptés pour 30 jours), modifiable ensuite.
     */
    public static function include(Contract $contract): bool
    {
        if (self::forContract((int) $contract->getID()) !== []) {
            return true;
        }
        $notice = (int) ($contract->fields['notice'] ?? 0);

        return (bool) (new self())->add([
            'contracts_id'       => (int) $contract->getID(),
            'notice_entity_days' => $notice > 0 ? $notice * 30 : 'NULL',
        ]);
    }

    /** Retire le contrat du registre (champs DORA, fonctions et sous-traitants). */
    public static function exclude(int $contractsId): bool
    {
        $item = new self();
        if (!$item->getFromDBByCrit(['contracts_id' => $contractsId])) {
            return false;
        }

        return (bool) $item->delete(['id' => $item->getID()], true);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<int> $allowedSuppliers fournisseurs du contrat
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $allowedSuppliers): array
    {
        $option = static fn (string $key, array $options): int => RegisterRules::option($input[$key] ?? 0, $options);
        $supplier = (int) ($input['suppliers_id'] ?? 0);
        $countries = static fn (string $key): string => implode(',', RegisterRules::countries($input[$key] ?? ''));

        return [
            'reference'                => mb_substr(trim((string) ($input['reference'] ?? '')), 0, 255),
            'arrangement_type'         => $option('arrangement_type', RegisterTemplates::ARRANGEMENT_TYPES),
            'overarching_reference'    => mb_substr(trim((string) ($input['overarching_reference'] ?? '')), 0, 255),
            'suppliers_id'             => in_array($supplier, $allowedSuppliers, true) ? $supplier : 0,
            'service_types'            => implode(',', RegisterRules::serviceTypes($input['service_types'] ?? [])),
            'currency'                 => RegisterRules::currency($input['currency'] ?? ''),
            'annual_cost'              => RegisterRules::amount($input['annual_cost'] ?? '') ?? 'NULL',
            'termination_reason'       => $option('termination_reason', RegisterTemplates::TERMINATION_REASONS),
            'notice_entity_days'       => RegisterRules::days($input['notice_entity_days'] ?? '') ?? 'NULL',
            'notice_provider_days'     => RegisterRules::days($input['notice_provider_days'] ?? '') ?? 'NULL',
            'governing_law_country'    => RegisterRules::country($input['governing_law_country'] ?? ''),
            'provision_country'        => RegisterRules::country($input['provision_country'] ?? ''),
            'data_storage'             => $option('data_storage', RegisterTemplates::YES_NO),
            'storage_countries'        => $countries('storage_countries'),
            'processing_countries'     => $countries('processing_countries'),
            'data_sensitivity'         => $option('data_sensitivity', RegisterTemplates::SENSITIVENESS),
            'reliance'                 => $option('reliance', RegisterTemplates::RELIANCE),
            'substitutability'         => $option('substitutability', RegisterTemplates::SUBSTITUTABILITY),
            'non_substitutable_reason' => $option('non_substitutable_reason', RegisterTemplates::NON_SUBSTITUTABLE_REASONS),
            'last_audit_date'          => RegisterRules::date($input['last_audit_date'] ?? '') ?? 'NULL',
            'exit_plan'                => $option('exit_plan', RegisterTemplates::YES_NO),
            'reintegration'            => $option('reintegration', RegisterTemplates::REINTEGRATION),
            'discontinuing_impact'     => $option('discontinuing_impact', RegisterTemplates::IMPACTS),
            'alternatives'             => $option('alternatives', RegisterTemplates::ALTERNATIVES),
            'alternative_providers'    => mb_substr(trim((string) ($input['alternative_providers'] ?? '')), 0, 2000),
        ];
    }

    /**
     * @return list<int> processus métier soutenus par l'accord
     */
    public static function getFunctionIds(int $contractsId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_businessprocesses_id'],
                'FROM'   => self::FUNCTIONS_TABLE,
                'WHERE'  => ['contracts_id' => $contractsId],
                'ORDER'  => 'id',
            ]) as $row
        ) {
            $ids[] = (int) $row['plugin_grcmanager_businessprocesses_id'];
        }

        return $ids;
    }

    /**
     * @return list<int> contrats qui soutiennent le processus
     */
    public static function getContractIdsForFunction(int $processId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['contracts_id'],
                'FROM'   => self::FUNCTIONS_TABLE,
                'WHERE'  => ['plugin_grcmanager_businessprocesses_id' => $processId],
            ]) as $row
        ) {
            $ids[] = (int) $row['contracts_id'];
        }

        return $ids;
    }

    public static function addFunction(int $contractsId, int $processId): bool
    {
        global $DB;

        $link = ['contracts_id' => $contractsId, 'plugin_grcmanager_businessprocesses_id' => $processId];
        if ($processId <= 0 || countElementsInTable(self::FUNCTIONS_TABLE, $link) > 0) {
            return false;
        }
        $DB->insert(self::FUNCTIONS_TABLE, $link + ['date_creation' => date('Y-m-d H:i:s')]);
        self::logOnContract($contractsId, __('DORA register: supported function added', 'grcmanager'));

        return true;
    }

    public static function removeFunction(int $contractsId, int $processId): bool
    {
        global $DB;

        $DB->delete(self::FUNCTIONS_TABLE, [
            'contracts_id' => $contractsId, 'plugin_grcmanager_businessprocesses_id' => $processId,
        ]);
        self::logOnContract($contractsId, __('DORA register: supported function removed', 'grcmanager'));

        return true;
    }

    /**
     * @return list<array{suppliers_id: int, recipient: int}>
     */
    public static function getSubcontractors(int $contractsId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::SUBCONTRACTORS_TABLE,
                'WHERE' => ['contracts_id' => $contractsId],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $rows[] = ['suppliers_id' => (int) $row['suppliers_id'], 'recipient' => (int) $row['suppliers_id_recipient']];
        }

        return $rows;
    }

    /**
     * Rang de chaque sous-traitant dans la chaîne (B_05.02.0050) : 2 s'il travaille pour le
     * prestataire direct, un de plus que celui pour qui il travaille sinon.
     *
     * @param list<array{suppliers_id: int, recipient: int}> $subcontractors
     * @return array<int, int> fournisseur => rang
     */
    public static function ranks(array $subcontractors): array
    {
        $recipients = array_column($subcontractors, 'recipient', 'suppliers_id');
        $ranks = [];
        foreach (array_keys($recipients) as $supplier) {
            $rank = 2;
            $seen = [$supplier => true];
            $current = $recipients[$supplier];
            while ($current > 0 && isset($recipients[$current]) && !isset($seen[$current])) {
                $seen[$current] = true;
                $rank++;
                $current = $recipients[$current];
            }
            $ranks[(int) $supplier] = $rank;
        }

        return $ranks;
    }

    public static function addSubcontractor(int $contractsId, int $suppliersId, int $recipient): bool
    {
        global $DB;

        $existing = array_column(self::getSubcontractors($contractsId), 'suppliers_id');
        if ($suppliersId <= 0 || in_array($suppliersId, $existing, true) || $suppliersId === $recipient) {
            return false;
        }
        // Le donneur d'ordre est le prestataire direct (0) ou un sous-traitant déjà dans la chaîne.
        $DB->insert(self::SUBCONTRACTORS_TABLE, [
            'contracts_id'           => $contractsId,
            'suppliers_id'           => $suppliersId,
            'suppliers_id_recipient' => in_array($recipient, $existing, true) ? $recipient : 0,
            'date_creation'          => date('Y-m-d H:i:s'),
        ]);
        self::logOnContract($contractsId, __('DORA register: subcontractor added', 'grcmanager'));

        return true;
    }

    public static function removeSubcontractor(int $contractsId, int $suppliersId): bool
    {
        global $DB;

        $DB->delete(self::SUBCONTRACTORS_TABLE, ['contracts_id' => $contractsId, 'suppliers_id' => $suppliersId]);
        // Ceux qui travaillaient pour lui remontent d'un rang (pour le prestataire direct).
        $DB->update(
            self::SUBCONTRACTORS_TABLE,
            ['suppliers_id_recipient' => 0],
            ['contracts_id' => $contractsId, 'suppliers_id_recipient' => $suppliersId]
        );
        self::logOnContract($contractsId, __('DORA register: subcontractor removed', 'grcmanager'));

        return true;
    }

    public function post_addItem()
    {
        self::logOnContract((int) $this->fields['contracts_id'], __('DORA register: contract added', 'grcmanager'));
    }

    public function post_updateItem($history = true)
    {
        if ($this->updates !== []) {
            self::logOnContract((int) $this->fields['contracts_id'], __('DORA register: information updated', 'grcmanager'));
        }
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $contractsId = (int) $this->fields['contracts_id'];
        $DB->delete(self::FUNCTIONS_TABLE, ['contracts_id' => $contractsId]);
        $DB->delete(self::SUBCONTRACTORS_TABLE, ['contracts_id' => $contractsId]);
    }

    public function post_purgeItem()
    {
        self::logOnContract((int) $this->fields['contracts_id'], __('DORA register: contract removed', 'grcmanager'));
    }

    private static function logOnContract(int $contractsId, string $message): void
    {
        DoraRegister::resetCache();
        Log::history($contractsId, Contract::class, [0, '', $message], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }
}
