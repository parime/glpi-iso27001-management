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

/**
 * Issue #843, lot 4 (corrigé par l'issue #1085) : protocoles TLS anciens (SSLv3, TLS 1.0, TLS 1.1) acceptés par l'interface
 * HTTPS du contrôleur, lus en LECTURE SEULE.
 *
 * Redfish standard ne décrit ces réglages que dans la ressource récente « SecurityPolicy » ; les
 * constructeurs les exposent dans leurs extensions « Oem » :
 * - HPE iLO 5/6/7 : service de sécurité (Oem.Hpe.Links.SecurityService), objet « TLSVersion »
 *   (TLS1_0, TLS1_1… activé ou non ; iLO 7 a retiré TLS1_0 et TLS1_1) et mode de sécurité
 *   (iLO 5/6 HighSecurity, iLO 7 SecureStandard, FIPS, CNSA : TLS 1.2 et plus seulement) ;
 * - Dell iDRAC 8/9 : attributs du contrôleur (Links.Oem.Dell.DellAttributes), réglage
 *   « WebServer.1.TLSProtocol » (« TLS 1.1 and Higher », « TLS 1.2 Only »…) ;
 * - Lenovo XClarity Controller, Supermicro et les autres : réglages TLS trouvés dans la rubrique
 *   « Oem » du contrôleur, de ses protocoles réseau ou du service de sécurité lié.
 *
 * Mêmes GET bornés que le reste du client (RedfishClient::get()) : quelques ressources au plus,
 * chemins sous /redfish/v1 seulement. Résultat à trois états : 1 = un protocole ancien est
 * accepté, 0 = TLS 1.2 et plus seulement, BmcRules::UNKNOWN = rien de lisible (l'import CSV reste
 * alors la seule source). Aucun réglage n'est jamais modifié.
 */
final class RedfishTlsReader
{
    /** Ressources liées lues au plus (politique de sécurité, service de sécurité, attributs). */
    public const MAX_LINKS = 3;

    /** Parcours borné d'une ressource (profondeur et nombre de valeurs examinées). */
    private const MAX_DEPTH = 8;
    private const MAX_NODES = 20000;

    /**
     * Modes de sécurité HPE qui n'acceptent que TLS 1.2 et plus (minuscules, sans séparateur).
     * HPE : « TLS versions 1.0 and 1.1 can be enabled or disabled only in Production mode » ; iLO 7
     * remplace HighSecurity par SecureStandard, son équivalent et le mode par défaut
     * (https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/supplementdocuments/securityservice,
     * https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo7/ilo7_adaptation).
     */
    private const HPE_STRICT_MODES = [
        'highsecurity', 'securestandard', 'fips', 'cnsa', 'suiteb', 'synergysecuritymode',
    ];

    public function __construct(private readonly RedfishClient $client)
    {
    }

    /**
     * Protocoles anciens acceptés par le contrôleur, d'après sa ressource Manager, ses protocoles
     * réseau et au plus MAX_LINKS ressources liées.
     *
     * @param array<mixed> $manager ressource Manager déjà lue
     * @param array<mixed> $protocol ressource ManagerNetworkProtocol déjà lue ([] si absente)
     */
    public function read(array $manager, array $protocol): int
    {
        $evidence = new TlsEvidence();
        self::scan($manager['Oem'] ?? null, $evidence);
        self::scan($protocol['Oem'] ?? null, $evidence);

        foreach (array_slice(self::links($manager), 0, self::MAX_LINKS) as $kind => $path) {
            $answer = $this->client->get($path);
            if (!is_array($answer['data'])) {
                continue;
            }
            if ($kind === 'policy') {
                self::readSecurityPolicy($answer['data'], $evidence);
            } else {
                self::scan($answer['data'], $evidence);
            }
        }

        return $evidence->result();
    }

    /**
     * Ressources liées utiles, par ordre de préférence : politique de sécurité DMTF, service de
     * sécurité HPE, attributs Dell du contrôleur, sécurité Lenovo.
     *
     * @param array<mixed> $manager
     * @return array<string, string>
     */
    public static function links(array $manager): array
    {
        $links = [];
        $policy = self::odataId($manager['SecurityPolicy'] ?? null);
        if ($policy !== null) {
            $links['policy'] = $policy;
        }
        $oem = is_array($manager['Oem'] ?? null) ? $manager['Oem'] : [];
        foreach (['Hpe', 'Hp'] as $vendor) {
            $hpe = is_array($oem[$vendor] ?? null) ? $oem[$vendor] : [];
            $service = self::odataId($hpe['Links']['SecurityService'] ?? null);
            if ($service !== null) {
                $links['hpe'] = $service;
                break;
            }
        }
        $managerLinks = is_array($manager['Links'] ?? null) ? $manager['Links'] : [];
        $dell = $managerLinks['Oem']['Dell']['DellAttributes'] ?? null;
        if (is_array($dell)) {
            // Une liste de liens : celle de l'iDRAC lui-même d'abord (pas celle du système).
            $candidates = array_is_list($dell) ? $dell : [$dell];
            $chosen = null;
            foreach ($candidates as $candidate) {
                $path = self::odataId($candidate);
                if ($path === null) {
                    continue;
                }
                $chosen ??= $path;
                if (stripos($path, 'idrac') !== false) {
                    $chosen = $path;
                    break;
                }
            }
            if ($chosen !== null) {
                $links['dell'] = $chosen;
            }
        }
        $lenovo = is_array($oem['Lenovo'] ?? null) ? $oem['Lenovo'] : [];
        $security = self::odataId($lenovo['Security'] ?? null);
        if ($security !== null) {
            $links['lenovo'] = $security;
        }

        return $links;
    }

    /**
     * Politique de sécurité DMTF (SecurityPolicy) : versions TLS acceptées par le service HTTPS.
     *
     * Schéma SecurityPolicy v1_0 (https://redfish.dmtf.org/schemas/v1/SecurityPolicy.v1_0_3.json) :
     * TLS (TLSCommunication) > Server (TLSPolicy) > Allowed et Denied (TLSParameterSet) > Versions,
     * tableau de « 1.2 », « ALL » ou « NONE » ; un tableau vide ou « ALL » désigne toutes les
     * versions, une version refusée l'emporte sur la même version autorisée, une version absente
     * des deux listes est refusée (Allowed absent : rien à conclure).
     *
     * @param array<mixed> $policy
     */
    public static function readSecurityPolicy(array $policy, TlsEvidence $evidence): void
    {
        $server = is_array($policy['TLS']['Server'] ?? null) ? $policy['TLS']['Server'] : [];
        $allowed = self::policyVersions($server['Allowed']['Versions'] ?? null);
        if ($allowed === null || $allowed === []) {
            return;
        }
        $denied = self::policyVersions($server['Denied']['Versions'] ?? null) ?? [];
        // Un refus « toutes versions » (tableau vide ou ALL) ne laisse rien d'autorisé.
        $effective = array_diff($allowed, $denied);
        if ($effective === []) {
            return;
        }
        $evidence->setting(min($effective) < 2);
    }

    /**
     * Versions mineures de TLS 1.x d'une liste Versions de la politique (0 à 3), toutes pour
     * « ALL » ou une liste vide, aucune pour « NONE » ; null si la propriété est absente ou illisible.
     *
     * @return list<int>|null
     */
    public static function policyVersions(mixed $versions): ?array
    {
        if (!is_array($versions)) {
            return null;
        }
        $all = [0, 1, 2, 3];
        if ($versions === []) {
            return $all;
        }
        $out = [];
        foreach ($versions as $version) {
            $text = is_string($version) ? strtoupper(trim($version)) : '';
            if ($text === 'ALL') {
                return $all;
            }
            if ($text === 'NONE') {
                continue;
            }
            if (preg_match('/^1\.([0-3])$/', $text, $m) === 1) {
                $out[] = (int) $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Parcours d'une ressource (ou d'une rubrique Oem) à la recherche des réglages TLS.
     */
    public static function scan(mixed $node, TlsEvidence $evidence, int $depth = 0): void
    {
        if (!is_array($node) || $depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($node as $key => $value) {
            if (!$evidence->visit(self::MAX_NODES)) {
                return;
            }
            $name = self::normalizeKey((string) $key);
            if ($name === '' || str_starts_with((string) $key, '@')) {
                continue;
            }
            $toggle = self::toggleOf($name);
            if ($toggle !== null) {
                $state = BmcRules::tristate($value);
                if ($state !== BmcRules::UNKNOWN) {
                    $evidence->toggle($toggle, $state === 1);
                    continue;
                }
            }
            if (self::isTlsSetting($name)) {
                $values = is_array($value) ? $value : [$value];
                $lowest = null;
                foreach ($values as $item) {
                    $minor = is_scalar($item) ? self::lowestMinor((string) $item, is_array($value)) : null;
                    if ($minor !== null && ($lowest === null || $minor < $lowest)) {
                        $lowest = $minor;
                    }
                }
                if ($lowest !== null) {
                    $evidence->setting($lowest < 2);
                    continue;
                }
            }
            if ($name === 'securitystate' && is_string($value)) {
                $mode = self::normalizeKey($value);
                if (in_array($mode, self::HPE_STRICT_MODES, true)) {
                    $evidence->strictMode();
                }
                continue;
            }
            if (is_array($value)) {
                self::scan($value, $evidence, $depth + 1);
            }
        }
    }

    /**
     * Interrupteur d'un protocole : « TLS1_0 », « TLSv1.1 », « EnableTLS10 », « SSLv3Enabled »…
     * Renvoie le protocole (« ssl3 », « tls10 », « tls11 », « tls12 », « tls13 ») ou null.
     */
    public static function toggleOf(string $name): ?string
    {
        $pattern = '/^(?:enable|enabled|allow)?(sslv?3|tlsv?1[0-3])(?:enabled|enable|support|supported|allowed)?$/';
        if (preg_match($pattern, $name, $m) !== 1) {
            return null;
        }

        return str_replace('v', '', $m[1]);
    }

    /** Réglage décrivant les versions TLS acceptées (« TLSProtocol », « MinimumTLSVersion »…). */
    public static function isTlsSetting(string $name): bool
    {
        // Le nom d'attribut Dell « WebServer.1.TLSProtocol » est ramené à son dernier segment.
        $setting = '/^(?:min|minimum|server|https|web)?(?:tls|ssl)(?:version|versions|protocol|protocols|mode|level)$/';

        return preg_match($setting, $name) === 1;
    }

    /**
     * Plus basse version mentionnée dans un texte : 0 (TLS 1.0, SSLv3 compté 0 aussi), 1, 2 ou 3 ;
     * null si aucune. $bare : « 1.2 » seul est accepté (liste de versions sans le mot TLS).
     */
    public static function lowestMinor(string $text, bool $bare = false): ?int
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }
        $lowest = null;
        if (preg_match('/ssl\s*v?\s*[23]\b/', $text) === 1) {
            $lowest = 0;
        }
        if (preg_match_all('/tls\s*v?\s*1(?:[._\s]?([0-3]))?(?![0-9])/', $text, $matches) > 0) {
            foreach ($matches[1] as $minor) {
                $value = $minor === '' ? 0 : (int) $minor;
                $lowest = $lowest === null ? $value : min($lowest, $value);
            }
        }
        if ($lowest === null && $bare && preg_match('/^v?1[._]([0-3])$/', $text, $m) === 1) {
            $lowest = (int) $m[1];
        }

        return $lowest;
    }

    /** Clé ramenée à son dernier segment (« WebServer.1.TLSProtocol »), minuscules, sans séparateur. */
    private static function normalizeKey(string $key): string
    {
        $segments = explode('.', $key);
        $last = (string) end($segments);
        // « TLS1.1 » coupé par le point : garder la clé entière quand le dernier segment est un chiffre.
        if (preg_match('/^[0-9]+$/', $last) === 1) {
            $last = $key;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($last));
    }

    private static function odataId(mixed $value): ?string
    {
        return BmcRules::safePath(is_array($value) ? ($value['@odata.id'] ?? null) : null);
    }
}
