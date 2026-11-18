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

namespace GlpiPlugin\Grcmanager\Services\Incident;

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #707 : client en LECTURE SEULE de la Management API v2.1 de SentinelOne. Il n'envoie que
 * des requêtes GET, sur une liste fermée de chemins (ENDPOINTS) : aucune action (isolement,
 * résolution, changement de statut) ne peut partir vers la console.
 *
 * Le jeton est envoyé dans l'en-tête « Authorization: ApiToken ... » et nulle part ailleurs : il
 * n'apparaît ni dans l'adresse, ni dans les messages d'erreur renvoyés. Les appels passent par
 * Http::send() (#658 : proxy de GLPI, délai, adresses internes refusées sauf autorisation de
 * l'administrateur, redirections refusées). Le transport est injectable pour les tests.
 *
 * Erreurs non bloquantes : 401 (jeton refusé), 403 (droits du jeton ou licence), 404 (fonction
 * absente de la console), 429 (limite de débit, avec Retry-After) arrêtent la lecture en cours, qui
 * reprend au passage suivant ; rien n'est levé.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, array<string, string>): HttpResult
 * @phpstan-type Fetch array{items: int, pages: int, complete: bool, error: string, retry_after: int}
 */
final class SentinelOneClient
{
    public const API_PREFIX = '/web/api/v2.1';

    /** Seuls chemins lus (GET). */
    public const ENDPOINTS = [
        'agents'          => '/agents',
        'threats'         => '/threats',
        'alerts'          => '/cloud-detection/alerts',
        'vulnerabilities' => '/application-management/risks',
    ];

    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UNREACHABLE = 'unreachable';
    /** Adresse interne refusée par Http::send() (réglage de la page de configuration). */
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';

    /** Taille des pages demandées (maximum de l'API : 1000 ; 100 pour les menaces). */
    public const PAGE_SIZE = 100;

    /** Pages lues au plus par liste et par passage : la suite est lue au passage suivant. */
    public const MAX_PAGES = 50;

    public const TIMEOUT = 20;

    /** @var Transport */
    private Closure $transport;

    /**
     * @param string $baseUrl adresse de la console, déjà normalisée (SentinelOneParser::normalizeConsoleUrl())
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiToken,
        ?Closure $transport = null
    ) {
        $this->transport = $transport ?? static function (string $url, array $headers): array {
            $error = null;
            $response = Http::send('GET', $url, $headers, $error, self::TIMEOUT);

            return $response + ['error' => (string) $error];
        };
    }

    /**
     * Test de connexion : une page d'un élément d'agents et de menaces. « empty » si le jeton est
     * accepté mais ne voit rien (rôle sans site, compte d'un autre périmètre) : avertissement.
     *
     * @return array{ok: bool, error: string, agents: int|null, threats: int|null, empty: bool}
     */
    public function testConnection(): array
    {
        $agents = $this->page('agents', ['limit' => '1']);
        if ($agents['error'] !== '') {
            return ['ok' => false, 'error' => $agents['error'], 'agents' => null, 'threats' => null, 'empty' => false];
        }
        $threats = $this->page('threats', ['limit' => '1']);
        $agentCount = $agents['total'] ?? count($agents['items']);
        $threatCount = $threats['error'] === '' ? ($threats['total'] ?? count($threats['items'])) : null;

        return [
            'ok'      => true,
            'error'   => $threats['error'],
            'agents'  => $agentCount,
            'threats' => $threatCount,
            'empty'   => $agentCount === 0 && (int) $threatCount === 0,
        ];
    }

    /**
     * Lit une liste page par page (curseur), en passant chaque page à $onItems. S'arrête à la
     * première erreur ou après MAX_PAGES pages (complete = false).
     *
     * @param array<string, string> $query
     * @param callable(list<array<string, mixed>>): void $onItems
     * @return Fetch
     */
    public function fetchAll(string $endpoint, array $query, callable $onItems, int $maxPages = self::MAX_PAGES): array
    {
        $query += ['limit' => (string) self::PAGE_SIZE];
        $count = 0;
        $pages = 0;
        $cursor = null;
        do {
            if ($pages >= $maxPages) {
                return ['items' => $count, 'pages' => $pages, 'complete' => false, 'error' => '', 'retry_after' => 0];
            }
            $page = $this->page($endpoint, $cursor === null ? $query : $query + ['cursor' => $cursor]);
            if ($page['error'] !== '') {
                return [
                    'items' => $count, 'pages' => $pages, 'complete' => false,
                    'error' => $page['error'], 'retry_after' => $page['retry_after'],
                ];
            }
            $pages++;
            $count += count($page['items']);
            if ($page['items'] !== []) {
                $onItems($page['items']);
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null && $page['items'] !== []);

        return ['items' => $count, 'pages' => $pages, 'complete' => true, 'error' => '', 'retry_after' => 0];
    }

    /**
     * Une page d'une liste.
     *
     * @param array<string, string> $query
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null, total: int|null,
     *     error: string, retry_after: int}
     */
    public function page(string $endpoint, array $query): array
    {
        $empty = ['items' => [], 'next_cursor' => null, 'total' => null, 'error' => '', 'retry_after' => 0];
        if (!isset(self::ENDPOINTS[$endpoint])) {
            return ['error' => self::ERROR_NOT_FOUND] + $empty;
        }
        $url = $this->baseUrl . self::API_PREFIX . self::ENDPOINTS[$endpoint]
            . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $result = ($this->transport)($url, [
            'Authorization' => 'ApiToken ' . $this->apiToken,
            'Accept'        => 'application/json',
        ]);
        $error = self::errorOf($result['status']);
        $transportError = (string) ($result['error'] ?? '');
        if (
            $result['status'] === 0
            && (str_starts_with($transportError, Http::ERROR_INTERNAL) || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT))
        ) {
            $error = self::ERROR_INTERNAL;
        }
        if ($error !== '') {
            return ['error' => $error, 'retry_after' => max(0, $result['retry_after'])] + $empty;
        }
        $page = SentinelOneParser::page($result['body']);
        if ($page === null) {
            return ['error' => self::ERROR_INVALID] + $empty;
        }

        return $page + ['error' => '', 'retry_after' => 0];
    }

    public static function errorOf(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => '',
            $status === 0                   => self::ERROR_UNREACHABLE,
            $status === 401                 => self::ERROR_UNAUTHORIZED,
            $status === 403                 => self::ERROR_FORBIDDEN,
            $status === 404                 => self::ERROR_NOT_FOUND,
            $status === 429                 => self::ERROR_RATE_LIMITED,
            default                         => self::ERROR_STATUS,
        };
    }

    /**
     * Message lisible d'un code d'erreur (jamais le jeton ni l'adresse complète).
     */
    public static function errorLabel(string $error): string
    {
        return match ($error) {
            self::ERROR_UNAUTHORIZED => __('SentinelOne refused the API token (401): check that it is valid and has not expired.', 'grcmanager'),
            self::ERROR_FORBIDDEN    => __('The API token is not allowed to read this information (403): give it the Viewer role on the accounts or sites to read, or check the licence.', 'grcmanager'),
            self::ERROR_NOT_FOUND    => __('This information is not available on this console (404): it may not be included in the licence.', 'grcmanager'),
            self::ERROR_RATE_LIMITED => __('SentinelOne limits the number of requests (429): the next run resumes where this one stopped.', 'grcmanager'),
            self::ERROR_UNREACHABLE  => __('The SentinelOne console could not be reached: check its address, the GLPI proxy and, for an internal address, the setting that allows them.', 'grcmanager'),
            self::ERROR_INTERNAL     => __('The console address is an internal address, refused by default: an administrator can allow internal addresses in this configuration page.', 'grcmanager'),
            self::ERROR_INVALID      => __('The SentinelOne console sent an unexpected answer: check the console address.', 'grcmanager'),
            ''                       => '',
            default                  => __('The SentinelOne console returned an error.', 'grcmanager'),
        };
    }
}
