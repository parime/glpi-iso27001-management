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
 * Issue #859 : client en LECTURE SEULE de l'API publique d'organisation de Bitwarden (« Bitwarden
 * Public API », offres Teams et Enterprise, en nuage ou auto-hébergé).
 *
 * Documentation officielle suivie :
 * - https://bitwarden.com/help/public-api/ : adresses (https://api.bitwarden.com,
 *   https://api.bitwarden.eu, https://<serveur>/api), jeton par
 *   POST <identité>/connect/token (grant_type=client_credentials, scope=api.organization,
 *   client_id « organization.<id> », client_secret), réponse access_token / expires_in / token_type ;
 * - référence de l'API (https://bitwarden.com/help/api/) et modèles du serveur ouvert
 *   (https://github.com/bitwarden/server, src/Api/AdminConsole/Public/Controllers/MembersController.cs,
 *   Models/Response/MemberResponseModel.cs, AssociationWithPermissionsResponseModel.cs) :
 *   GET /public/members renvoie { "object": "list", "data": [ { "object": "member", "id", "userId",
 *   "name", "email", "twoFactorEnabled", "status", "type", "externalId", "collections": [ { "id",
 *   "readOnly", "hidePasswords", "manage" } ] } ] }.
 *
 * Seuls deux appels sont possibles : la demande du jeton et GET /public/members. Les éléments du
 * coffre ne sont pas exposés par cette API (chiffrés de bout en bout) : ils s'importent en CSV de
 * métadonnées. Vaultwarden n'implémente de cette API que l'import d'annuaire
 * (POST /public/organization/import, src/api/core/public.rs du dépôt dani-garcia/vaultwarden) : ses
 * membres s'importent aussi en CSV (interface en ligne de commande « bw »).
 *
 * Secret jamais dans l'adresse, un message d'erreur ni un journal. Appels par Http::send() (proxy de
 * GLPI, délai, redirections refusées, adresses internes seulement si l'administrateur les a
 * autorisées). Transport injectable pour les tests.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int, error?: string}
 * @phpstan-type Transport Closure(string, string, array<string, string>, ?string): HttpResult
 */
final class BitwardenPublicClient
{
    public const SERVER_US = 'us';
    public const SERVER_EU = 'eu';
    public const SERVER_SELF = 'self';

    /** Adresses de l'API et du service d'identité du nuage Bitwarden. */
    public const CLOUD = [
        self::SERVER_US => ['api' => 'https://api.bitwarden.com', 'identity' => 'https://identity.bitwarden.com'],
        self::SERVER_EU => ['api' => 'https://api.bitwarden.eu', 'identity' => 'https://identity.bitwarden.eu'],
    ];

    public const SCOPE = 'api.organization';

    public const ERROR_CONFIG = 'not_configured';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_INVALID = 'invalid_response';
    public const ERROR_STATUS = 'http_error';

    /** Membres lus au plus. */
    public const MAX_MEMBERS = 5000;

    public const TIMEOUT = 20;

    private const CLIENT_ID = '/^organization\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var Transport */
    private Closure $transport;

    /**
     * @param string $apiBase adresse de l'API, sans « / » final (vide : non configuré)
     * @param string $identityBase adresse du service d'identité, sans « / » final
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     */
    public function __construct(
        private readonly string $apiBase,
        private readonly string $identityBase,
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
     * Adresses de l'API et du service d'identité d'un serveur : nuage américain ou européen, ou
     * serveur auto-hébergé (<adresse>/api et <adresse>/identity). Vide si l'adresse est refusée.
     *
     * @return array{api: string, identity: string}
     */
    public static function endpoints(string $server, string $selfUrl, bool $allowHttp = false): array
    {
        if (isset(self::CLOUD[$server])) {
            return self::CLOUD[$server];
        }
        $base = self::normalizeBaseUrl($selfUrl, $allowHttp);

        return $base === null
            ? ['api' => '', 'identity' => '']
            : ['api' => $base . '/api', 'identity' => $base . '/identity'];
    }

    /**
     * Adresse d'un serveur auto-hébergé : https (http seulement si les adresses internes sont
     * autorisées), sans identifiants, requête ni fragment ; un « /api » ou « /identity » final est
     * retiré.
     */
    public static function normalizeBaseUrl(string $url, bool $allowHttp = false): ?string
    {
        $parts = parse_url(trim($url));
        if (
            !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
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
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && (preg_match('#^[A-Za-z0-9/_.\-~]*$#', $path) !== 1 || str_contains($path, '..'))) {
            return null;
        }
        $path = (string) preg_replace('#/(api|identity)$#i', '', rtrim($path, '/'));

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
    }

    public static function isValidClientId(string $clientId): bool
    {
        return preg_match(self::CLIENT_ID, trim($clientId)) === 1;
    }

    private function isConfigured(): bool
    {
        return $this->apiBase !== '' && $this->identityBase !== '' && self::isValidClientId($this->clientId)
            && $this->secret !== '';
    }

    /**
     * Essai de la connexion : la seule demande d'un jeton. Rien n'est lu ni enregistré. Renvoie le
     * code d'erreur, vide si le jeton est obtenu.
     */
    public function test(): string
    {
        if (!$this->isConfigured()) {
            return self::ERROR_CONFIG;
        }

        return $this->token()['error'];
    }

    /**
     * Membres de l'organisation, au format des lignes d'import (PrivilegedVaultRules::memberRow()).
     * complete = false en cas d'erreur : rien n'est alors remplacé par l'appelant.
     *
     * @return array{members: list<array<string, mixed>>, truncated: bool, complete: bool, error: string}
     */
    public function fetchMembers(): array
    {
        $out = ['members' => [], 'truncated' => false, 'complete' => false, 'error' => ''];
        if (!$this->isConfigured()) {
            return ['error' => self::ERROR_CONFIG] + $out;
        }
        $token = $this->token();
        if ($token['error'] !== '') {
            return ['error' => $token['error']] + $out;
        }
        $result = ($this->transport)('GET', $this->apiBase . '/public/members', [
            'Authorization' => 'Bearer ' . $token['token'],
            'Accept'        => 'application/json',
        ], null);
        $error = self::errorOf($result);
        if ($error !== '') {
            return ['error' => $error] + $out;
        }
        $page = json_decode((string) $result['body'], true);
        if (!is_array($page) || !is_array($page['data'] ?? null)) {
            return ['error' => self::ERROR_INVALID] + $out;
        }
        foreach ($page['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (count($out['members']) >= self::MAX_MEMBERS) {
                $out['truncated'] = true;
                break;
            }
            $row = self::memberFromApi($item);
            if ($row !== null) {
                $out['members'][] = $row;
            }
        }
        $out['complete'] = true;

        return $out;
    }

    /**
     * Un membre de GET /public/members : adresse, nom, rôle (type), statut, double authentification
     * et identifiants des collections accessibles.
     *
     * @param array<mixed> $item
     * @return array<string, mixed>|null
     */
    public static function memberFromApi(array $item): ?array
    {
        $collections = [];
        foreach (is_array($item['collections'] ?? null) ? $item['collections'] : [] as $collection) {
            if (is_array($collection) && is_string($collection['id'] ?? null) && $collection['id'] !== '') {
                $collections[] = mb_substr($collection['id'], 0, 64);
            }
        }

        return PrivilegedVaultRules::memberRow(
            is_string($item['id'] ?? null) ? $item['id'] : '',
            is_string($item['email'] ?? null) ? $item['email'] : '',
            is_string($item['name'] ?? null) ? $item['name'] : '',
            is_int($item['type'] ?? null) || is_string($item['type'] ?? null) ? $item['type'] : '',
            is_int($item['status'] ?? null) || is_string($item['status'] ?? null) ? $item['status'] : '',
            array_values(array_unique($collections)),
            is_bool($item['twoFactorEnabled'] ?? null) ? $item['twoFactorEnabled'] : null
        );
    }

    /**
     * Jeton d'organisation (client credentials).
     *
     * @return array{token: string, error: string}
     */
    private function token(): array
    {
        $result = ($this->transport)(
            'POST',
            $this->identityBase . '/connect/token',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query([
                'grant_type'    => 'client_credentials',
                'scope'         => self::SCOPE,
                'client_id'     => trim($this->clientId),
                'client_secret' => $this->secret,
            ], '', '&', PHP_QUERY_RFC3986)
        );
        // Identifiant ou secret refusés : 400 (invalid_client) ou 401.
        if (in_array((int) $result['status'], [400, 401], true)) {
            return ['token' => '', 'error' => self::ERROR_UNAUTHORIZED];
        }
        $error = self::errorOf($result);
        if ($error !== '') {
            return ['token' => '', 'error' => $error];
        }
        $data = json_decode((string) $result['body'], true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        return is_string($token) && $token !== ''
            ? ['token' => $token, 'error' => '']
            : ['token' => '', 'error' => self::ERROR_INVALID];
    }

    /**
     * @param array{status: int, body: string, retry_after: int, error?: string} $result
     */
    public static function errorOf(array $result): string
    {
        $status = (int) $result['status'];
        if ($status === 0) {
            $transportError = (string) ($result['error'] ?? '');

            return str_starts_with($transportError, Http::ERROR_INTERNAL)
                || str_starts_with($transportError, Http::ERROR_GLPI_CONTEXT)
                ? self::ERROR_INTERNAL : self::ERROR_UNREACHABLE;
        }

        return match (true) {
            $status >= 200 && $status < 300 => '',
            $status === 401                 => self::ERROR_UNAUTHORIZED,
            $status === 403                 => self::ERROR_FORBIDDEN,
            $status === 404                 => self::ERROR_NOT_FOUND,
            $status === 429                 => self::ERROR_RATE_LIMITED,
            default                         => self::ERROR_STATUS,
        };
    }
}
