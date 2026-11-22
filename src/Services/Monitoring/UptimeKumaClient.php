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
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;

/**
 * Issue #842 : client en LECTURE SEULE d'Uptime Kuma (1.23 et 2.x).
 *
 * - Requêtes GET seulement, vers trois chemins du serveur configuré : l'endpoint Prometheus
 *   « /metrics » (protégé par une clé d'API d'Uptime Kuma, en authentification Basic) et, pour
 *   chaque page de statut publique choisie, « /api/status-page/<page> » et
 *   « /api/status-page/heartbeat/<page> ». Tout autre chemin ou méthode est refusé avant l'envoi :
 *   aucune sonde, notification ni maintenance ne peut être créée, modifiée ou suspendue.
 * - Clé d'API jamais dans l'adresse, un message d'erreur ni un journal.
 * - Appels par Http::send() (#658 : proxy de GLPI, délai, redirections refusées, adresses internes
 *   seulement si l'administrateur les a autorisées). Transport injectable pour les tests.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 */
final class UptimeKumaClient
{
    /** Seuls chemins appelés, tous en lecture. */
    private const READ_PATHS = [
        '#^/metrics$#',
        '#^/api/status-page/[a-z0-9][a-z0-9_\-]{0,63}$#',
        '#^/api/status-page/heartbeat/[a-z0-9][a-z0-9_\-]{0,63}$#',
    ];

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_REFUSED = 'request_refused';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';
    /** Page de statut inconnue (identifiant mal saisi, page supprimée). */
    public const ERROR_PAGE = 'status_page_not_found';

    /** Sondes lues au plus. */
    public const MAX_MONITORS = 2000;

    public const TIMEOUT = 30;

    /** @var Transport */
    private Closure $transport;

    /**
     * @param string $baseUrl adresse du serveur, déjà normalisée (UptimeKumaRules::normalizeBaseUrl())
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
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

    public static function isReadPath(string $path): bool
    {
        foreach (self::READ_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une requête GET vers un des chemins de lecture. Corps renvoyé tel quel.
     *
     * @return array{body: string, error: string}
     */
    public function get(string $path, bool $withKey = false): array
    {
        if (!self::isReadPath($path)) {
            return ['body' => '', 'error' => self::ERROR_REFUSED];
        }
        if ($this->baseUrl === '' || ($withKey && $this->apiKey === '')) {
            return ['body' => '', 'error' => self::ERROR_CONFIG];
        }
        $headers = ['Accept' => $withKey ? 'text/plain' : 'application/json'];
        if ($withKey) {
            // Uptime Kuma attend la clé comme mot de passe, l'utilisateur est ignoré.
            $headers['Authorization'] = 'Basic ' . base64_encode(':' . $this->apiKey);
        }
        $result = ($this->transport)('GET', $this->baseUrl . $path, $headers, null);
        $status = (int) $result['status'];
        if ($status === 0) {
            $transportError = (string) ($result['error'] ?? '');

            return ['body' => '', 'error' => str_starts_with($transportError, Http::ERROR_INTERNAL)
                || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT)
                ? self::ERROR_INTERNAL : self::ERROR_UNREACHABLE];
        }
        if ($status === 401 || $status === 403) {
            return ['body' => '', 'error' => self::ERROR_UNAUTHORIZED];
        }
        if ($status === 404 && !$withKey) {
            return ['body' => '', 'error' => self::ERROR_PAGE];
        }
        if ($status < 200 || $status >= 300) {
            return ['body' => '', 'error' => self::ERROR_STATUS];
        }

        return ['body' => (string) $result['body'], 'error' => ''];
    }

    /**
     * Lecture complète : sondes de l'endpoint « /metrics » (si une clé d'API est enregistrée) et des
     * pages de statut choisies, avec leurs relevés. complete = false en cas d'erreur : rien n'est
     * alors remplacé par l'appelant. « page » : page de statut en cause (pas un secret).
     *
     * @param list<string> $slugs pages de statut, déjà normalisées
     * @return array{monitors: list<array<string, mixed>>, samples: list<array{key: string, time: int,
     *     status: int, ping: ?int}>, metrics: bool, pages: list<string>, truncated: bool, complete: bool,
     *     error: string, page: string}
     */
    public function fetch(array $slugs, int $now): array
    {
        $out = [
            'monitors' => [], 'samples' => [], 'metrics' => false, 'pages' => [], 'truncated' => false,
            'complete' => false, 'error' => '', 'page' => '',
        ];
        if ($this->baseUrl === '' || ($this->apiKey === '' && $slugs === [])) {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        /** @var array<string, array<string, mixed>> $monitors */
        $monitors = [];
        $samples = [];
        /** @var array<string, string> $renames ancienne clé => nouvelle clé */
        $renames = [];

        if ($this->apiKey !== '') {
            $metrics = $this->get('/metrics', true);
            if ($metrics['error'] !== '') {
                return ['error' => $metrics['error']] + $out;
            }
            $rows = Rules::parseMetrics($metrics['body']);
            // Pas une réponse Prometheus (page de connexion, autre service) : rien n'est remplacé.
            if ($rows === [] && !str_contains($metrics['body'], '# TYPE')) {
                return ['error' => self::ERROR_INVALID] + $out;
            }
            foreach (self::monitorsFromMetrics($rows) as $monitor) {
                $monitors[$monitor['key']] = $monitor;
                if ($monitor['status'] !== Rules::STATUS_UNKNOWN) {
                    $samples[] = [
                        'key' => $monitor['key'], 'time' => $now, 'status' => $monitor['status'],
                        'ping' => $monitor['response_time'],
                    ];
                }
            }
            $out['metrics'] = true;
        }

        foreach ($slugs as $slug) {
            $page = $this->get('/api/status-page/' . $slug);
            if ($page['error'] !== '') {
                return ['error' => $page['error'], 'page' => $slug] + $out;
            }
            $config = json_decode($page['body'], true);
            if (!is_array($config) || !is_array($config['publicGroupList'] ?? null)) {
                return ['error' => self::ERROR_INVALID, 'page' => $slug] + $out;
            }
            $beats = $this->get('/api/status-page/heartbeat/' . $slug);
            if ($beats['error'] !== '') {
                return ['error' => $beats['error'], 'page' => $slug] + $out;
            }
            $heartbeats = json_decode($beats['body'], true);
            if (!is_array($heartbeats)) {
                return ['error' => self::ERROR_INVALID, 'page' => $slug] + $out;
            }
            $read = self::monitorsFromStatusPage($slug, $config, $heartbeats);
            foreach ($read['monitors'] as $monitor) {
                $monitors[$monitor['key']] = self::merge($monitors, $monitor, $renames);
            }
            foreach ($read['samples'] as $sample) {
                $samples[] = $sample;
            }
            $out['pages'][] = $slug;
        }

        // Une sonde de « /metrics » rapprochée par son nom d'une sonde de page de statut prend la clé
        // de cette dernière (son identifiant) : ses relevés aussi.
        foreach ($samples as &$sample) {
            $sample['key'] = $renames[$sample['key']] ?? $sample['key'];
        }
        unset($sample);
        $out['monitors'] = array_values($monitors);
        if (count($out['monitors']) > self::MAX_MONITORS) {
            $out['monitors'] = array_slice($out['monitors'], 0, self::MAX_MONITORS);
            $out['truncated'] = true;
            $kept = array_fill_keys(array_column($out['monitors'], 'key'), true);
            $samples = array_values(array_filter($samples, static fn (array $s): bool => isset($kept[$s['key']])));
        }
        $out['samples'] = $samples;
        $out['complete'] = true;

        return $out;
    }

    /**
     * Ajoute une sonde de page de statut à celles déjà lues : même clé, ou même nom qu'une sonde lue
     * par « /metrics » sans identifiant. Les valeurs déjà connues sont gardées, les manquantes ajoutées.
     *
     * @param array<string, array<string, mixed>> $monitors
     * @param array<string, mixed> $monitor
     * @param array<string, string> $renames
     * @return array<string, mixed>
     */
    private static function merge(array &$monitors, array $monitor, array &$renames): array
    {
        $existing = $monitors[$monitor['key']] ?? null;
        if ($existing === null) {
            foreach ($monitors as $key => $candidate) {
                if (
                    str_starts_with((string) $key, 'name:')
                    && mb_strtolower((string) $candidate['name']) === mb_strtolower((string) $monitor['name'])
                ) {
                    $existing = $candidate;
                    unset($monitors[$key]);
                    $renames[(string) $key] = (string) $monitor['key'];
                    break;
                }
            }
        }
        if ($existing === null) {
            return $monitor;
        }
        foreach ($monitor as $field => $value) {
            if ($field === 'pages') {
                $existing['pages'] = array_values(array_unique(
                    array_merge((array) $existing['pages'], (array) $value)
                ));
            } elseif (
                $value !== null && $value !== ''
                && (($existing[$field] ?? null) === null || ($existing[$field] ?? '') === ''
                    || ($field === 'status' && $existing['status'] === Rules::STATUS_UNKNOWN))
            ) {
                $existing[$field] = $value;
            }
        }
        $existing['key'] = $monitor['key'];

        return $existing;
    }

    /**
     * Une sonde vide, au format commun.
     *
     * @return array{key: string, monitor_id: string, name: string, type: string, url: string, hostname: string,
     *     port: string, status: int, response_time: ?int, cert_days: ?int, cert_valid: ?bool, uptime_24h: ?float,
     *     pages: list<string>}
     */
    private static function blank(string $id, string $name): array
    {
        return [
            'key'           => Rules::monitorKey($id, $name),
            'monitor_id'    => preg_match('/^\d{1,20}$/', $id) === 1 ? $id : '',
            'name'          => mb_substr($name, 0, 255),
            'type'          => '',
            'url'           => '',
            'hostname'      => '',
            'port'          => '',
            'status'        => Rules::STATUS_UNKNOWN,
            'response_time' => null,
            'cert_days'     => null,
            'cert_valid'    => null,
            'uptime_24h'    => null,
            'pages'         => [],
        ];
    }

    /**
     * Sondes de l'endpoint « /metrics » : monitor_status, monitor_response_time,
     * monitor_cert_days_remaining et monitor_cert_is_valid, regroupées par sonde.
     *
     * @param list<array{metric: string, labels: array<string, string>, value: float}> $rows
     * @return list<array<string, mixed>>
     */
    public static function monitorsFromMetrics(array $rows): array
    {
        $monitors = [];
        foreach ($rows as $row) {
            $labels = $row['labels'];
            $name = trim((string) ($labels['monitor_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = Rules::labelValue($labels['monitor_id'] ?? '');
            $key = Rules::monitorKey($id, $name);
            $monitor = $monitors[$key] ?? self::blank($id, $name);
            $monitor['type'] = mb_substr(Rules::labelValue($labels['monitor_type'] ?? ''), 0, 32);
            $monitor['url'] = self::cleanUrl(Rules::labelValue($labels['monitor_url'] ?? ''));
            $monitor['hostname'] = mb_substr(Rules::labelValue($labels['monitor_hostname'] ?? ''), 0, 255);
            $monitor['port'] = preg_match('/^\d{1,5}$/', Rules::labelValue($labels['monitor_port'] ?? '')) === 1
                ? Rules::labelValue($labels['monitor_port'] ?? '') : '';
            $value = $row['value'];
            match ($row['metric']) {
                'monitor_status'              => $monitor['status'] = Rules::normalizeStatus((int) $value),
                'monitor_response_time'       => $monitor['response_time'] = $value >= 0 ? (int) round($value) : null,
                'monitor_cert_days_remaining' => $monitor['cert_days'] = (int) floor($value),
                'monitor_cert_is_valid'       => $monitor['cert_valid'] = $value >= 1,
                default                       => null,
            };
            $monitors[$key] = $monitor;
        }

        return array_values($monitors);
    }

    /**
     * Sondes d'une page de statut publique et leurs relevés (heartbeatList), disponibilité sur
     * 24 heures calculée par Uptime Kuma (uptimeList), certificat si la page l'affiche.
     *
     * @param array<mixed> $config réponse de /api/status-page/<page>
     * @param array<mixed> $heartbeats réponse de /api/status-page/heartbeat/<page>
     * @return array{monitors: list<array<string, mixed>>, samples: list<array{key: string, time: int,
     *     status: int, ping: ?int}>}
     */
    public static function monitorsFromStatusPage(string $slug, array $config, array $heartbeats): array
    {
        $monitors = [];
        $samples = [];
        $beats = is_array($heartbeats['heartbeatList'] ?? null) ? $heartbeats['heartbeatList'] : [];
        $uptimes = is_array($heartbeats['uptimeList'] ?? null) ? $heartbeats['uptimeList'] : [];
        foreach ((array) $config['publicGroupList'] as $group) {
            if (!is_array($group) || !is_array($group['monitorList'] ?? null)) {
                continue;
            }
            foreach ($group['monitorList'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? '');
                $name = trim((string) ($item['name'] ?? ''));
                if (preg_match('/^\d{1,20}$/', $id) !== 1 || $name === '') {
                    continue;
                }
                $monitor = self::blank($id, $name);
                $monitor['type'] = mb_substr((string) ($item['type'] ?? ''), 0, 32);
                $monitor['url'] = self::cleanUrl((string) ($item['url'] ?? ''));
                $monitor['pages'] = [$slug];
                if (isset($item['certExpiryDaysRemaining']) && is_numeric($item['certExpiryDaysRemaining'])) {
                    $monitor['cert_days'] = (int) floor((float) $item['certExpiryDaysRemaining']);
                }
                if (isset($item['validCert']) && is_bool($item['validCert'])) {
                    $monitor['cert_valid'] = $item['validCert'];
                }
                $uptime = $uptimes[$id . '_24'] ?? null;
                if (is_numeric($uptime)) {
                    $monitor['uptime_24h'] = round(max(0.0, min(1.0, (float) $uptime)) * 100, 2);
                }
                $last = null;
                foreach (is_array($beats[$id] ?? null) ? $beats[$id] : [] as $beat) {
                    if (!is_array($beat)) {
                        continue;
                    }
                    $time = Rules::parseTime((string) ($beat['time'] ?? ''));
                    $status = Rules::normalizeStatus($beat['status'] ?? null);
                    if ($time === null || $status === Rules::STATUS_UNKNOWN) {
                        continue;
                    }
                    $ping = is_numeric($beat['ping'] ?? null) ? (int) round((float) $beat['ping']) : null;
                    $samples[] = ['key' => $monitor['key'], 'time' => $time, 'status' => $status, 'ping' => $ping];
                    if ($last === null || $time >= $last[0]) {
                        $last = [$time, $status, $ping];
                    }
                }
                if ($last !== null) {
                    $monitor['status'] = $last[1];
                    $monitor['response_time'] = $last[2];
                }
                $monitors[$monitor['key']] = $monitor;
            }
        }

        return ['monitors' => array_values($monitors), 'samples' => $samples];
    }

    /**
     * Adresse surveillée, gardée seulement si c'est une adresse web lisible (jamais d'identifiants).
     */
    private static function cleanUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (
            $url === '' || !is_array($parts) || !in_array($scheme, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            return '';
        }
        // Sans identifiants, requête ni fragment (une adresse de sonde peut porter un jeton).
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return mb_substr(
            $scheme . '://' . (string) $parts['host'] . $port . (string) ($parts['path'] ?? ''),
            0,
            1024
        );
    }
}
