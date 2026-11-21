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

namespace GlpiPlugin\Grcmanager\Services\Monitoring;

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #836 : client en LECTURE SEULE de l'API JSON-RPC de Zabbix (5.4 et suivantes).
 *
 * - Liste fermée de méthodes (READ_METHODS) : uniquement des lectures (*.get, sla.getsli,
 *   apiinfo.version). Toute autre méthode est refusée avant l'envoi : aucune création, mise à jour,
 *   suppression ni acquittement ne peut partir vers Zabbix.
 * - Une seule adresse appelée : celle de l'API du serveur configuré (POST JSON-RPC).
 * - Jeton d'API d'un utilisateur Zabbix (de préférence en lecture seule) : en-tête
 *   « Authorization: Bearer » à partir de Zabbix 6.4, champ « auth » du corps avant ; jamais dans
 *   l'adresse, un message d'erreur ni un journal.
 * - Appels par Http::send() (#658 : proxy de GLPI, délai, redirections refusées, adresses internes
 *   seulement si l'administrateur les a autorisées). Transport injectable pour les tests.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 */
final class ZabbixClient
{
    /** Seules méthodes appelées : des lectures. */
    public const READ_METHODS = [
        'apiinfo.version', 'host.get', 'problem.get', 'event.get', 'sla.get', 'sla.getsli', 'service.get',
    ];

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_METHOD = 'method_refused';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_UNREACHABLE = 'unreachable';
    /** Adresse interne refusée par Http::send() (réglage de la page de configuration). */
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';
    public const ERROR_API = 'api_error';
    public const ERROR_VERSION = 'unsupported_version';
    /** Méthode absente de cette version de Zabbix (SLA avant 6.0) : non bloquant. */
    public const ERROR_NOT_FOUND = 'method_not_found';

    /** Hôtes et événements lus au plus (sur clic : la page web doit répondre). */
    public const MAX_HOSTS = 10000;
    public const MAX_EVENTS = 5000;
    public const MAX_SLAS = 50;

    public const TIMEOUT = 30;

    private const MIN_VERSION = '5.4';
    private const BEARER_VERSION = '6.4';

    /** @var Transport */
    private Closure $transport;

    private int $requestId = 0;

    private ?string $version = null;

    /**
     * @param string $apiUrl adresse de l'API, déjà normalisée (ZabbixRules::normalizeApiUrl())
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $apiUrl,
        #[\SensitiveParameter] private readonly string $token,
        ?Closure $transport = null
    ) {
        $this->transport = $transport ?? static function (
            string $method,
            string $url,
            array $headers,
            ?string $body
        ): array {
            $error = null;
            $response = Http::send($method, $url, $headers, $error, self::TIMEOUT, $body);

            return $response + ['error' => (string) $error];
        };
    }

    public static function isReadMethod(string $method): bool
    {
        return in_array($method, self::READ_METHODS, true);
    }

    /**
     * Un appel JSON-RPC. Refuse toute méthode hors de READ_METHODS.
     *
     * @param array<string, mixed>|list<mixed> $params
     * @return array{result: mixed, error: string}
     */
    public function call(string $method, array $params = []): array
    {
        if (!self::isReadMethod($method)) {
            return ['result' => null, 'error' => self::ERROR_METHOD];
        }
        if ($this->apiUrl === '' || ($this->token === '' && $method !== 'apiinfo.version')) {
            return ['result' => null, 'error' => self::ERROR_CONFIG];
        }
        $request = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => ++$this->requestId];
        $headers = ['Content-Type' => 'application/json-rpc', 'Accept' => 'application/json'];
        // apiinfo.version refuse toute authentification.
        if ($method !== 'apiinfo.version') {
            if ($this->version !== null && version_compare($this->version, self::BEARER_VERSION, '>=')) {
                $headers['Authorization'] = 'Bearer ' . $this->token;
            } else {
                $request['auth'] = $this->token;
            }
        }
        $result = ($this->transport)(
            'POST',
            $this->apiUrl,
            $headers,
            (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $status = (int) $result['status'];
        if ($status === 0) {
            $transportError = (string) ($result['error'] ?? '');

            return ['result' => null, 'error' => str_starts_with($transportError, Http::ERROR_INTERNAL)
                || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT)
                ? self::ERROR_INTERNAL : self::ERROR_UNREACHABLE];
        }
        if ($status === 401) {
            return ['result' => null, 'error' => self::ERROR_UNAUTHORIZED];
        }
        if ($status === 403) {
            return ['result' => null, 'error' => self::ERROR_FORBIDDEN];
        }
        if ($status < 200 || $status >= 300) {
            return ['result' => null, 'error' => self::ERROR_STATUS];
        }
        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            return ['result' => null, 'error' => self::ERROR_INVALID];
        }
        if (isset($data['error']) && is_array($data['error'])) {
            return ['result' => null, 'error' => self::errorOf($data['error'])];
        }
        if (!array_key_exists('result', $data)) {
            return ['result' => null, 'error' => self::ERROR_INVALID];
        }

        return ['result' => $data['result'], 'error' => ''];
    }

    /**
     * Erreur JSON-RPC de Zabbix : jeton refusé, méthode absente, droits, autre.
     *
     * @param array<mixed> $error
     */
    public static function errorOf(array $error): string
    {
        $code = (int) ($error['code'] ?? 0);
        $text = mb_strtolower((string) ($error['data'] ?? '') . ' ' . (string) ($error['message'] ?? ''));
        if ($code === -32601 || str_contains($text, 'incorrect method') || str_contains($text, 'method not found')) {
            return self::ERROR_NOT_FOUND;
        }
        if (preg_match('/not authori[sz]ed|session terminated|re-login|api token expired|invalid token/', $text) === 1) {
            return self::ERROR_UNAUTHORIZED;
        }
        if (str_contains($text, 'no permissions')) {
            return self::ERROR_FORBIDDEN;
        }

        return self::ERROR_API;
    }

    /**
     * Version de Zabbix (apiinfo.version), gardée pour choisir le mode d'authentification.
     *
     * @return array{version: string, error: string}
     */
    public function version(): array
    {
        $answer = $this->call('apiinfo.version', []);
        if ($answer['error'] !== '') {
            return ['version' => '', 'error' => $answer['error']];
        }
        $version = is_string($answer['result']) ? trim($answer['result']) : '';
        if (preg_match('/^\d+\.\d+/', $version) !== 1) {
            return ['version' => '', 'error' => self::ERROR_INVALID];
        }
        if (version_compare($version, self::MIN_VERSION, '<')) {
            return ['version' => $version, 'error' => self::ERROR_VERSION];
        }
        $this->version = $version;

        return ['version' => $version, 'error' => ''];
    }

    /**
     * Lecture complète : hôtes, problèmes de la période et problèmes encore ouverts, retours à la
     * normale, SLA (Zabbix 6.0 et suivants). complete = false en cas d'erreur : rien n'est alors
     * remplacé par l'appelant.
     *
     * @return array{version: string, hosts: list<array<string, mixed>>, events: list<array<string, mixed>>,
     *     open: list<string>, recoveries: array<string, int>, slas: list<array<string, mixed>>,
     *     sla_supported: bool, truncated: bool, complete: bool, error: string}
     */
    public function fetch(int $periodDays, int $now): array
    {
        $out = [
            'version' => '', 'hosts' => [], 'events' => [], 'open' => [], 'recoveries' => [], 'slas' => [],
            'sla_supported' => false, 'truncated' => false, 'complete' => false, 'error' => '',
        ];
        if ($this->apiUrl === '' || $this->token === '') {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        $version = $this->version();
        $out['version'] = $version['version'];
        if ($version['error'] !== '') {
            return ['error' => $version['error']] + $out;
        }

        $hosts = $this->call('host.get', [
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'available'],
            'selectInventory'  => ['serialno_a'],
            'sortfield'        => 'hostid',
            'limit'            => self::MAX_HOSTS + 1,
        ]);
        if ($hosts['error'] !== '') {
            return ['error' => $hosts['error']] + $out;
        }
        if (!is_array($hosts['result'])) {
            return ['error' => self::ERROR_INVALID] + $out;
        }
        $out['hosts'] = self::lists($hosts['result']);
        if (count($out['hosts']) > self::MAX_HOSTS) {
            $out['hosts'] = array_slice($out['hosts'], 0, self::MAX_HOSTS);
            $out['truncated'] = true;
        }

        $eventOutput = ['eventid', 'objectid', 'name', 'severity', 'clock', 'r_eventid', 'acknowledged'];
        $events = $this->call('event.get', [
            'output'      => $eventOutput,
            'selectHosts' => ['hostid'],
            'selectTags'  => ['tag', 'value'],
            'source'      => 0,
            'object'      => 0,
            'value'       => 1,
            'severities'  => ZabbixRules::READ_SEVERITIES,
            'time_from'   => $now - $periodDays * 86400,
            'sortfield'   => ['clock', 'eventid'],
            'sortorder'   => 'DESC',
            'limit'       => self::MAX_EVENTS + 1,
        ]);
        if ($events['error'] !== '' || !is_array($events['result'])) {
            return ['error' => $events['error'] !== '' ? $events['error'] : self::ERROR_INVALID] + $out;
        }
        $byId = [];
        foreach (self::lists($events['result']) as $event) {
            $byId[(string) ($event['eventid'] ?? '')] = $event;
        }
        unset($byId['']);
        if (count($byId) > self::MAX_EVENTS) {
            $byId = array_slice($byId, 0, self::MAX_EVENTS, true);
            $out['truncated'] = true;
        }

        // Problèmes encore ouverts, y compris ceux ouverts avant la période.
        $problems = $this->call('problem.get', [
            'output'     => ['eventid'],
            'source'     => 0,
            'object'     => 0,
            'severities' => ZabbixRules::READ_SEVERITIES,
            'sortfield'  => ['eventid'],
            'sortorder'  => 'DESC',
            'limit'      => self::MAX_EVENTS + 1,
        ]);
        if ($problems['error'] !== '' || !is_array($problems['result'])) {
            return ['error' => $problems['error'] !== '' ? $problems['error'] : self::ERROR_INVALID] + $out;
        }
        $open = [];
        foreach (self::lists($problems['result']) as $problem) {
            $id = (string) ($problem['eventid'] ?? '');
            if ($id !== '') {
                $open[$id] = true;
            }
        }
        $older = array_values(array_diff(array_keys($open), array_map('strval', array_keys($byId))));
        if ($older !== []) {
            $before = $this->call('event.get', [
                'output'      => $eventOutput,
                'selectHosts' => ['hostid'],
                'selectTags'  => ['tag', 'value'],
                'eventids'    => array_slice($older, 0, self::MAX_EVENTS),
            ]);
            if ($before['error'] !== '' || !is_array($before['result'])) {
                return ['error' => $before['error'] !== '' ? $before['error'] : self::ERROR_INVALID] + $out;
            }
            foreach (self::lists($before['result']) as $event) {
                $byId[(string) ($event['eventid'] ?? '')] ??= $event;
            }
            unset($byId['']);
        }
        $out['events'] = array_values($byId);
        $out['open'] = array_map('strval', array_keys($open));

        // Heure du retour à la normale des problèmes résolus.
        $recoveryIds = [];
        foreach ($out['events'] as $event) {
            $recovery = (string) ($event['r_eventid'] ?? '0');
            if ($recovery !== '' && $recovery !== '0') {
                $recoveryIds[$recovery] = true;
            }
        }
        if ($recoveryIds !== []) {
            $recoveries = $this->call('event.get', [
                'output'   => ['eventid', 'clock'],
                'eventids' => array_map('strval', array_keys($recoveryIds)),
            ]);
            if ($recoveries['error'] !== '' || !is_array($recoveries['result'])) {
                return ['error' => $recoveries['error'] !== '' ? $recoveries['error'] : self::ERROR_INVALID] + $out;
            }
            foreach (self::lists($recoveries['result']) as $event) {
                $out['recoveries'][(string) ($event['eventid'] ?? '')] = (int) ($event['clock'] ?? 0);
            }
        }

        // SLA des services (Zabbix 6.0 et suivants) : absents d'une version plus ancienne, non bloquant.
        $slas = $this->fetchSlas();
        if ($slas['error'] !== '') {
            return ['error' => $slas['error']] + $out;
        }
        $out['slas'] = $slas['slas'];
        $out['sla_supported'] = $slas['supported'];
        $out['complete'] = true;

        return $out;
    }

    /**
     * SLA actifs, disponibilité (SLI) de chaque service sur la période en cours et la précédente.
     *
     * @return array{slas: list<array<string, mixed>>, supported: bool, error: string}
     */
    private function fetchSlas(): array
    {
        $slas = $this->call('sla.get', [
            'output' => ['slaid', 'name', 'period', 'slo', 'status'],
            'filter' => ['status' => 1],
            'limit'  => self::MAX_SLAS,
        ]);
        if ($slas['error'] === self::ERROR_NOT_FOUND || $slas['error'] === self::ERROR_FORBIDDEN) {
            return ['slas' => [], 'supported' => false, 'error' => ''];
        }
        if ($slas['error'] !== '' || !is_array($slas['result'])) {
            return ['slas' => [], 'supported' => false, 'error' => $slas['error'] !== '' ? $slas['error'] : self::ERROR_INVALID];
        }
        $out = [];
        $serviceIds = [];
        foreach (self::lists($slas['result']) as $sla) {
            $sli = $this->call('sla.getsli', ['slaid' => (string) ($sla['slaid'] ?? ''), 'periods' => 2]);
            if ($sli['error'] !== '' || !is_array($sli['result'])) {
                return [
                    'slas' => [], 'supported' => true,
                    'error' => $sli['error'] !== '' ? $sli['error'] : self::ERROR_INVALID,
                ];
            }
            $sla['sli'] = $sli['result'];
            foreach (is_array($sli['result']['serviceids'] ?? null) ? $sli['result']['serviceids'] : [] as $id) {
                $serviceIds[(string) $id] = true;
            }
            $out[] = $sla;
        }
        $names = [];
        if ($serviceIds !== []) {
            $services = $this->call('service.get', [
                'output'     => ['serviceid', 'name'],
                'serviceids' => array_map('strval', array_keys($serviceIds)),
            ]);
            if ($services['error'] !== '' || !is_array($services['result'])) {
                return [
                    'slas' => [], 'supported' => true,
                    'error' => $services['error'] !== '' ? $services['error'] : self::ERROR_INVALID,
                ];
            }
            foreach (self::lists($services['result']) as $service) {
                $names[(string) ($service['serviceid'] ?? '')] = (string) ($service['name'] ?? '');
            }
        }
        foreach ($out as &$sla) {
            $sla['service_names'] = $names;
        }
        unset($sla);

        return ['slas' => $out, 'supported' => true, 'error' => ''];
    }

    /**
     * @param array<mixed> $result
     * @return list<array<string, mixed>>
     */
    private static function lists(array $result): array
    {
        return array_values(array_filter($result, 'is_array'));
    }

    // ------------------------------------------------------------------ Mise en forme des réponses.

    /**
     * Hôtes au format des lignes stockées.
     *
     * @param list<array<string, mixed>> $hosts
     * @return list<array{hostid: string, host: string, name: string, monitored: bool, ips: list<string>,
     *     dns: list<string>, availability: list<int>, serial: string}>
     */
    public static function hostRows(array $hosts): array
    {
        $rows = [];
        foreach ($hosts as $host) {
            $id = (string) ($host['hostid'] ?? '');
            if ($id === '' || preg_match('/^\d{1,20}$/', $id) !== 1) {
                continue;
            }
            $ips = [];
            $dns = [];
            $states = [];
            foreach (is_array($host['interfaces'] ?? null) ? $host['interfaces'] : [] as $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                $ip = trim((string) ($interface['ip'] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
                $name = trim((string) ($interface['dns'] ?? ''));
                if ($name !== '' && preg_match('/^[A-Za-z0-9.\-_]{1,253}$/', $name) === 1) {
                    $dns[] = $name;
                }
                if (isset($interface['available']) && is_numeric($interface['available'])) {
                    $states[] = (int) $interface['available'];
                }
            }
            $inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
            $rows[] = [
                'hostid'       => $id,
                'host'         => mb_substr(trim((string) ($host['host'] ?? '')), 0, 255),
                'name'         => mb_substr(trim((string) ($host['name'] ?? '')), 0, 255),
                // status 0 : supervisé ; 1 : non supervisé.
                'monitored'    => (string) ($host['status'] ?? '1') === '0',
                'ips'          => array_values(array_unique($ips)),
                'dns'          => array_values(array_unique($dns)),
                'availability' => $states,
                'serial'       => mb_substr(trim((string) ($inventory['serialno_a'] ?? '')), 0, 255),
            ];
        }

        return $rows;
    }

    /**
     * Problèmes au format des lignes stockées : une ligne par hôte concerné.
     *
     * @param list<array<string, mixed>> $events
     * @param list<string> $open
     * @param array<string, int> $recoveries
     * @return list<array{eventid: string, hostid: string, name: string, severity: int, clock: int,
     *     r_clock: ?int, is_open: bool, acknowledged: bool, capacity: string}>
     */
    public static function problemRows(array $events, array $open, array $recoveries): array
    {
        $isOpen = array_fill_keys($open, true);
        $rows = [];
        foreach ($events as $event) {
            $id = (string) ($event['eventid'] ?? '');
            if ($id === '' || preg_match('/^\d{1,20}$/', $id) !== 1) {
                continue;
            }
            $tags = [];
            foreach (is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
                if (is_array($tag)) {
                    $tags[] = ['tag' => (string) ($tag['tag'] ?? ''), 'value' => (string) ($tag['value'] ?? '')];
                }
            }
            $name = mb_substr(trim((string) ($event['name'] ?? '')), 0, 255);
            $recovery = (string) ($event['r_eventid'] ?? '0');
            $stillOpen = isset($isOpen[$id]);
            $recoveredAt = !$stillOpen && $recovery !== '0' && isset($recoveries[$recovery]) ? $recoveries[$recovery] : null;
            foreach (is_array($event['hosts'] ?? null) ? $event['hosts'] : [] as $host) {
                $hostId = is_array($host) ? (string) ($host['hostid'] ?? '') : '';
                if ($hostId === '') {
                    continue;
                }
                $rows[] = [
                    'eventid'      => $id,
                    'hostid'       => $hostId,
                    'name'         => $name,
                    'severity'     => ZabbixRules::normalizeSeverity($event['severity'] ?? 0),
                    'clock'        => (int) ($event['clock'] ?? 0),
                    'r_clock'      => $recoveredAt,
                    'is_open'      => $stillOpen,
                    'acknowledged' => (string) ($event['acknowledged'] ?? '0') === '1',
                    'capacity'     => (string) ZabbixRules::capacityKind($name, $tags),
                ];
            }
        }

        return $rows;
    }

    /**
     * Disponibilité des services : une ligne par SLA et par service, période en cours et précédente.
     *
     * @param list<array<string, mixed>> $slas
     * @return list<array{slaid: string, sla_name: string, slo: ?float, serviceid: string, service_name: string,
     *     sli: ?float, sli_previous: ?float, downtime: int, period_from: ?int, period_to: ?int}>
     */
    public static function serviceRows(array $slas): array
    {
        $rows = [];
        foreach ($slas as $sla) {
            $data = is_array($sla['sli'] ?? null) ? $sla['sli'] : [];
            $periods = array_values(array_filter(is_array($data['periods'] ?? null) ? $data['periods'] : [], 'is_array'));
            $serviceIds = array_values(is_array($data['serviceids'] ?? null) ? $data['serviceids'] : []);
            $values = array_values(is_array($data['sli'] ?? null) ? $data['sli'] : []);
            // Périodes de la plus ancienne à la plus récente : la dernière est la période en cours.
            $last = count($periods) - 1;
            $names = is_array($sla['service_names'] ?? null) ? $sla['service_names'] : [];
            foreach ($serviceIds as $index => $serviceId) {
                $serviceId = (string) $serviceId;
                $current = $last >= 0 && is_array($values[$last][$index] ?? null) ? $values[$last][$index] : null;
                $previous = $last >= 1 && is_array($values[$last - 1][$index] ?? null) ? $values[$last - 1][$index] : null;
                $rows[] = [
                    'slaid'        => (string) ($sla['slaid'] ?? ''),
                    'sla_name'     => mb_substr((string) ($sla['name'] ?? ''), 0, 255),
                    'slo'          => is_numeric($sla['slo'] ?? null) ? round((float) $sla['slo'], 4) : null,
                    'serviceid'    => $serviceId,
                    'service_name' => mb_substr((string) ($names[$serviceId] ?? $serviceId), 0, 255),
                    'sli'          => is_numeric($current['sli'] ?? null) ? round((float) $current['sli'], 4) : null,
                    'sli_previous' => is_numeric($previous['sli'] ?? null) ? round((float) $previous['sli'], 4) : null,
                    'downtime'     => (int) ($current['downtime'] ?? 0),
                    'period_from'  => $last >= 0 ? (int) ($periods[$last]['period_from'] ?? 0) : null,
                    'period_to'    => $last >= 0 ? (int) ($periods[$last]['period_to'] ?? 0) : null,
                ];
            }
        }

        return $rows;
    }
}
