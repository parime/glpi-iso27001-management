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

use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;

/**
 * Issue #843 : règles des contrôleurs de gestion des serveurs (HPE iLO, Dell iDRAC, Lenovo
 * XClarity Controller, Supermicro IPMI/BMC), sans GLPI ni réseau : adresse acceptée, écarts de
 * configuration signalés, comptes par défaut, appartenance aux réseaux d'administration, lecture
 * de l'import CSV des contrôleurs sans Redfish.
 *
 * Rien n'est jamais corrigé : les écarts sont des signalements.
 */
final class BmcRules
{
    // Écarts signalés (codes stables, libellés traduits par la page).
    public const GAP_NOT_READ = 'not_read';
    public const GAP_READ_FAILED = 'read_failed';
    public const GAP_IPMI_LAN = 'ipmi_lan';
    public const GAP_TELNET = 'telnet';
    public const GAP_SNMP_V1V2 = 'snmp_v1v2';
    public const GAP_WEAK_TLS = 'weak_tls';
    public const GAP_TLS_NOT_VERIFIED = 'tls_not_verified';
    public const GAP_CERT_SELF_SIGNED = 'cert_self_signed';
    public const GAP_CERT_EXPIRED = 'cert_expired';
    public const GAP_CERT_EXPIRING = 'cert_expiring';
    public const GAP_SECURE_BOOT_OFF = 'secure_boot_off';
    public const GAP_DEFAULT_ACCOUNT = 'default_account';
    public const GAP_OUTSIDE_ADMIN_NETWORK = 'outside_admin_network';
    public const GAP_HEALTH_WARNING = 'health_warning';
    public const GAP_HEALTH_CRITICAL = 'health_critical';
    public const GAP_NO_SERVER = 'no_server';

    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Gravité de chaque écart, dans l'ordre d'affichage. */
    public const GAPS = [
        self::GAP_READ_FAILED           => self::SEVERITY_MEDIUM,
        self::GAP_NOT_READ              => self::SEVERITY_LOW,
        self::GAP_IPMI_LAN              => self::SEVERITY_HIGH,
        self::GAP_DEFAULT_ACCOUNT       => self::SEVERITY_HIGH,
        self::GAP_OUTSIDE_ADMIN_NETWORK => self::SEVERITY_HIGH,
        self::GAP_TELNET                => self::SEVERITY_HIGH,
        self::GAP_WEAK_TLS              => self::SEVERITY_HIGH,
        self::GAP_CERT_EXPIRED          => self::SEVERITY_HIGH,
        self::GAP_HEALTH_CRITICAL       => self::SEVERITY_HIGH,
        self::GAP_SNMP_V1V2             => self::SEVERITY_MEDIUM,
        self::GAP_SECURE_BOOT_OFF       => self::SEVERITY_MEDIUM,
        self::GAP_CERT_SELF_SIGNED      => self::SEVERITY_MEDIUM,
        self::GAP_TLS_NOT_VERIFIED      => self::SEVERITY_MEDIUM,
        self::GAP_HEALTH_WARNING        => self::SEVERITY_MEDIUM,
        self::GAP_CERT_EXPIRING         => self::SEVERITY_LOW,
        self::GAP_NO_SERVER             => self::SEVERITY_LOW,
    ];

    /** Certificat signalé comme proche de son expiration (jours). */
    public const CERT_EXPIRING_DAYS = 30;

    /**
     * Comptes livrés par les constructeurs (Dell « root », HPE « Administrator », Supermicro
     * « ADMIN », Lenovo « USERID », « admin » générique), en minuscules.
     */
    public const DEFAULT_ACCOUNTS = ['root', 'administrator', 'admin', 'userid'];

    /** Valeur à trois états : inconnu (non lu), non, oui. */
    public const UNKNOWN = -1;

    public const SOURCE_REDFISH = 'redfish';
    public const SOURCE_CSV = 'csv';

    public const HEALTH_OK = 'OK';
    public const HEALTH_WARNING = 'Warning';
    public const HEALTH_CRITICAL = 'Critical';

    /** Import CSV : taille et nombre de lignes au plus. */
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_CSV_ROWS = 2000;

    /** Colonnes de l'import CSV et leurs en-têtes acceptés (normalisés). */
    public const CSV_FIELDS = [
        'name'        => ['name', 'nom', 'controller', 'controleur', 'bmc', 'host', 'hostname'],
        'address'     => ['address', 'adresse', 'ip', 'ipaddress', 'adresseip', 'url'],
        'vendor'      => ['vendor', 'constructeur', 'manufacturer', 'fabricant'],
        'model'       => ['model', 'modele', 'controllermodel'],
        'firmware'    => ['firmware', 'firmwareversion', 'versionfirmware', 'micrologiciel'],
        'bios'        => ['bios', 'biosversion', 'versionbios'],
        'serial'      => ['serial', 'serialnumber', 'numerodeserie', 'serie', 'servicetag'],
        'ipmi_lan'    => ['ipmilan', 'ipmioverlan', 'ipmi', 'ipmisurlereseau'],
        'telnet'      => ['telnet'],
        'snmp_v1v2'   => ['snmpv1v2', 'snmpv1v2c', 'snmpv1', 'snmpv2c'],
        'weak_tls'    => ['weaktls', 'tls10', 'tls1011', 'oldtls', 'tlsancien', 'protocolesanciens', 'sslv3'],
        'secure_boot' => ['secureboot', 'demarragesecurise'],
        'accounts'    => ['accounts', 'comptes', 'users', 'utilisateurs'],
    ];

    /**
     * Adresse d'un contrôleur : https seulement (le mot de passe part dans chaque requête), un nom
     * ou une adresse IP et un port facultatif, sans chemin, paramètre ni identifiant. Renvoie
     * « https://hôte[:port] », ou null si l'adresse est refusée. Une adresse IP seule est acceptée.
     */
    public static function normalizeUrl(string $url, bool $allowPrivate): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/' && rtrim($path, '/') !== '/redfish/v1') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if (isset($parts['port']) && ($port < 1 || $port > 65535)) {
            return null;
        }
        $normalized = 'https://' . $host . ($port > 0 && $port !== 443 ? ':' . $port : '');
        if (Http::checkUrl($normalized, false, $allowPrivate) !== null) {
            return null;
        }

        return $normalized;
    }

    /** Hôte d'une adresse normalisée (sans crochets IPv6). */
    public static function hostOf(string $url): string
    {
        return strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    }

    /**
     * Chemin Redfish renvoyé par le contrôleur (« @odata.id ») accepté pour un appel : un chemin
     * absolu sous /redfish/v1, sans hôte, paramètre, fragment ni remontée. Ainsi, seule l'adresse
     * configurée est appelée, quoi que réponde le contrôleur.
     */
    public static function safePath(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }
        $path = trim($path);
        if (
            preg_match('#^/redfish/v1(/[A-Za-z0-9._~%:@!$&\'()*+,;=-]*)*/?$#', $path) !== 1
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_contains($path, '%2')
        ) {
            return null;
        }

        return $path;
    }

    /** Machine virtuelle d'après son modèle ou son fabricant dans GLPI (pas de contrôleur attendu). */
    public static function isVirtual(string $model, string $manufacturer): bool
    {
        $pattern = '/virtual|vmware|kvm|qemu|hyper-v|xen|bochs|proxmox|parallels|innotek|openstack|nutanix|ovirt/i';

        return preg_match($pattern, $model . ' ' . $manufacturer) === 1;
    }

    /** Compte livré par le constructeur (insensible à la casse). */
    public static function isDefaultAccount(string $username): bool
    {
        return in_array(mb_strtolower(trim($username)), self::DEFAULT_ACCOUNTS, true);
    }

    /** Rôle Redfish d'administration (« Administrator », ou un rôle d'un constructeur qui le dit). */
    public static function isAdminRole(string $role): bool
    {
        return preg_match('/admin/i', $role) === 1;
    }

    /**
     * Constructeur d'après le champ « Vendor » de Redfish ou, à défaut, la rubrique Oem.
     *
     * @param array<mixed> $root réponse de /redfish/v1
     */
    public static function vendorOf(array $root): string
    {
        $vendor = trim((string) ($root['Vendor'] ?? ''));
        $oem = is_array($root['Oem'] ?? null) ? array_keys($root['Oem']) : [];
        $known = ['hpe' => 'HPE', 'hp' => 'HPE', 'dell' => 'Dell', 'lenovo' => 'Lenovo', 'supermicro' => 'Supermicro'];
        foreach ([$vendor, ...array_map('strval', $oem)] as $candidate) {
            $key = mb_strtolower(trim($candidate));
            if (isset($known[$key])) {
                return $known[$key];
            }
        }

        return mb_substr($vendor, 0, 64);
    }

    /** État de santé Redfish ramené à OK, Warning, Critical ou '' (inconnu). */
    public static function normalizeHealth(mixed $health): string
    {
        $value = mb_strtolower(trim(is_string($health) ? $health : ''));

        return match ($value) {
            'ok'       => self::HEALTH_OK,
            'warning'  => self::HEALTH_WARNING,
            'critical' => self::HEALTH_CRITICAL,
            default    => '',
        };
    }

    /** Le plus mauvais de deux états de santé. */
    public static function worstHealth(string $a, string $b): string
    {
        $rank = ['' => 0, self::HEALTH_OK => 1, self::HEALTH_WARNING => 2, self::HEALTH_CRITICAL => 3];

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    /** Booléen Redfish ou CSV vers -1 (inconnu), 0, 1. */
    public static function tristate(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (!is_string($value) && !is_int($value)) {
            return self::UNKNOWN;
        }
        $text = mb_strtolower(trim((string) $value));
        $yes = ['1', 'true', 'yes', 'y', 'oui', 'o', 'x', 'vrai', 'enabled', 'on', 'active', 'actif'];
        if (in_array($text, $yes, true)) {
            return 1;
        }
        if (in_array($text, ['0', 'false', 'no', 'n', 'non', 'faux', 'disabled', 'off', 'inactive', 'inactif'], true)) {
            return 0;
        }

        return self::UNKNOWN;
    }

    /**
     * Certificat auto-signé : émetteur identique au sujet (Redfish « Issuer » et « Subject »).
     *
     * @param array<mixed> $certificate
     */
    public static function isSelfSigned(array $certificate): bool
    {
        $issuer = is_array($certificate['Issuer'] ?? null) ? $certificate['Issuer'] : [];
        $subject = is_array($certificate['Subject'] ?? null) ? $certificate['Subject'] : [];
        if ($issuer === [] || $subject === []) {
            return false;
        }
        $normalize = static function (array $name): array {
            $out = [];
            foreach ($name as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $out[(string) $key] = mb_strtolower(trim((string) $value));
                }
            }
            ksort($out);

            return $out;
        };

        return $normalize($issuer) === $normalize($subject);
    }

    /** Date ISO 8601 (Redfish) ramenée à AAAA-MM-JJ, ou null. */
    public static function dateOf(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate('Y-m-d', $time);
    }

    /**
     * Réseaux d'administration déclarés dans la matrice des flux : adresses et plages écrites dans
     * une source ou une destination dont le nom désigne une zone d'administration (MGMT, OOB,
     * iLO, iDRAC, bastion...).
     *
     * @param list<array{source_label?: string, destination_label?: string}> $flows
     * @return list<array{0: int, 1: int}>
     */
    public static function adminNetworks(array $flows): array
    {
        $networks = [];
        foreach ($flows as $flow) {
            foreach (['source_label', 'destination_label'] as $side) {
                $label = (string) ($flow[$side] ?? '');
                if ($label !== '' && preg_match(NetworkFlowRules::ADMIN_ZONE_PATTERN, $label) === 1) {
                    foreach (NetworkFlowRules::extractNetworks($label) as $network) {
                        $networks[$network[0] . '/' . $network[1]] = $network;
                    }
                }
            }
        }

        return array_values($networks);
    }

    /**
     * L'adresse IPv4 est-elle dans un des réseaux d'administration ? null si on ne peut pas le
     * dire (aucun réseau déclaré, adresse inconnue ou IPv6).
     *
     * @param list<array{0: int, 1: int}> $networks
     */
    public static function inAdminNetwork(string $ip, array $networks): ?bool
    {
        if ($networks === [] || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $address = ip2long($ip);
        if ($address === false) {
            return null;
        }
        foreach ($networks as $network) {
            if (NetworkFlowRules::networksOverlap([$address, 32], $network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Écarts d'un contrôleur, dans l'ordre de GAPS. $accounts : comptes lus (username, enabled).
     *
     * @param array<string, mixed> $controller ligne de la table (valeurs lues)
     * @param list<array{username: string, enabled: int}> $accounts
     * @return list<string>
     */
    public static function gaps(
        array $controller,
        array $accounts,
        ?bool $inAdminNetwork,
        bool $hasServer,
        string $today
    ): array {
        $found = [];
        $read = (string) ($controller['date_read'] ?? '') !== '';
        if (!$read) {
            $found[self::GAP_NOT_READ] = true;
        }
        if ((string) ($controller['read_error'] ?? '') !== '') {
            $found[self::GAP_READ_FAILED] = true;
        }
        $flags = [
            'ipmi_lan'    => self::GAP_IPMI_LAN,
            'telnet'      => self::GAP_TELNET,
            'snmp_v1v2'   => self::GAP_SNMP_V1V2,
            'weak_tls'    => self::GAP_WEAK_TLS,
        ];
        foreach ($flags as $field => $gap) {
            if ((int) ($controller[$field] ?? self::UNKNOWN) === 1) {
                $found[$gap] = true;
            }
        }
        if ((int) ($controller['secure_boot'] ?? self::UNKNOWN) === 0) {
            $found[self::GAP_SECURE_BOOT_OFF] = true;
        }
        $redfish = (string) ($controller['source'] ?? '') === self::SOURCE_REDFISH;
        if ($read && $redfish && (int) ($controller['tls_verified'] ?? 1) === 0) {
            $found[self::GAP_TLS_NOT_VERIFIED] = true;
        }
        if ((int) ($controller['cert_self_signed'] ?? self::UNKNOWN) === 1) {
            $found[self::GAP_CERT_SELF_SIGNED] = true;
        }
        $expiry = (string) ($controller['cert_expiry'] ?? '');
        if ($expiry !== '' && $today !== '') {
            if ($expiry < $today) {
                $found[self::GAP_CERT_EXPIRED] = true;
            } elseif ($expiry <= gmdate('Y-m-d', (int) strtotime($today . ' +' . self::CERT_EXPIRING_DAYS . ' days'))) {
                $found[self::GAP_CERT_EXPIRING] = true;
            }
        }
        foreach ($accounts as $account) {
            if ((int) $account['enabled'] !== 0 && self::isDefaultAccount((string) $account['username'])) {
                $found[self::GAP_DEFAULT_ACCOUNT] = true;
                break;
            }
        }
        if ($inAdminNetwork === false) {
            $found[self::GAP_OUTSIDE_ADMIN_NETWORK] = true;
        }
        $health = (string) ($controller['health'] ?? '');
        if ($health === self::HEALTH_CRITICAL) {
            $found[self::GAP_HEALTH_CRITICAL] = true;
        } elseif ($health === self::HEALTH_WARNING) {
            $found[self::GAP_HEALTH_WARNING] = true;
        }
        if ($read && !$hasServer) {
            $found[self::GAP_NO_SERVER] = true;
        }

        return array_values(array_filter(array_keys(self::GAPS), static fn (string $gap): bool => isset($found[$gap])));
    }

    /**
     * Le contrôleur a-t-il un écart de gravité haute ?
     *
     * @param list<string> $gaps
     */
    public static function hasHighGap(array $gaps): bool
    {
        foreach ($gaps as $gap) {
            if ((self::GAPS[$gap] ?? '') === self::SEVERITY_HIGH) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comptes d'une cellule CSV : « root:Administrator; operateur:Operator; ancien:ReadOnly:disabled ».
     *
     * @return list<array{username: string, role: string, enabled: int, locked: int}>
     */
    public static function parseAccounts(string $cell): array
    {
        $accounts = [];
        foreach (preg_split('/[;|\n]+/', $cell) ?: [] as $chunk) {
            $parts = array_map('trim', explode(':', $chunk));
            $username = mb_substr($parts[0] ?? '', 0, 255);
            if ($username === '') {
                continue;
            }
            $enabled = isset($parts[2]) ? self::tristate($parts[2]) : 1;
            $accounts[mb_strtolower($username)] = [
                'username' => $username,
                'role'     => mb_substr($parts[1] ?? '', 0, 64),
                'enabled'  => $enabled === 0 ? 0 : 1,
                'locked'   => 0,
            ];
        }

        return array_values($accounts);
    }

    /**
     * Import CSV des contrôleurs lus autrement (IPMI sans Redfish, export d'un outil constructeur).
     * Colonnes reconnues par leur en-tête (CSV_FIELDS) ; « name » obligatoire.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, truncated: bool}
     */
    public static function parseCsv(string $raw): array
    {
        if (trim($raw) === '') {
            return ['rows' => [], 'errors' => ['empty'], 'truncated' => false];
        }
        $parsed = EdrCsvImport::parse($raw);
        $mapping = [];
        foreach (self::CSV_FIELDS as $field => $aliases) {
            foreach ($parsed['headers'] as $index => $header) {
                $known = in_array(EdrCsvImport::normalizeHeader($header), $aliases, true);
                if (!in_array($index, $mapping, true) && $known) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }
        if (!isset($mapping['name'])) {
            return ['rows' => [], 'errors' => ['missing_name'], 'truncated' => false];
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $rows = [];
        $truncated = $parsed['truncated'];
        foreach ($parsed['rows'] as $row) {
            $name = mb_substr($get($row, 'name'), 0, 255);
            if ($name === '') {
                continue;
            }
            if (count($rows) >= self::MAX_CSV_ROWS) {
                $truncated = true;
                break;
            }
            $address = mb_substr($get($row, 'address'), 0, 255);
            // Comptes en dernière colonne : leurs « ; » coupent la ligne quand « ; » sépare aussi les
            // colonnes ; les morceaux en trop leur sont rendus.
            $accountsCell = $get($row, 'accounts');
            if (isset($mapping['accounts']) && $mapping['accounts'] === count($parsed['headers']) - 1) {
                $accountsCell = implode(';', array_slice($row, $mapping['accounts']));
            }
            $rows[mb_strtolower($name)] = [
                'name'        => $name,
                'address'     => $address,
                'ip_address'  => filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '',
                'vendor'      => mb_substr($get($row, 'vendor'), 0, 64),
                'manager_model' => mb_substr($get($row, 'model'), 0, 255),
                'firmware_version' => mb_substr($get($row, 'firmware'), 0, 255),
                'bios_version' => mb_substr($get($row, 'bios'), 0, 255),
                'serial'      => mb_substr($get($row, 'serial'), 0, 255),
                'ipmi_lan'    => self::tristate($get($row, 'ipmi_lan')),
                'telnet'      => self::tristate($get($row, 'telnet')),
                'snmp_v1v2'   => self::tristate($get($row, 'snmp_v1v2')),
                'weak_tls'    => self::tristate($get($row, 'weak_tls')),
                'secure_boot' => self::tristate($get($row, 'secure_boot')),
                'accounts'    => self::parseAccounts($accountsCell),
            ];
        }

        return ['rows' => array_values($rows), 'errors' => [], 'truncated' => $truncated];
    }
}
