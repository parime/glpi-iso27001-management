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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #178 : risques types d'infrastructure, qui reviennent d'un système d'information à l'autre
 * et se repèrent sur un simple schéma réseau. Seules les données stables sont ici (catégorie du
 * registre, mesures de l'Annexe A) ; les textes traduits sont dans \GlpiPlugin\Grcmanager\RiskCatalog.
 *
 * Les clés ai_* sont les risques types des systèmes d'IA (groupe « ai »), rattachés aux sections
 * d'ISO/IEC 27090:2026 (Iso27090CatalogDefaults) : pensés pour une organisation qui utilise
 * l'IA plus qu'elle n'en développe.
 */
final class InfrastructureRiskCatalog
{
    public const GROUP_INFRASTRUCTURE = 'infrastructure';
    public const GROUP_AI = 'ai';

    /**
     * Clé => catégorie du registre, mesures de l'Annexe A ISO/IEC 27001:2022 concernées et, pour les
     * risques d'IA, sections d'ISO/IEC 27090:2026.
     *
     * @var array<string, array{category: string, controls: list<string>, iso27090?: list<string>}>
     */
    public const RISKS = [
        'admin_exposed'       => ['category' => 'technical', 'controls' => ['A.8.20', 'A.8.21', 'A.8.22', 'A.8.2']],
        'remote_mgmt_exposed' => ['category' => 'technical', 'controls' => ['A.8.20', 'A.8.22', 'A.8.2']],
        'vpn_overlap'         => ['category' => 'third_party', 'controls' => ['A.8.20', 'A.8.21', 'A.5.20']],
        'guest_network'       => ['category' => 'technical', 'controls' => ['A.8.20', 'A.8.22']],
        'flat_network'        => ['category' => 'technical', 'controls' => ['A.8.22', 'A.8.20']],
        'spof_power'          => ['category' => 'physical', 'controls' => ['A.7.11', 'A.8.14']],
        'spof_hypervisor'     => ['category' => 'technical', 'controls' => ['A.8.14', 'A.5.30']],
        'spof_isp_link'       => ['category' => 'third_party', 'controls' => ['A.8.14', 'A.5.30', 'A.8.21']],
        'spof_directory'      => ['category' => 'technical', 'controls' => ['A.8.14', 'A.5.29']],
        'backups_same_site'   => ['category' => 'technical', 'controls' => ['A.8.13', 'A.5.30']],
        'outdated_diagrams'   => ['category' => 'process', 'controls' => ['A.5.37', 'A.8.20']],
        'logs_not_reviewed'   => ['category' => 'technical', 'controls' => ['A.8.15', 'A.8.16']],
        // Systèmes d'IA (ISO/IEC 27090:2026).
        'ai_public_services'  => [
            'category' => 'people',
            'controls' => ['A.5.10', 'A.5.12', 'A.5.14', 'A.6.3'],
            'iso27090' => ['5.2', '6.11'],
        ],
        'ai_prompt_injection' => [
            'category' => 'technical',
            'controls' => ['A.8.2', 'A.8.16', 'A.8.26'],
            'iso27090' => ['6.9', '6.10', '5.3'],
        ],
        'ai_sensitive_output' => [
            'category' => 'technical',
            'controls' => ['A.5.12', 'A.5.15', 'A.5.34', 'A.8.12'],
            'iso27090' => ['6.8', '6.4', '6.6'],
        ],
        'ai_supply_chain'     => [
            'category' => 'third_party',
            'controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.23'],
            'iso27090' => ['5.5'],
        ],
        'ai_data_poisoning'   => [
            'category' => 'technical',
            'controls' => ['A.5.21', 'A.8.3', 'A.8.32'],
            'iso27090' => ['6.2', '6.7', '6.11'],
        ],
        'ai_model_theft'      => [
            'category' => 'technical',
            'controls' => ['A.5.32', 'A.8.3', 'A.8.6', 'A.8.16'],
            'iso27090' => ['6.5', '7.8'],
        ],
        'ai_no_inventory'     => [
            'category' => 'process',
            'controls' => ['A.5.9', 'A.8.15', 'A.8.16'],
            'iso27090' => ['5.2', '7.5'],
        ],
    ];

    public static function group(string $key): string
    {
        return str_starts_with($key, 'ai_') ? self::GROUP_AI : self::GROUP_INFRASTRUCTURE;
    }

    /**
     * Sections d'ISO/IEC 27090:2026 d'un risque d'IA (vide pour les autres).
     *
     * @return list<string>
     */
    public static function iso27090(string $key): array
    {
        return self::RISKS[$key]['iso27090'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::RISKS);
    }

    public static function isKey(string $key): bool
    {
        return isset(self::RISKS[$key]);
    }

    public static function category(string $key): string
    {
        return self::RISKS[$key]['category'] ?? 'technical';
    }

    /**
     * @return list<string>
     */
    public static function controls(string $key): array
    {
        return self::RISKS[$key]['controls'] ?? [];
    }

    /**
     * Clés demandées, valides et pas encore importées, sans doublon.
     *
     * @param array<int|string, mixed> $requested
     * @param list<string> $alreadyImported
     * @return list<string>
     */
    public static function toImport(array $requested, array $alreadyImported): array
    {
        $keys = [];
        foreach ($requested as $key) {
            $key = (string) $key;
            if (self::isKey($key) && !in_array($key, $alreadyImported, true) && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
