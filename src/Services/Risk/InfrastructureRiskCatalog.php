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
 * registre, mesures de l'Annexe A) ; les textes traduits sont dans PluginGrcmanagerRiskCatalog.
 */
final class InfrastructureRiskCatalog
{
    /**
     * Clé => catégorie du registre et mesures de l'Annexe A ISO/IEC 27001:2022 concernées.
     *
     * @var array<string, array{category: string, controls: list<string>}>
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
    ];

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
