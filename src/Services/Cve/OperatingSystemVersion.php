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

use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;
use GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher;

/**
 * Issue #780 : systèmes d'exploitation du parc (`Item_OperatingSystem`) face aux CVE. PHP pur,
 * sans GLPI.
 *
 * La famille d'un système (Windows, Windows Server, Debian, Ubuntu, RHEL et ses dérivés, Fedora,
 * macOS) est reconnue par la règle de fin de support (#704, ProductMatcher) : un nom entier connu,
 * jamais une ressemblance.
 * Elle sert seulement à PROPOSER un CPE et à lire la bonne version ; le rapprochement lui-même
 * passe toujours par un alias déclaré (ProductAlias), validé par l'administrateur.
 *
 * - designation() : nom rapproché par alias. C'est le nom du système dans GLPI, plus la version
 *   de fonctionnalités pour Windows 10 et 11 (« Microsoft Windows 11 Pro 23H2 ») : NVD décrit
 *   chacune par son propre produit (`windows_11_23h2`), la version de GLPI (« 23H2 ») doit donc
 *   faire partie de l'identité.
 * - release() : version comparée aux CVE, sous la forme que NVD emploie pour cette famille :
 *   numéro de build pour Windows (version du noyau, « 10.0.22631.4317 »), « 12.0 » pour Debian 12,
 *   « 9.0 » pour RHEL 9, « 22.04 » pour Ubuntu 22.04 ; lot 2 : « 9.3 » pour Rocky Linux et
 *   AlmaLinux 9.3, « 9 » pour Oracle Linux 9, « 40 » pour Fedora 40, « 14.4.1 » pour macOS.
 * - evaluate() : un build sans révision (« 10.0.22631 ») face à une borne qui en a une
 *   (« 10.0.22631.4317 ») reste « version non vérifiable », jamais « touché » par supposition.
 */
final class OperatingSystemVersion
{
    /**
     * CPE proposés par défaut, par famille reconnue (#704). Lot 2 : dérivés de RHEL, Fedora et
     * macOS, produits relevés dans le dictionnaire CPE de NVD.
     */
    private const DEFAULT_PREFIXES = [
        'debian'       => 'cpe:2.3:o:debian:debian_linux',
        'ubuntu'       => 'cpe:2.3:o:canonical:ubuntu_linux',
        'rhel'         => 'cpe:2.3:o:redhat:enterprise_linux',
        'rocky-linux'  => 'cpe:2.3:o:rockylinux:rocky_linux',
        'almalinux'    => 'cpe:2.3:o:almalinux:almalinux',
        'oracle-linux' => 'cpe:2.3:o:oracle:linux',
        'fedora'       => 'cpe:2.3:o:fedoraproject:fedora',
        'macos'        => 'cpe:2.3:o:apple:macos',
    ];

    /** Familles que NVD décrit par la version majeure suivie de « .0 » (« 12.0 », « 9.0 »). */
    private const MAJOR_DOT_ZERO = ['debian', 'rhel'];

    /** Familles que NVD décrit par la version majeure seule (Oracle Linux « 9 », Fedora « 40 »). */
    private const MAJOR_ONLY = ['oracle-linux', 'fedora'];

    /** Familles que NVD décrit par la version mineure (Rocky Linux et AlmaLinux « 9.3 »). */
    private const MAJOR_MINOR = ['rocky-linux', 'almalinux'];

    /** Windows Server dont NVD décrit la version par un produit `windows_server_<année>`. */
    private const WINDOWS_SERVER_YEARS = ['2016', '2019', '2022', '2025'];

    /** Version de fonctionnalités de Windows 10 et 11 : « 22H2 », « 1809 ». */
    private const FEATURE_RELEASE_PATTERN = '/^(\d{2}h\d|\d{4})$/i';

    /** Build Windows : « 10.0.22631 », avec ou sans révision (« 10.0.22631.4317 »). */
    private const BUILD_PATTERN = '/^\d+\.\d+\.\d+(\.\d+)?$/';

    /**
     * Famille reconnue par la fin de support (#704) : « windows », « windows-server »,
     * « debian »... ; chaîne vide pour un nom inconnu.
     */
    public static function family(string $osName): string
    {
        return ProductMatcher::automatic(ProductMatcher::KIND_OS, $osName);
    }

    /**
     * Version de fonctionnalités de Windows (« 23h2 »), chaîne vide si la version n'en est pas une.
     */
    public static function featureRelease(string $osVersion): string
    {
        $token = strtolower((string) (preg_split('/[\s(]+/', trim($osVersion), -1, PREG_SPLIT_NO_EMPTY)[0] ?? ''));

        return preg_match(self::FEATURE_RELEASE_PATTERN, $token) === 1 ? $token : '';
    }

    /**
     * Nom du système rapproché par alias.
     */
    public static function designation(string $osName, string $osVersion): string
    {
        $osName = trim($osName);
        if (self::family($osName) !== 'windows') {
            return $osName;
        }
        $release = self::featureRelease($osVersion);

        return $release === '' ? $osName : $osName . ' ' . strtoupper($release);
    }

    /**
     * CPE proposé par défaut pour une désignation (designation()), null si la famille n'est pas
     * couverte : l'administrateur le valide ou en saisit un autre.
     */
    public static function proposedPrefix(string $designation): ?string
    {
        $family = self::family($designation);
        if (isset(self::DEFAULT_PREFIXES[$family])) {
            return self::DEFAULT_PREFIXES[$family];
        }
        $number = CycleMatcher::nameNumber($designation);
        if ($family === 'windows-server') {
            return in_array($number, self::WINDOWS_SERVER_YEARS, true)
                ? 'cpe:2.3:o:microsoft:windows_server_' . $number
                : null;
        }
        if ($family === 'windows' && in_array($number, ['10', '11'], true)) {
            $words = preg_split('/\s+/', trim($designation)) ?: [];
            $release = self::featureRelease((string) end($words));

            return $release === '' ? null : 'cpe:2.3:o:microsoft:windows_' . $number . '_' . $release;
        }

        return null;
    }

    /**
     * Version comparée aux CVE, chaîne vide si elle ne peut pas être lue.
     */
    public static function release(string $osName, string $osVersion, string $kernelVersion): string
    {
        $family = self::family($osName);
        if ($family === 'windows' || $family === 'windows-server') {
            $build = trim($kernelVersion);

            return preg_match(self::BUILD_PATTERN, $build) === 1 ? $build : '';
        }
        $numbers = self::leadingNumbers($osVersion);
        if ($numbers === [] && preg_match('/(?<![\w.])(\d{1,2}(\.\d{1,2})?)(?![\w])/', $osName, $m) === 1) {
            // Version absente : numéro porté par le nom complet (« Debian GNU/Linux 12 (bookworm) »).
            $numbers = self::leadingNumbers($m[1]);
        }
        if ($numbers === []) {
            return '';
        }

        if ($family === 'ubuntu') {
            return count($numbers) >= 2 ? $numbers[0] . '.' . str_pad($numbers[1], 2, '0', STR_PAD_LEFT) : '';
        }

        if (in_array($family, self::MAJOR_MINOR, true)) {
            // Version mineure inconnue (« 9 ») : non vérifiable plutôt que supposée « 9.0 ».
            return count($numbers) >= 2 ? $numbers[0] . '.' . $numbers[1] : '';
        }
        if (in_array($family, self::MAJOR_ONLY, true)) {
            return $numbers[0];
        }

        return in_array($family, self::MAJOR_DOT_ZERO, true) ? $numbers[0] . '.0' : implode('.', $numbers);
    }

    /**
     * Un build Windows sans révision ne s'interroge pas auprès de NVD : toutes les CVE corrigées
     * depuis la sortie de ce build sortiraient, la machine reste « à vérifier ».
     */
    public static function isQueryable(string $prefix, string $version): bool
    {
        if (!str_starts_with($prefix, 'cpe:2.3:o:microsoft:windows')) {
            return true;
        }

        return preg_match('/^\d+\.\d+\.\d+\.\d+$/', trim($version)) === 1;
    }

    /**
     * @param array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * } $affectedCpe
     */
    public static function evaluate(array $affectedCpe, string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return CpeVersionMatch::UNKNOWN;
        }
        foreach (
            [
                $affectedCpe['version_start_including'], $affectedCpe['version_start_excluding'],
                $affectedCpe['version_end_including'], $affectedCpe['version_end_excluding'],
            ] as $bound
        ) {
            // « 10.0.22631 » face à « 10.0.22631.4317 » : même build, révision inconnue.
            if ($bound !== null && str_starts_with(trim($bound), $version . '.')) {
                return CpeVersionMatch::UNKNOWN;
            }
        }

        return CpeVersionMatch::evaluate($affectedCpe, $version);
    }

    /**
     * Nombres en tête d'une version (« 22.04.4 LTS » : 22, 4, 4), zéros de tête retirés.
     *
     * @return list<string>
     */
    private static function leadingNumbers(string $version): array
    {
        if (preg_match('/^v?(\d+(?:\.\d+)*)/i', trim($version), $m) !== 1) {
            return [];
        }

        return array_map(static fn (string $n): string => (string) (int) $n, explode('.', $m[1]));
    }
}
