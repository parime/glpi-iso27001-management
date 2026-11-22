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

namespace GlpiPlugin\Grcmanager\Services\Backup;

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

/**
 * Issue #838 : lecture SEULE de Veeam Backup & Replication par son API REST (port 9419 par défaut).
 *
 * - Jeton obtenu par POST /api/oauth2/token (compte de lecture, rôle « Veeam Backup Viewer »
 *   suffisant). C'est la seule requête POST ; toutes les autres sont des GET sur deux chemins :
 *   /api/v1/restorePoints (points de restauration récents, donc dernière sauvegarde réussie de
 *   chaque machine) et /api/v1/jobs/states (dernier résultat de chaque travail). Aucune sauvegarde
 *   n'est lancée, aucun travail ni réglage n'est modifié.
 * - Le mot de passe et le jeton ne sortent que dans le corps de la demande de jeton et dans
 *   l'en-tête Authorization : jamais dans une adresse, un message d'erreur ni un journal.
 * - Pages lues par skip/limit, au plus MAX_PAGES par liste ; une lecture incomplète est signalée
 *   et ne remplace rien (BackupCoverage::importFromVeeam()).
 * - Appels par Http::send() (#658 : proxy de GLPI, délai, redirections refusées, adresses internes
 *   refusées sauf autorisation de l'administrateur). Transport injectable pour les tests.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 * @phpstan-type HostRow array{host: string, ip: string, job: string, last_success: ?string,
 *     last_attempt: ?string, last_result: string}
 * @phpstan-type JobRow array{name: string, type: string, last_run: ?string, last_result: string, objects: int}
 */
final class VeeamClient
{
    public const DEFAULT_PORT = 9419;

    /** Révisions de l'API proposées (en-tête x-api-version), de VBR 12 à VBR 13. */
    public const API_VERSIONS = ['1.1-rev0', '1.1-rev1', '1.1-rev2', '1.2-rev0', '1.2-rev1', '1.3-rev0'];
    public const DEFAULT_API_VERSION = '1.1-rev2';

    public const TOKEN_PATH = '/api/oauth2/token';
    public const RESTORE_POINTS_PATH = '/api/v1/restorePoints';
    public const JOB_STATES_PATH = '/api/v1/jobs/states';

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_PRIVATE = 'private_address';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';
    public const ERROR_TOO_LARGE = 'too_large';

    /** Pages lues au plus par liste (sur clic : la page web doit répondre). */
    public const MAX_PAGES = 50;
    public const PAGE_SIZE = 500;

    /** Points de restauration lus : ceux des derniers jours seulement. */
    public const LOOKBACK_DAYS = 31;

    public const TIMEOUT = 20;

    /** @var Transport */
    private Closure $transport;

    /**
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $apiVersion = self::DEFAULT_API_VERSION,
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

    /**
     * Adresse du serveur : https (http seulement si l'administrateur autorise les adresses
     * internes), nom ou adresse IP, port 9419 si aucun n'est donné ; sans chemin ni identifiants.
     * Null si l'adresse est refusée.
     */
    public static function normalizeUrl(string $url, bool $allowHttp = false): ?string
    {
        $url = trim($url);
        if ($url !== '' && !str_contains($url, '://')) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $allowHttp))) {
            return null;
        }
        if (preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : self::DEFAULT_PORT;

        return $scheme . '://' . $host . ':' . $port;
    }

    public static function isValidApiVersion(string $version): bool
    {
        return in_array($version, self::API_VERSIONS, true);
    }

    /**
     * Lit les points de restauration récents et l'état des travaux.
     *
     * @return array{hosts: list<HostRow>, jobs: list<JobRow>, points: int, complete: bool, error: string}
     */
    public function fetch(string $now, int $maxPages = self::MAX_PAGES): array
    {
        $out = ['hosts' => [], 'jobs' => [], 'points' => 0, 'complete' => false, 'error' => ''];
        if ($this->baseUrl === '' || trim($this->username) === '' || $this->password === '') {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        $token = $this->token();
        if ($token['error'] !== '') {
            return ['error' => $token['error']] + $out;
        }
        $since = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($now) - self::LOOKBACK_DAYS * 86400);
        $points = $this->list(self::RESTORE_POINTS_PATH, [
            'orderColumn'        => 'CreationTime',
            'orderAsc'           => 'false',
            'createdAfterFilter' => $since,
        ], $token['token'], $maxPages);
        if ($points['error'] !== '') {
            return ['error' => $points['error']] + $out;
        }
        $jobs = $this->list(self::JOB_STATES_PATH, [], $token['token'], $maxPages);
        if ($jobs['error'] !== '') {
            return ['error' => $jobs['error']] + $out;
        }

        return [
            'hosts'    => self::hostRows($points['items']),
            'jobs'     => self::jobRows($jobs['items']),
            'points'   => count($points['items']),
            'complete' => true,
            'error'    => '',
        ];
    }

    /**
     * Essai de la connexion (issue #1042, bouton « Tester la connexion » de la configuration) :
     * demande d'un jeton d'accès avec le compte de lecture. Code d'erreur, vide si elle a réussi.
     */
    public function test(): string
    {
        if ($this->baseUrl === '' || trim($this->username) === '' || $this->password === '') {
            return self::ERROR_CONFIG;
        }

        return $this->token()['error'];
    }

    /**
     * @param array<string, string> $query
     * @return array{items: list<array<string, mixed>>, error: string}
     */
    private function list(string $path, array $query, string $token, int $maxPages): array
    {
        $items = [];
        $skip = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $url = $this->baseUrl . $path . '?' . http_build_query(
                ['skip' => (string) $skip, 'limit' => (string) self::PAGE_SIZE] + $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $result = ($this->transport)('GET', $url, [
                'Authorization' => 'Bearer ' . $token,
                'x-api-version' => $this->apiVersion,
                'Accept'        => 'application/json',
            ], null);
            $error = self::errorOf($result['status'], (string) ($result['error'] ?? ''));
            if ($error !== '') {
                return ['items' => [], 'error' => $error];
            }
            $json = json_decode($result['body'], true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                return ['items' => [], 'error' => self::ERROR_INVALID];
            }
            $count = 0;
            foreach ($json['data'] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                    $count++;
                }
            }
            $skip += $count;
            $total = is_array($json['pagination'] ?? null) && is_numeric($json['pagination']['total'] ?? null)
                ? (int) $json['pagination']['total']
                : null;
            if ($count < self::PAGE_SIZE || ($total !== null && $skip >= $total)) {
                return ['items' => $items, 'error' => ''];
            }
        }

        return ['items' => [], 'error' => self::ERROR_TOO_LARGE];
    }

    /**
     * Jeton d'accès (mot de passe du compte de lecture).
     *
     * @return array{token: string, error: string}
     */
    private function token(): array
    {
        $result = ($this->transport)(
            'POST',
            $this->baseUrl . self::TOKEN_PATH,
            [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
                'x-api-version' => $this->apiVersion,
            ],
            http_build_query([
                'grant_type' => 'password',
                'username'   => $this->username,
                'password'   => $this->password,
            ], '', '&', PHP_QUERY_RFC3986)
        );
        // Compte ou mot de passe refusés : 400 ou 401 selon la version.
        if (in_array($result['status'], [400, 401], true)) {
            return ['token' => '', 'error' => self::ERROR_UNAUTHORIZED];
        }
        $error = self::errorOf($result['status'], (string) ($result['error'] ?? ''));
        if ($error !== '') {
            return ['token' => '', 'error' => $error];
        }
        $data = json_decode($result['body'], true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        return is_string($token) && $token !== ''
            ? ['token' => $token, 'error' => '']
            : ['token' => '', 'error' => self::ERROR_INVALID];
    }

    /**
     * Code d'erreur d'une réponse (vide si elle a réussi). Code 0 : appel refusé par Http::send()
     * (adresse interne non autorisée) ou serveur injoignable.
     */
    public static function errorOf(int $status, string $transportError = ''): string
    {
        $refused = str_starts_with($transportError, Http::ERROR_INTERNAL)
            || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT);
        if ($status === 0 && $refused) {
            return self::ERROR_PRIVATE;
        }

        return match (true) {
            $status >= 200 && $status < 300 => '',
            $status === 0                   => self::ERROR_UNREACHABLE,
            $status === 401                 => self::ERROR_UNAUTHORIZED,
            $status === 403                 => self::ERROR_FORBIDDEN,
            $status === 429                 => self::ERROR_RATE_LIMITED,
            default                         => self::ERROR_STATUS,
        };
    }

    /**
     * Dernière sauvegarde réussie de chaque machine, d'après ses points de restauration (un point
     * n'existe que pour une sauvegarde réussie ou en avertissement).
     *
     * @param list<array<string, mixed>> $items
     * @return list<HostRow>
     */
    public static function hostRows(array $items): array
    {
        $hosts = [];
        foreach ($items as $item) {
            $name = is_string($item['name'] ?? null) ? mb_substr(trim($item['name']), 0, 255) : '';
            $created = is_string($item['creationTime'] ?? null)
                ? EdrCsvImport::parseDate($item['creationTime'])
                : null;
            if ($name === '' || $created === null) {
                continue;
            }
            $key = mb_strtolower($name);
            $hosts[$key] = BackupCoverageRules::merge($hosts[$key] ?? null, [
                'host'         => $name,
                'ip'           => '',
                'job'          => '',
                'last_success' => $created,
                'last_attempt' => $created,
                'last_result'  => BackupCoverageRules::RESULT_SUCCESS,
            ]);
        }

        return array_values($hosts);
    }

    /**
     * État de chaque travail : nom, type, dernier lancement et son résultat, nombre d'objets.
     *
     * @param list<array<string, mixed>> $items
     * @return list<JobRow>
     */
    public static function jobRows(array $items): array
    {
        $jobs = [];
        foreach ($items as $item) {
            $name = is_string($item['name'] ?? null) ? mb_substr(trim($item['name']), 0, 255) : '';
            if ($name === '') {
                continue;
            }
            $lastRun = is_string($item['lastRun'] ?? null) ? EdrCsvImport::parseDate($item['lastRun']) : null;
            $result = is_string($item['lastResult'] ?? null) ? $item['lastResult'] : '';
            $jobs[] = [
                'name'        => $name,
                'type'        => is_string($item['type'] ?? null) ? mb_substr($item['type'], 0, 64) : '',
                'last_run'    => $lastRun,
                'last_result' => BackupCoverageRules::normalizeResult($result),
                'objects'     => is_numeric($item['objectsCount'] ?? null) ? max(0, (int) $item['objectsCount']) : 0,
            ];
        }

        return $jobs;
    }
}
