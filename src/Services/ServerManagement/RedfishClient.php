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

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #843 : client Redfish (DMTF) en LECTURE SEULE d'un contrôleur de gestion de serveur
 * (iLO 4/5/6, iDRAC 8/9, XClarity Controller, Supermicro récents).
 *
 * - Requêtes GET uniquement : le transport ne reçoit même pas de méthode. Aucune session Redfish
 *   n'est ouverte (elle demanderait un POST) : authentification « Basic » à chaque requête, par
 *   un compte local en lecture seule du contrôleur.
 * - Une seule adresse appelée : celle du contrôleur configuré. Les liens « @odata.id » renvoyés
 *   ne sont suivis que s'ils sont des chemins sous /redfish/v1 (BmcRules::safePath()).
 * - Appels par Http::send() (#658 : proxy de GLPI, délai, redirections refusées, adresses internes
 *   seulement si l'administrateur les a autorisées). Certificat auto-signé accepté seulement si
 *   l'option est cochée pour CE contrôleur ($verifyTls = false).
 * - Le mot de passe n'apparaît jamais dans une adresse, un message d'erreur ni un journal.
 * - Nombre de requêtes borné (comptes, certificats, membres lus au plus).
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, array<string, string>, bool): HttpResult
 */
final class RedfishClient
{
    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_TLS = 'tls_untrusted';
    public const ERROR_NOT_REDFISH = 'not_redfish';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';

    public const MAX_ACCOUNTS = 64;
    public const MAX_CERTIFICATES = 5;
    public const MAX_REQUESTS = 120;
    public const MAX_BODY = 2 * 1024 * 1024;

    public const TIMEOUT = 20;

    /** @var Transport */
    private Closure $transport;

    private int $requests = 0;

    /**
     * @param string $baseUrl adresse normalisée (BmcRules::normalizeUrl()), « https://hôte[:port] »
     * @param Transport|null $transport transport HTTP GET (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly bool $verifyTls = true,
        ?Closure $transport = null
    ) {
        $this->transport = $transport ?? static function (string $url, array $headers, bool $verifyTls): array {
            $error = null;
            $response = Http::send('GET', $url, $headers, $error, self::TIMEOUT, null, $verifyTls);

            return $response + ['error' => (string) $error];
        };
    }

    /**
     * Un GET sur un chemin Redfish (refusé s'il n'est pas sous /redfish/v1).
     *
     * @return array{data: array<mixed>|null, status: int, error: string}
     */
    public function get(mixed $path): array
    {
        $path = BmcRules::safePath($path);
        if ($path === null) {
            return ['data' => null, 'status' => 0, 'error' => self::ERROR_INVALID];
        }
        if ($this->baseUrl === '' || $this->username === '') {
            return ['data' => null, 'status' => 0, 'error' => self::ERROR_CONFIG];
        }
        if (++$this->requests > self::MAX_REQUESTS) {
            return ['data' => null, 'status' => 0, 'error' => self::ERROR_INVALID];
        }
        $headers = [
            'Accept'        => 'application/json',
            'OData-Version' => '4.0',
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
        ];
        $result = ($this->transport)($this->baseUrl . $path, $headers, $this->verifyTls);
        $status = (int) $result['status'];
        if ($status === 0) {
            $transportError = mb_strtolower((string) ($result['error'] ?? ''));
            if (
                str_starts_with($transportError, Http::ERROR_INTERNAL)
                || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT)
            ) {
                return ['data' => null, 'status' => 0, 'error' => self::ERROR_INTERNAL];
            }
            if (preg_match('/ssl|certificate|tls|x509|self.signed/', $transportError) === 1) {
                return ['data' => null, 'status' => 0, 'error' => self::ERROR_TLS];
            }

            return ['data' => null, 'status' => 0, 'error' => self::ERROR_UNREACHABLE];
        }
        if ($status === 401) {
            return ['data' => null, 'status' => $status, 'error' => self::ERROR_UNAUTHORIZED];
        }
        if ($status === 403) {
            return ['data' => null, 'status' => $status, 'error' => self::ERROR_FORBIDDEN];
        }
        if ($status < 200 || $status >= 300) {
            return ['data' => null, 'status' => $status, 'error' => self::ERROR_STATUS];
        }
        $body = (string) $result['body'];
        $data = strlen($body) > self::MAX_BODY ? null : json_decode($body, true);
        if (!is_array($data)) {
            return ['data' => null, 'status' => $status, 'error' => self::ERROR_INVALID];
        }

        return ['data' => $data, 'status' => $status, 'error' => ''];
    }

    /**
     * Membres d'une collection Redfish (chemins acceptés seulement).
     *
     * @return array{members: list<string>, error: string}
     */
    private function members(mixed $path, int $max): array
    {
        $answer = $this->get($path);
        if ($answer['error'] !== '' || $answer['data'] === null) {
            return ['members' => [], 'error' => $answer['error'] !== '' ? $answer['error'] : self::ERROR_INVALID];
        }
        $members = [];
        foreach (is_array($answer['data']['Members'] ?? null) ? $answer['data']['Members'] : [] as $member) {
            $id = BmcRules::safePath(is_array($member) ? ($member['@odata.id'] ?? null) : null);
            if ($id !== null) {
                $members[] = $id;
            }
            if (count($members) >= $max) {
                break;
            }
        }

        return ['members' => $members, 'error' => ''];
    }

    /** Lien « @odata.id » d'une propriété d'une ressource. */
    private static function link(array $resource, string $property): ?string
    {
        $value = $resource[$property] ?? null;

        return BmcRules::safePath(is_array($value) ? ($value['@odata.id'] ?? null) : null);
    }

    /** Erreur bloquante : ni le contrôleur ni le serveur ne peuvent être lus. */
    private static function isBlocking(string $error): bool
    {
        return in_array($error, [
            self::ERROR_UNAUTHORIZED, self::ERROR_UNREACHABLE, self::ERROR_INTERNAL, self::ERROR_TLS, self::ERROR_CONFIG,
        ], true);
    }

    /**
     * Lecture complète : contrôleur (modèle, firmware, protocoles, certificat), serveur (série,
     * modèle, BIOS, santé, démarrage sécurisé), comptes locaux. complete = false en cas d'erreur
     * sur la racine, le contrôleur ou le serveur : l'appelant ne remplace alors rien. Une rubrique
     * facultative illisible (comptes, certificat, démarrage sécurisé) reste « inconnue ».
     *
     * @return array{complete: bool, error: string, snapshot: array<string, mixed>,
     *     accounts: list<array{username: string, role: string, enabled: int, locked: int}>, accounts_read: bool}
     */
    public function fetch(): array
    {
        $out = ['complete' => false, 'error' => '', 'snapshot' => [], 'accounts' => [], 'accounts_read' => false];
        if ($this->baseUrl === '' || $this->username === '') {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        $root = $this->get('/redfish/v1/');
        if ($root['error'] !== '') {
            $error = $root['error'];
            if (in_array($error, [self::ERROR_STATUS, self::ERROR_INVALID], true)) {
                $error = self::ERROR_NOT_REDFISH;
            }

            return ['error' => $error] + $out;
        }
        $rootData = (array) $root['data'];
        if (!isset($rootData['RedfishVersion']) && !isset($rootData['Managers']) && !isset($rootData['Systems'])) {
            return ['error' => self::ERROR_NOT_REDFISH] + $out;
        }
        $snapshot = [
            'redfish_version'  => mb_substr((string) ($rootData['RedfishVersion'] ?? ''), 0, 32),
            'vendor'           => BmcRules::vendorOf($rootData),
            'manager_model'    => '',
            'firmware_version' => '',
            'bios_version'     => '',
            'serial'           => '',
            'system_model'     => '',
            'manufacturer'     => '',
            'health'           => '',
            'power_state'      => '',
            'ipmi_lan'         => BmcRules::UNKNOWN,
            'telnet'           => BmcRules::UNKNOWN,
            'snmp_v1v2'        => BmcRules::UNKNOWN,
            'weak_tls'         => BmcRules::UNKNOWN,
            'secure_boot'      => BmcRules::UNKNOWN,
            'cert_self_signed' => BmcRules::UNKNOWN,
            'cert_expiry'      => null,
            'tls_verified'     => $this->verifyTls ? 1 : 0,
        ];

        // Contrôleur : premier gestionnaire de type BMC (sinon le premier).
        $managers = $this->members(self::link($rootData, 'Managers') ?? '/redfish/v1/Managers', 4);
        if ($managers['error'] !== '' || $managers['members'] === []) {
            return ['error' => $managers['error'] !== '' ? $managers['error'] : self::ERROR_INVALID] + $out;
        }
        $manager = null;
        foreach ($managers['members'] as $path) {
            $answer = $this->get($path);
            if ($answer['error'] !== '') {
                return ['error' => $answer['error']] + $out;
            }
            $manager ??= $answer['data'];
            if (strcasecmp((string) ($answer['data']['ManagerType'] ?? ''), 'BMC') === 0) {
                $manager = $answer['data'];
                break;
            }
        }
        $manager = (array) $manager;
        $snapshot['manager_model'] = mb_substr((string) ($manager['Model'] ?? ''), 0, 255);
        $snapshot['firmware_version'] = mb_substr((string) ($manager['FirmwareVersion'] ?? ''), 0, 255);
        $snapshot['health'] = BmcRules::normalizeHealth($manager['Status']['Health'] ?? null);

        // Protocoles réseau du contrôleur et certificat HTTPS (facultatifs).
        $protocolPath = self::link($manager, 'NetworkProtocol');
        if ($protocolPath !== null) {
            $protocol = $this->get($protocolPath);
            if (self::isBlocking($protocol['error'])) {
                return ['error' => $protocol['error']] + $out;
            }
            $p = (array) $protocol['data'];
            if ($p !== []) {
                $snapshot['ipmi_lan'] = BmcRules::tristate($p['IPMI']['ProtocolEnabled'] ?? null);
                $snapshot['telnet'] = BmcRules::tristate($p['Telnet']['ProtocolEnabled'] ?? null);
                $snmp = is_array($p['SNMP'] ?? null) ? $p['SNMP'] : [];
                if (BmcRules::tristate($snmp['ProtocolEnabled'] ?? null) === 0) {
                    $snapshot['snmp_v1v2'] = 0;
                } elseif (array_key_exists('EnableSNMPv1', $snmp) || array_key_exists('EnableSNMPv2c', $snmp)) {
                    $snapshot['snmp_v1v2'] = (($snmp['EnableSNMPv1'] ?? false) === true
                        || ($snmp['EnableSNMPv2c'] ?? false) === true) ? 1 : 0;
                }
                $https = is_array($p['HTTPS'] ?? null) ? $p['HTTPS'] : [];
                $certificates = $this->members(self::link($https, 'Certificates'), self::MAX_CERTIFICATES);
                foreach ($certificates['members'] as $path) {
                    $certificate = $this->get($path);
                    if ($certificate['data'] === null) {
                        continue;
                    }
                    $expiry = BmcRules::dateOf($certificate['data']['ValidNotAfter'] ?? null);
                    if ($expiry !== null && ($snapshot['cert_expiry'] === null || $expiry < $snapshot['cert_expiry'])) {
                        $snapshot['cert_expiry'] = $expiry;
                    }
                    $self = BmcRules::isSelfSigned($certificate['data']) ? 1 : 0;
                    $snapshot['cert_self_signed'] = max((int) $snapshot['cert_self_signed'], $self);
                }
            }
        }

        // Serveur géré : premier système.
        $systems = $this->members(self::link($rootData, 'Systems') ?? '/redfish/v1/Systems', 1);
        if ($systems['error'] !== '' || $systems['members'] === []) {
            return ['error' => $systems['error'] !== '' ? $systems['error'] : self::ERROR_INVALID] + $out;
        }
        $system = $this->get($systems['members'][0]);
        if ($system['error'] !== '') {
            return ['error' => $system['error']] + $out;
        }
        $s = (array) $system['data'];
        $snapshot['serial'] = mb_substr(trim((string) ($s['SerialNumber'] ?? '')), 0, 255);
        $snapshot['system_model'] = mb_substr((string) ($s['Model'] ?? ''), 0, 255);
        $snapshot['manufacturer'] = mb_substr((string) ($s['Manufacturer'] ?? ''), 0, 255);
        $snapshot['bios_version'] = mb_substr((string) ($s['BiosVersion'] ?? ''), 0, 255);
        $snapshot['power_state'] = mb_substr((string) ($s['PowerState'] ?? ''), 0, 16);
        $status = is_array($s['Status'] ?? null) ? $s['Status'] : [];
        $snapshot['health'] = BmcRules::worstHealth(
            $snapshot['health'],
            BmcRules::normalizeHealth($status['HealthRollup'] ?? ($status['Health'] ?? null))
        );
        $secureBootPath = self::link($s, 'SecureBoot');
        if ($secureBootPath !== null) {
            $secureBoot = $this->get($secureBootPath);
            if ($secureBoot['data'] !== null) {
                $snapshot['secure_boot'] = BmcRules::tristate($secureBoot['data']['SecureBootEnable'] ?? null);
            }
        }

        // Comptes locaux (facultatif : un compte en lecture seule peut ne pas les voir).
        $accounts = [];
        $accountsRead = false;
        $service = $this->get(self::link($rootData, 'AccountService') ?? '/redfish/v1/AccountService');
        if ($service['data'] !== null) {
            $list = $this->members(
                self::link($service['data'], 'Accounts') ?? '/redfish/v1/AccountService/Accounts',
                self::MAX_ACCOUNTS
            );
            if ($list['error'] === '') {
                $accountsRead = true;
                foreach ($list['members'] as $path) {
                    $account = $this->get($path);
                    if ($account['data'] === null) {
                        $accountsRead = false;
                        continue;
                    }
                    $username = mb_substr(trim((string) ($account['data']['UserName'] ?? '')), 0, 255);
                    // iDRAC et d'autres listent des emplacements vides.
                    if ($username === '') {
                        continue;
                    }
                    $accounts[mb_strtolower($username)] = [
                        'username' => $username,
                        'role'     => mb_substr((string) ($account['data']['RoleId'] ?? ''), 0, 64),
                        'enabled'  => BmcRules::tristate($account['data']['Enabled'] ?? null) === 0 ? 0 : 1,
                        'locked'   => BmcRules::tristate($account['data']['Locked'] ?? null) === 1 ? 1 : 0,
                    ];
                }
            }
        }

        return [
            'complete'      => true,
            'error'         => '',
            'snapshot'      => $snapshot,
            'accounts'      => array_values($accounts),
            'accounts_read' => $accountsRead,
        ];
    }
}
