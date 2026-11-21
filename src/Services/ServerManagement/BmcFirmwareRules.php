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

namespace GlpiPlugin\Grcmanager\Services\ServerManagement;

use GlpiPlugin\Grcmanager\Services\Cve\FirmwareVersion;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;

/**
 * Issue #843, lot 2 : firmware du contrôleur et BIOS du serveur, rapprochés des vulnérabilités du
 * parc (#780) et des fins de support (#782) sans nouveau service : chacun devient un « firmware »
 * nommé comme dans GLPI (désignation et version), relié à un produit par les alias déjà déclarés
 * sur la page des vulnérabilités du parc ou dans la configuration d'endoflife.date.
 *
 * Sans GLPI ni réseau.
 */
final class BmcFirmwareRules
{
    public const PART_CONTROLLER = 'controller';
    public const PART_BIOS = 'bios';

    /**
     * Firmwares d'un contrôleur lu : désignation (constructeur et modèle) et version publiée.
     * Une partie sans modèle ou sans version est ignorée (rien à rapprocher).
     *
     * @param array<string, mixed> $controller ligne de la table des contrôleurs
     * @return list<array{part: string, name: string, version: string}>
     */
    public static function items(array $controller): array
    {
        $vendor = trim((string) ($controller['vendor'] ?? ''));
        $items = [];
        $parts = [
            self::PART_CONTROLLER => [(string) ($controller['manager_model'] ?? ''), '', 'firmware_version'],
            self::PART_BIOS       => [(string) ($controller['system_model'] ?? ''), ' BIOS', 'bios_version'],
        ];
        foreach ($parts as $part => [$model, $suffix, $field]) {
            $model = trim($model);
            $version = self::version((string) ($controller[$field] ?? ''));
            if ($model === '' || $version === '') {
                continue;
            }
            $name = $vendor !== '' && stripos($model, $vendor) !== 0 ? $vendor . ' ' . $model : $model;
            $items[] = ['part' => $part, 'name' => mb_substr($name . $suffix, 0, 255), 'version' => $version];
        }

        return $items;
    }

    /**
     * Version publiée d'un firmware lu par Redfish : « iLO 5 v2.72 », « U30 v2.72 (03/22/2023) » et
     * « 2.72 Feb 09 2022 » donnent « 2.72 » ; sinon la forme de FirmwareVersion::normalize().
     */
    public static function version(string $raw): string
    {
        if (preg_match('/(?<![\d.])\d+(?:\.\d+)+/', $raw, $match) === 1) {
            return $match[0];
        }

        return FirmwareVersion::normalize($raw);
    }

    /**
     * Écarts liés aux firmwares d'un contrôleur.
     *
     * @param list<array{cves: int, priority: int, eol_status: string}> $evaluations
     * @return list<string> codes de BmcRules::GAPS
     */
    public static function gaps(array $evaluations): array
    {
        $gaps = [];
        foreach ($evaluations as $evaluation) {
            if ($evaluation['priority'] > 0) {
                $gaps[BmcRules::GAP_FIRMWARE_PRIORITY_CVE] = true;
            } elseif ($evaluation['cves'] > 0) {
                $gaps[BmcRules::GAP_FIRMWARE_CVE] = true;
            }
            if ($evaluation['eol_status'] === CycleMatcher::STATUS_EOL) {
                $gaps[BmcRules::GAP_FIRMWARE_EOL] = true;
            } elseif ($evaluation['eol_status'] === CycleMatcher::STATUS_SOON) {
                $gaps[BmcRules::GAP_FIRMWARE_EOL_SOON] = true;
            }
        }
        if (isset($gaps[BmcRules::GAP_FIRMWARE_PRIORITY_CVE])) {
            unset($gaps[BmcRules::GAP_FIRMWARE_CVE]);
        }
        if (isset($gaps[BmcRules::GAP_FIRMWARE_EOL])) {
            unset($gaps[BmcRules::GAP_FIRMWARE_EOL_SOON]);
        }

        return array_keys($gaps);
    }
}
