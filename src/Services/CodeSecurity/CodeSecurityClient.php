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

namespace GlpiPlugin\Grcmanager\Services\CodeSecurity;

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;

/**
 * Issue #858 : client en LECTURE SEULE des alertes de sécurité du code de GitHub et de GitLab.
 *
 * - GitHub (github.com ou GitHub Enterprise Server) : requêtes GET seulement, vers les trois listes
 *   d'alertes ouvertes d'un dépôt (« dependabot/alerts », « secret-scanning/alerts » avec
 *   hide_secret=true, « code-scanning/alerts »). Tout autre chemin ou méthode est refusé avant l'envoi.
 * - GitLab : une seule requête, la lecture GraphQL constante GITLAB_QUERY (vulnérabilités détectées
 *   ou confirmées d'un projet, et analyseurs activés), envoyée en POST comme l'exige GraphQL ; aucune
 *   autre requête ni mutation ne peut partir.
 * - La valeur d'un secret exposé n'est jamais demandée, lue ni gardée : seuls son type, sa date et sa
 *   validité le sont.
 * - Jeton jamais dans l'adresse, un message d'erreur ni un journal. Appels par Http::send() (#658 :
 *   proxy de GLPI, délai, redirections refusées, adresses internes seulement si l'administrateur les
 *   a autorisées). Transport injectable pour les tests.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 */
final class CodeSecurityClient
{
    public const GITHUB_API = 'https://api.github.com';

    /** Alertes lues au plus par famille et par dépôt (une page). */
    public const PER_PAGE = 100;

    public const TIMEOUT = 30;

    /** Seuls chemins appelés chez GitHub, tous en lecture. */
    private const GITHUB_PATH = '#^/repos/[A-Za-z0-9_.\-]{1,100}/[A-Za-z0-9_.\-]{1,100}/'
        . '(dependabot|secret-scanning|code-scanning)/alerts$#';

    /** Famille d'alertes => chemin GitHub. */
    private const GITHUB_ENDPOINTS = [
        Rules::CATEGORY_DEPENDENCY => 'dependabot',
        Rules::CATEGORY_SECRET     => 'secret-scanning',
        Rules::CATEGORY_CODE       => 'code-scanning',
    ];

    /** Seule requête envoyée à GitLab : une lecture (query), jamais une mutation. */
    public const GITLAB_QUERY = 'query($fullPath: ID!) { project(fullPath: $fullPath) { '
        . 'securityScanners { enabled } '
        . 'vulnerabilities(state: [DETECTED, CONFIRMED], first: 100) { pageInfo { hasNextPage } '
        . 'nodes { id title severity reportType state detectedAt webUrl } } } }';

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_REFUSED = 'request_refused';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_RATE_LIMIT = 'rate_limited';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';

    /** @var Transport */
    private Closure $transport;

    /**
     * @param string $githubApi adresse de l'API GitHub (https://api.github.com ou https://serveur/api/v3)
     * @param string $gitlabUrl adresse du serveur GitLab, déjà normalisée
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $githubApi,
        #[\SensitiveParameter] private readonly string $githubToken,
        private readonly string $gitlabUrl,
        #[\SensitiveParameter] private readonly string $gitlabToken,
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

    public function hasProvider(string $provider): bool
    {
        return match ($provider) {
            Rules::PROVIDER_GITHUB => $this->githubApi !== '' && $this->githubToken !== '',
            Rules::PROVIDER_GITLAB => $this->gitlabUrl !== '' && $this->gitlabToken !== '',
            default                => false,
        };
    }

    public static function isGithubReadPath(string $path): bool
    {
        return preg_match(self::GITHUB_PATH, $path) === 1 && !str_contains($path, '..');
    }

    /**
     * Alertes ouvertes d'un dépôt. complete = false en cas d'erreur : rien n'est alors remplacé par
     * l'appelant. scans : famille => SCAN_OK ou SCAN_UNAVAILABLE (non activée ou non accessible).
     *
     * @return array{complete: bool, error: string, scans: array<string, string>, truncated: bool,
     *     alerts: list<array<string, string>>}
     */
    public function fetch(string $provider, string $path): array
    {
        $out = ['complete' => false, 'error' => '', 'scans' => [], 'truncated' => false, 'alerts' => []];
        if (!$this->hasProvider($provider)) {
            return ['error' => self::ERROR_CONFIG] + $out;
        }

        return $provider === Rules::PROVIDER_GITHUB ? $this->fetchGithub($path) : $this->fetchGitlab($path);
    }

    /**
     * @return array{status: int, body: string, error: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $result = ($this->transport)($method, $url, $headers, $body);
        $status = (int) $result['status'];
        if ($status === 0) {
            $transportError = (string) ($result['error'] ?? '');

            return ['status' => 0, 'body' => '', 'error' => str_starts_with($transportError, Http::ERROR_INTERNAL)
                || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT)
                ? self::ERROR_INTERNAL : self::ERROR_UNREACHABLE];
        }

        return ['status' => $status, 'body' => (string) $result['body'], 'error' => ''];
    }

    /**
     * @return array{complete: bool, error: string, scans: array<string, string>, truncated: bool,
     *     alerts: list<array<string, string>>}
     */
    private function fetchGithub(string $repository): array
    {
        $out = ['complete' => false, 'error' => '', 'scans' => [], 'truncated' => false, 'alerts' => []];
        foreach (self::GITHUB_ENDPOINTS as $category => $endpoint) {
            $path = '/repos/' . $repository . '/' . $endpoint . '/alerts';
            if (!self::isGithubReadPath($path)) {
                return ['error' => self::ERROR_REFUSED] + $out;
            }
            $query = ['state' => 'open', 'per_page' => self::PER_PAGE];
            if ($category === Rules::CATEGORY_SECRET) {
                // La valeur du secret n'est jamais demandée.
                $query['hide_secret'] = 'true';
            }
            $response = $this->send('GET', $this->githubApi . $path . '?' . http_build_query($query), [
                'Accept'               => 'application/vnd.github+json',
                'Authorization'        => 'Bearer ' . $this->githubToken,
                'X-GitHub-Api-Version' => '2022-11-28',
            ], null);
            if ($response['error'] !== '') {
                return ['error' => $response['error']] + $out;
            }
            $status = $response['status'];
            if ($status === 401) {
                return ['error' => self::ERROR_UNAUTHORIZED] + $out;
            }
            if (($status === 403 || $status === 429) && stripos($response['body'], 'rate limit') !== false) {
                return ['error' => self::ERROR_RATE_LIMIT] + $out;
            }
            if ($status === 403 || $status === 404) {
                // Analyse non activée, dépôt introuvable ou jeton sans accès à ces alertes.
                $out['scans'][$category] = Rules::SCAN_UNAVAILABLE;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return ['error' => self::ERROR_STATUS] + $out;
            }
            $rows = json_decode($response['body'], true);
            if (!is_array($rows) || !array_is_list($rows)) {
                return ['error' => self::ERROR_INVALID] + $out;
            }
            $out['scans'][$category] = Rules::SCAN_OK;
            $out['truncated'] = $out['truncated'] || count($rows) >= self::PER_PAGE;
            foreach ($rows as $row) {
                $alert = is_array($row) ? self::githubAlert($category, $row) : null;
                if ($alert !== null) {
                    $out['alerts'][] = $alert;
                }
            }
        }
        $out['complete'] = true;

        return $out;
    }

    /**
     * Une alerte GitHub au format commun ; null si elle n'est pas ouverte ou illisible. Pour un secret,
     * seuls le type, la date et la validité sont gardés (jamais la valeur).
     *
     * @param array<mixed> $row
     * @return array<string, string>|null
     */
    public static function githubAlert(string $category, array $row): ?array
    {
        $number = (string) ($row['number'] ?? '');
        if (preg_match('/^\d{1,20}$/', $number) !== 1 || (string) ($row['state'] ?? 'open') !== 'open') {
            return null;
        }
        $title = '';
        $detail = '';
        $severity = '';
        $validity = '';
        $reference = '';
        if ($category === Rules::CATEGORY_DEPENDENCY) {
            $advisory = is_array($row['security_advisory'] ?? null) ? $row['security_advisory'] : [];
            $vulnerability = is_array($row['security_vulnerability'] ?? null) ? $row['security_vulnerability'] : [];
            $package = is_array($row['dependency']['package'] ?? null) ? $row['dependency']['package'] : [];
            $title = (string) ($advisory['summary'] ?? '');
            $severity = (string) ($advisory['severity'] ?? $vulnerability['severity'] ?? '');
            $detail = trim((string) ($package['ecosystem'] ?? '') . ' ' . (string) ($package['name'] ?? ''));
            $reference = (string) ($advisory['cve_id'] ?? '') ?: (string) ($advisory['ghsa_id'] ?? '');
        } elseif ($category === Rules::CATEGORY_SECRET) {
            $title = (string) ($row['secret_type_display_name'] ?? '') ?: (string) ($row['secret_type'] ?? '');
            $severity = Rules::CRITICAL;
            $validity = Rules::normalizeValidity((string) ($row['validity'] ?? ''));
            $detail = !empty($row['push_protection_bypassed']) ? 'push_protection_bypassed' : '';
        } else {
            $rule = is_array($row['rule'] ?? null) ? $row['rule'] : [];
            $tool = is_array($row['tool'] ?? null) ? $row['tool'] : [];
            $location = is_array($row['most_recent_instance']['location'] ?? null)
                ? $row['most_recent_instance']['location'] : [];
            $title = (string) ($rule['description'] ?? '') ?: (string) ($rule['name'] ?? $rule['id'] ?? '');
            $severity = (string) ($rule['security_severity_level'] ?? '') ?: (string) ($rule['severity'] ?? '');
            $detail = trim(implode(' · ', array_filter([
                (string) ($tool['name'] ?? ''),
                (string) ($location['path'] ?? ''),
            ])));
        }

        return [
            'category'  => $category,
            'key'       => $number,
            'severity'  => Rules::normalizeSeverity($severity),
            'title'     => mb_substr(trim($title) !== '' ? trim($title) : '#' . $number, 0, 255),
            'detail'    => mb_substr($detail, 0, 255),
            'reference' => preg_match('/^[A-Za-z0-9\-]{1,64}$/', $reference) === 1 ? $reference : '',
            'validity'  => $validity,
            'url'       => Rules::webUrl((string) ($row['html_url'] ?? '')),
            'detected'  => Rules::parseDate((string) ($row['created_at'] ?? '')),
        ];
    }

    /**
     * @return array{complete: bool, error: string, scans: array<string, string>, truncated: bool,
     *     alerts: list<array<string, string>>}
     */
    private function fetchGitlab(string $project): array
    {
        $out = ['complete' => false, 'error' => '', 'scans' => [], 'truncated' => false, 'alerts' => []];
        if (
            preg_match('#^[A-Za-z0-9_.\-]{1,100}(/[A-Za-z0-9_.\-]{1,100}){1,9}$#', $project) !== 1
            || str_contains($project, '..')
        ) {
            return ['error' => self::ERROR_REFUSED] + $out;
        }
        $body = (string) json_encode(['query' => self::GITLAB_QUERY, 'variables' => ['fullPath' => $project]]);
        $response = $this->send('POST', $this->gitlabUrl . '/api/graphql', [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->gitlabToken,
        ], $body);
        if ($response['error'] !== '') {
            return ['error' => $response['error']] + $out;
        }
        $status = $response['status'];
        if ($status === 401 || $status === 403) {
            return ['error' => self::ERROR_UNAUTHORIZED] + $out;
        }
        if ($status === 429) {
            return ['error' => self::ERROR_RATE_LIMIT] + $out;
        }
        if ($status < 200 || $status >= 300) {
            return ['error' => self::ERROR_STATUS] + $out;
        }
        $json = json_decode($response['body'], true);
        if (!is_array($json) || !array_key_exists('data', $json)) {
            return ['error' => self::ERROR_INVALID] + $out;
        }
        $project = is_array($json['data']['project'] ?? null) ? $json['data']['project'] : null;
        $vulnerabilities = is_array($project['vulnerabilities'] ?? null) ? $project['vulnerabilities'] : null;
        if ($project === null || $vulnerabilities === null) {
            // Projet introuvable, jeton sans accès, ou édition de GitLab sans tableau de vulnérabilités.
            $out['scans'] = array_fill_keys(Rules::CATEGORIES, Rules::SCAN_UNAVAILABLE);
            $out['complete'] = true;

            return $out;
        }
        $enabled = is_array($project['securityScanners']['enabled'] ?? null)
            ? array_map('strval', $project['securityScanners']['enabled']) : [];
        $dependencyScanners = ['DEPENDENCY_SCANNING', 'CONTAINER_SCANNING'];
        $out['scans'] = [
            Rules::CATEGORY_DEPENDENCY => array_intersect($enabled, $dependencyScanners) !== []
                ? Rules::SCAN_OK : Rules::SCAN_UNAVAILABLE,
            Rules::CATEGORY_SECRET     => in_array('SECRET_DETECTION', $enabled, true)
                ? Rules::SCAN_OK : Rules::SCAN_UNAVAILABLE,
            Rules::CATEGORY_CODE       => array_intersect($enabled, ['SAST', 'DAST']) !== []
                ? Rules::SCAN_OK : Rules::SCAN_UNAVAILABLE,
        ];
        $out['truncated'] = !empty($vulnerabilities['pageInfo']['hasNextPage']);
        foreach (is_array($vulnerabilities['nodes'] ?? null) ? $vulnerabilities['nodes'] : [] as $node) {
            $alert = is_array($node) ? self::gitlabAlert($node) : null;
            if ($alert !== null) {
                $out['alerts'][] = $alert;
                // Une alerte lue prouve que son analyse tourne, même si elle n'est plus déclarée.
                $out['scans'][$alert['category']] = Rules::SCAN_OK;
            }
        }
        $out['complete'] = true;

        return $out;
    }

    /**
     * Une vulnérabilité GitLab au format commun ; null si elle est illisible ou d'un autre état.
     *
     * @param array<mixed> $node
     * @return array<string, string>|null
     */
    public static function gitlabAlert(array $node): ?array
    {
        $id = (string) ($node['id'] ?? '');
        if (preg_match('#(\d{1,20})$#', $id, $m) !== 1) {
            return null;
        }
        if (!in_array(strtoupper((string) ($node['state'] ?? 'DETECTED')), ['DETECTED', 'CONFIRMED'], true)) {
            return null;
        }
        $category = match (strtoupper((string) ($node['reportType'] ?? ''))) {
            'SECRET_DETECTION' => Rules::CATEGORY_SECRET,
            'DEPENDENCY_SCANNING', 'CONTAINER_SCANNING', 'CLUSTER_IMAGE_SCANNING' => Rules::CATEGORY_DEPENDENCY,
            default            => Rules::CATEGORY_CODE,
        };
        $title = trim((string) ($node['title'] ?? ''));

        return [
            'category'  => $category,
            'key'       => $m[1],
            'severity'  => $category === Rules::CATEGORY_SECRET
                ? Rules::CRITICAL : Rules::normalizeSeverity((string) ($node['severity'] ?? '')),
            'title'     => mb_substr($title !== '' ? $title : '#' . $m[1], 0, 255),
            'detail'    => mb_substr(strtolower((string) ($node['reportType'] ?? '')), 0, 255),
            'reference' => '',
            'validity'  => '',
            'url'       => Rules::webUrl((string) ($node['webUrl'] ?? '')),
            'detected'  => Rules::parseDate((string) ($node['detectedAt'] ?? '')),
        ];
    }
}
