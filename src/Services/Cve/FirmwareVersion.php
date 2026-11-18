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

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Issue #705 : versions de firmware (composants `Item_DeviceFirmware` de GLPI) comparées aux
 * plages de versions d'une CVE.
 *
 * Une version de firmware inventoriée par SNMP ou par l'agent n'a pas la forme d'une version de
 * logiciel : préfixe « v » (« v7.0.12 »), numéro de build après une virgule (« 7.0.12,build0523 »),
 * ou train de publication en lettres (Cisco « 15.2(7)E3 »). normalize() ne retire que ce qui
 * n'est jamais une partie de la version publiée par l'éditeur (préfixe « v », build), sans rien
 * deviner.
 *
 * evaluate() reste prudent : version_compare() tient deux lettres quelconques pour égales
 * (« 15.2(7)E3 » == « 15.2(7)M3 »). Une version qui contient des lettres n'est donc comparée
 * qu'à une version exacte de la CVE, à l'identique (sans tenir compte de la casse ni des
 * échappements du CPE) ; face à une plage de versions, elle reste UNKNOWN (version non
 * vérifiable), jamais MATCHED ni EXCLUDED par supposition. Une version purement numérique suit
 * la même règle que les logiciels (CpeVersionMatch).
 */
final class FirmwareVersion
{
    /** Version purement numérique (« 7.0.12 », « 2 ») : comparable comme un logiciel. */
    private const NUMERIC_PATTERN = '/^\d+(\.\d+)*$/';

    public static function normalize(string $version): string
    {
        $version = trim($version);
        // Numéro de build : « 7.0.12,build0523 », « 7.0.12 build 0523 (GA) », « 6.4.5 b1828 ».
        $version = (string) preg_replace('/(\s*,.*|\s+(build|b)\s*\d.*)$/i', '', $version);
        // Préfixe « v » ou « V » collé au premier chiffre.
        $version = (string) preg_replace('/^v(?=\d)/i', '', trim($version));

        return trim($version);
    }

    /**
     * @param array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * } $affectedCpe
     */
    public static function evaluate(array $affectedCpe, string $installedVersion): string
    {
        $version = self::normalize($installedVersion);
        if (preg_match('/^\d/', $version) !== 1) {
            return CpeVersionMatch::UNKNOWN;
        }

        $hasExplicitBound = $affectedCpe['version_start_including'] !== null
            || $affectedCpe['version_start_excluding'] !== null
            || $affectedCpe['version_end_including'] !== null
            || $affectedCpe['version_end_excluding'] !== null;
        $cpeVersion = self::cpeVersionOf($affectedCpe['cpe23_uri']);

        if (preg_match(self::NUMERIC_PATTERN, $version) === 1) {
            if (!$hasExplicitBound && $cpeVersion !== null && preg_match(self::NUMERIC_PATTERN, $cpeVersion) !== 1) {
                // CPE « 15.2(7)e3 » face à une version numérique : jamais la même version.
                return CpeVersionMatch::EXCLUDED;
            }

            return CpeVersionMatch::evaluate($affectedCpe, $version);
        }

        if ($hasExplicitBound || $cpeVersion === null) {
            return CpeVersionMatch::UNKNOWN;
        }

        return strcasecmp($version, $cpeVersion) === 0 ? CpeVersionMatch::MATCHED : CpeVersionMatch::EXCLUDED;
    }

    /**
     * Champ version du CPE 2.3 (6e champ), échappements retirés (`15.2\(7\)e3` : `15.2(7)e3`) ;
     * null pour `*`, `-` ou absent.
     */
    private static function cpeVersionOf(string $cpe23Uri): ?string
    {
        $fields = preg_split('/(?<!\\\\):/', $cpe23Uri);
        $version = is_array($fields) ? ($fields[5] ?? null) : null;
        if ($version === null || $version === '*' || $version === '-' || $version === '') {
            return null;
        }

        return (string) preg_replace('/\\\\(.)/', '$1', $version);
    }
}
