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

namespace GlpiPlugin\Grcmanager\Services\Access;

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #852 : lecture SEULE du rapport « détails de l'inscription des utilisateurs » de Microsoft
 * Entra ID par Microsoft Graph (reports/authenticationMethods/userRegistrationDetails).
 *
 * - Jeton d'application obtenu par le flux « client credentials » (identifiant et secret d'une
 *   application inscrite dans le locataire, permission d'application AuditLog.Read.All). Seule
 *   requête POST : celle du jeton, vers login.microsoftonline.com ; toutes les autres sont des GET
 *   sur graph.microsoft.com (ce rapport, et pour #846 les sites, OneDrive et équipes Teams avec
 *   leurs droits). Rien n'est jamais écrit dans Entra ID ni dans Microsoft 365.
 * - Le secret et le jeton ne sortent que dans le corps de la demande de jeton et dans l'en-tête
 *   Authorization : jamais dans une adresse, un message d'erreur ni un journal.
 * - Pages suivies par @odata.nextLink, seulement si le lien reste sur graph.microsoft.com ; au plus
 *   MAX_PAGES pages par lecture.
 * - Appels par Http::send() (#658 : proxy de GLPI, délai, redirections refusées). Transport
 *   injectable pour les tests (aucun appel réseau).
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 */
final class EntraGraphClient
{
    public const LOGIN_BASE = 'https://login.microsoftonline.com/';
    public const GRAPH_BASE = 'https://graph.microsoft.com/';
    public const REPORT_URL = self::GRAPH_BASE . 'v1.0/reports/authenticationMethods/userRegistrationDetails';
    public const SCOPE = 'https://graph.microsoft.com/.default';

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';
    /** Ressource absente (404), par exemple un compte sans OneDrive (#846). */
    public const ERROR_NOT_FOUND = 'not_found';
    /** Limite de pages atteinte : rien n'est remplacé (MfaCoverage::importFromGraph()). */
    public const ERROR_TOO_LARGE = 'too_large';

    /** Pages lues au plus par lecture (sur clic : la page web doit répondre). */
    public const MAX_PAGES = 100;

    public const TIMEOUT = 20;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var Transport */
    private Closure $transport;

    /** Jeton gardé pendant une lecture (jamais écrit ailleurs). */
    private ?string $token = null;

    private int $requests = 0;

    /**
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $tenant,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $secret,
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
     * Locataire valide : identifiant GUID ou nom de domaine (« exemple.onmicrosoft.com »).
     */
    public static function isValidTenant(string $tenant): bool
    {
        return preg_match(self::GUID, $tenant) === 1
            || preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $tenant) === 1;
    }

    /** Identifiant d'application (client) : GUID. */
    public static function isValidClientId(string $clientId): bool
    {
        return preg_match(self::GUID, $clientId) === 1;
    }

    /**
     * Issue #1043 : essai de la connexion enregistrée, par la seule demande d'un jeton d'application.
     * Rien n'est lu dans Entra ID ni enregistré. Renvoie le code d'erreur, vide si le jeton est obtenu.
     */
    public function test(): string
    {
        if (!self::isValidTenant($this->tenant) || !self::isValidClientId($this->clientId) || $this->secret === '') {
            return self::ERROR_CONFIG;
        }

        return $this->token()['error'];
    }

    /**
     * Lit tout le rapport (complete = false si la limite de pages est atteinte ou en cas d'erreur).
     *
     * @return array{items: list<array<string, mixed>>, pages: int, complete: bool, error: string}
     */
    public function fetchRegistrationDetails(int $maxPages = self::MAX_PAGES): array
    {
        return $this->fetchCollection(self::REPORT_URL, $maxPages);
    }

    /**
     * Lit une collection Graph (GET seulement, page par page). $url doit commencer par GRAPH_BASE.
     * #846 : réutilisé pour SharePoint, OneDrive et Teams ; le jeton est gardé pour la lecture.
     *
     * @param array<string, string> $headers en-têtes ajoutés (ConsistencyLevel par exemple)
     * @return array{items: list<array<string, mixed>>, pages: int, complete: bool, error: string}
     */
    public function fetchCollection(string $url, int $maxPages = self::MAX_PAGES, array $headers = []): array
    {
        $out = ['items' => [], 'pages' => 0, 'complete' => false, 'error' => ''];
        if (!self::isValidTenant($this->tenant) || !self::isValidClientId($this->clientId) || $this->secret === '') {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        if (!str_starts_with($url, self::GRAPH_BASE)) {
            return ['error' => self::ERROR_INVALID] + $out;
        }
        if ($this->token === null) {
            $token = $this->token();
            if ($token['error'] !== '') {
                return ['error' => $token['error']] + $out;
            }
            $this->token = $token['token'];
        }
        $next = $url;
        while ($next !== null) {
            if ($out['pages'] >= $maxPages) {
                return $out;
            }
            $this->requests++;
            $result = ($this->transport)('GET', $next, [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ] + $headers, null);
            $error = self::errorOf($result['status']);
            if ($error !== '') {
                return ['error' => $error] + $out;
            }
            $page = json_decode($result['body'], true);
            if (!is_array($page) || !isset($page['value']) || !is_array($page['value'])) {
                return ['error' => self::ERROR_INVALID] + $out;
            }
            $out['pages']++;
            foreach ($page['value'] as $item) {
                if (is_array($item)) {
                    $out['items'][] = $item;
                }
            }
            $link = $page['@odata.nextLink'] ?? null;
            // Page suivante : seulement sur Graph, jamais vers un autre hôte.
            $next = is_string($link) && str_starts_with($link, self::GRAPH_BASE) ? $link : null;
        }
        $out['complete'] = true;

        return $out;
    }

    /** Requêtes GET envoyées depuis la création du client (budget d'une lecture sur clic). */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Jeton d'application (client credentials).
     *
     * @return array{token: string, error: string}
     */
    private function token(): array
    {
        $result = ($this->transport)(
            'POST',
            self::LOGIN_BASE . rawurlencode($this->tenant) . '/oauth2/v2.0/token',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->secret,
                'scope'         => self::SCOPE,
            ], '', '&', PHP_QUERY_RFC3986)
        );
        // Locataire, identifiant ou secret refusés : 400 ou 401 selon l'erreur OAuth.
        if (in_array($result['status'], [400, 401], true)) {
            return ['token' => '', 'error' => self::ERROR_UNAUTHORIZED];
        }
        $error = self::errorOf($result['status']);
        if ($error !== '') {
            return ['token' => '', 'error' => $error];
        }
        $data = json_decode($result['body'], true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        return is_string($token) && $token !== ''
            ? ['token' => $token, 'error' => '']
            : ['token' => '', 'error' => self::ERROR_INVALID];
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
     * Comptes du rapport au format des lignes d'import CSV (MfaCoverageRules::parseCsv()).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{account: string, email: string, methods: list<string>, mfa: ?bool,
     *     admin: ?bool, remote: ?bool, excluded: bool, reason: string}>
     */
    public static function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $upn = is_string($item['userPrincipalName'] ?? null) ? $item['userPrincipalName'] : '';
            $upn = mb_substr(trim($upn), 0, 255);
            if ($upn === '') {
                continue;
            }
            $methods = [];
            foreach (is_array($item['methodsRegistered'] ?? null) ? $item['methodsRegistered'] : [] as $method) {
                if (is_string($method)) {
                    $methods[] = $method;
                }
            }
            $rows[] = [
                'account'  => $upn,
                'email'    => str_contains($upn, '@') ? $upn : '',
                'methods'  => MfaCoverageRules::splitMethods(implode(',', $methods)),
                'mfa'      => is_bool($item['isMfaRegistered'] ?? null) ? $item['isMfaRegistered'] : null,
                'admin'    => is_bool($item['isAdmin'] ?? null) ? $item['isAdmin'] : null,
                'remote'   => null,
                'excluded' => false,
                'reason'   => '',
            ];
        }

        return $rows;
    }
}
