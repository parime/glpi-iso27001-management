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

use GlpiPlugin\Grcmanager\Compatibility\Base\DoraProviderBase;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterRules;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use Log;
use Supplier;

/**
 * Issue #540 : champs propres à DORA d'un fournisseur GLPI prestataire de services TIC (modèle
 * B_05.01 du registre d'informations). Une ligne au plus par fournisseur ; le nom, le pays et le
 * numéro d'immatriculation restent ceux du fournisseur GLPI, proposés par défaut. Modifiée depuis
 * l'onglet « Registre DORA » du fournisseur (DoraRegister), historisée sur le fournisseur.
 */
class DoraProvider extends DoraProviderBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Types de code proposés (B_05.01.0020 et 0040). */
    public const CODE_TYPES = ['LEI', 'EUID', 'CRN', 'VAT', 'PNR', 'NIN'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_doraproviders';
    }

    public static function getTypeName($nb = 0)
    {
        return __('ICT third-party service provider', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-skyscraper';
    }

    /**
     * @return array<string, mixed> ligne enregistrée, ou [] si le fournisseur n'a pas encore de champs DORA
     */
    public static function forSupplier(int $suppliersId): array
    {
        $item = new self();

        return $suppliersId > 0 && $item->getFromDBByCrit(['suppliers_id' => $suppliersId]) ? $item->fields : [];
    }

    /**
     * Saisie nettoyée : codes et pays au format attendu, listes fermées bornées, montant en unités.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $type = static fn (string $key): string => in_array(
            strtoupper((string) ($input[$key] ?? '')),
            self::CODE_TYPES,
            true
        ) ? strtoupper((string) $input[$key]) : '';
        $text = static fn (string $key, int $max): string => mb_substr(trim((string) ($input[$key] ?? '')), 0, $max);
        $code = static fn (string $key): string => strtoupper(str_replace(' ', '', $text($key, 64)));

        return [
            'code'                 => $code('code'),
            'code_type'            => $type('code_type'),
            'code_country'         => RegisterRules::country($input['code_country'] ?? ''),
            'additional_code'      => $code('additional_code'),
            'additional_code_type' => $type('additional_code_type'),
            'legal_name'           => $text('legal_name', 255),
            'latin_name'           => $text('latin_name', 255),
            'person_type'          => RegisterRules::option($input['person_type'] ?? 1, RegisterTemplates::PERSON_TYPES)
                ?: RegisterTemplates::LEGAL_PERSON,
            'hq_country'           => RegisterRules::country($input['hq_country'] ?? ''),
            'currency'             => RegisterRules::currency($input['currency'] ?? ''),
            'annual_cost'          => RegisterRules::amount($input['annual_cost'] ?? '') ?? 'NULL',
            'parent_code'          => $code('parent_code'),
            'parent_code_type'     => $type('parent_code_type'),
            'parent_code_country'  => RegisterRules::country($input['parent_code_country'] ?? ''),
        ];
    }

    /**
     * Enregistre les champs DORA du fournisseur (création à la première saisie).
     *
     * @param array<string, mixed> $input
     */
    public static function saveFor(int $suppliersId, array $input): bool
    {
        $clean = self::sanitize($input);
        $item = new self();
        if ($item->getFromDBByCrit(['suppliers_id' => $suppliersId])) {
            return (bool) $item->update(['id' => $item->getID()] + $clean);
        }

        return (bool) $item->add(['suppliers_id' => $suppliersId] + $clean);
    }

    public function post_addItem()
    {
        $this->logOnSupplier();
    }

    public function post_updateItem($history = true)
    {
        if ($this->updates !== []) {
            $this->logOnSupplier();
        }
    }

    private function logOnSupplier(): void
    {
        DoraRegister::resetCache();
        Log::history(
            (int) $this->fields['suppliers_id'],
            Supplier::class,
            [0, '', __('DORA register: provider information updated', 'grcmanager')],
            0,
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }
}
