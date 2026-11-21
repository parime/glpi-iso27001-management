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

namespace GlpiPlugin\Grcmanager\Compat;

use GlpiPlugin\Grcmanager\Menu;

/**
 * Issue #658 : point de passage unique de TOUS les appels HTTP sortants du plugin (NVD, CISA KEV,
 * EPSS, flux du CERT-FR, Have I Been Pwned, dernière version publiée sur GitHub). Aucun autre code
 * du plugin n'ouvre de connexion réseau.
 *
 * - Client HTTP du cœur GLPI, donc son proxy : Glpi\Toolbox\HttpClient en GLPI 12, avec le contexte
 *   de la classe du plugin (Menu::class), Toolbox::getGuzzleClient() en GLPI 11 (le premier n'y
 *   existe pas).
 * - Délai d'expiration total, en plus du délai de connexion du cœur.
 * - Redirections jamais suivies : une réponse 3xx est un échec (une redirection pourrait mener vers
 *   une adresse interne après le contrôle).
 * - Schémas http et https seulement.
 * - Adresses internes refusées (boucle locale, réseaux privés, lien local dont les métadonnées
 *   cloud 169.254.169.254, adresses réservées, IPv4 dans IPv6, écritures numériques) : le nom est
 *   résolu, chaque adresse obtenue est contrôlée, et la connexion est épinglée sur l'adresse
 *   contrôlée (pas de second DNS qui pourrait répondre autre chose). Un administrateur peut les
 *   autoriser dans la page de configuration du plugin (proxy ou miroir interne) ; en GLPI 12, le
 *   cœur exige EN PLUS que Menu::class figure dans la constante
 *   GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS (config/local_define.php).
 * - Aucun en-tête d'authentification GLPI (jeton de session, jeton d'application, cookie) n'est
 *   jamais transmis : ils sont retirés s'ils sont fournis par erreur. Les identifiants dans
 *   l'adresse (https://utilisateur:secret@hôte) sont refusés.
 */
final class Http
{
    /** Contexte passé au client du cœur GLPI 12 (proxy et réseaux privés par contexte). */
    public const CONTEXT = Menu::class;

    /** Réglage global (table de configuration du cœur) : adresses internes autorisées. */
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CONFIG_ALLOW_PRIVATE = 'http_allow_private_networks';

    public const DEFAULT_TIMEOUT = 10;

    public const ERROR_SCHEME = 'scheme_not_allowed';
    public const ERROR_HOST = 'invalid_host';
    public const ERROR_INTERNAL = 'internal_address';
    public const ERROR_UNRESOLVED = 'unresolved_host';
    public const ERROR_GLPI_CONTEXT = 'glpi_private_network_context_required';
    public const ERROR_REDIRECT = 'redirect_refused';
    public const ERROR_STATUS = 'http_status';
    public const ERROR_TRANSPORT = 'transport_error';

    /** En-têtes jamais envoyés hors de GLPI. */
    private const FORBIDDEN_HEADER_PATTERN = '/^(session-token|app-token|x-glpi-.*|cookie)$/i';

    /**
     * Les administrateurs ont-ils autorisé les adresses internes (page de configuration) ?
     */
    public static function allowsPrivateNetworks(): bool
    {
        $values = \Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_ALLOW_PRIVATE]);

        return !empty($values[self::CONFIG_ALLOW_PRIVATE]);
    }

    public static function setAllowPrivateNetworks(bool $allow): void
    {
        \Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_ALLOW_PRIVATE => $allow ? 1 : 0]);
    }

    /**
     * Adresse IP interne (non routable sur Internet) : boucle locale, privée, lien local, partagée
     * (CGNAT), réservée, multicast, non spécifiée ; IPv4 encapsulée dans IPv6 comprise. Une chaîne
     * qui n'est pas une adresse IP est considérée comme interne (refus par défaut).
     */
    public static function isInternalIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        // ::ffff:127.0.0.1, ::127.0.0.1 : l'adresse IPv4 portée est celle qui compte.
        if (preg_match('/^::(ffff(:0{1,4})?:)?(\d{1,3}(\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            return self::isInternalIp($m[3]);
        }
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        );
        if ($public === false) {
            return true;
        }
        // Multicast (224.0.0.0/4, ff00::/8) : jamais une cible HTTP légitime.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ((int) explode('.', $ip)[0]) >= 224;
        }

        return stripos($ip, 'ff') === 0;
    }

    /**
     * Contrôle d'une adresse avant tout appel. Renvoie null si elle est acceptable, sinon le code
     * d'erreur (constantes ERROR_*). Avec $resolve, le nom d'hôte est résolu et chaque adresse
     * obtenue contrôlée ; $resolvedIp reçoit alors l'adresse sur laquelle épingler la connexion
     * (null pour une adresse IP littérale). Sans $resolve, seules les adresses IP littérales et les
     * noms locaux sont contrôlés (aucun accès réseau, pas même DNS).
     *
     * @param bool|null $allowPrivate null : réglage de la page de configuration.
     */
    public static function checkUrl(
        string $url,
        bool $resolve = true,
        ?bool $allowPrivate = null,
        ?string &$resolvedIp = null
    ): ?string {
        $resolvedIp = null;
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return self::ERROR_SCHEME;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::ERROR_HOST;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || preg_match('/[^a-z0-9.:_-]/', $host) === 1) {
            return self::ERROR_HOST;
        }
        $allowPrivate ??= self::allowsPrivateNetworks();

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return !$allowPrivate && self::isInternalIp($host) ? self::ERROR_INTERNAL : null;
        }
        // Écritures numériques d'une adresse (2130706433, 0x7f.1, 017700000001) que la résolution
        // transformerait en adresse IP : refusées, une vraie adresse s'écrit en notation pointée.
        if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}\.?$/i', $host) === 1) {
            return self::ERROR_HOST;
        }
        if (
            !$allowPrivate
            && preg_match('/(^|\.)(localhost|localdomain|local|internal|home\.arpa)\.?$/', $host) === 1
        ) {
            return self::ERROR_INTERNAL;
        }
        if (!$resolve) {
            return null;
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            // Derrière un proxy, c'est lui qui résout : le serveur GLPI peut ne pas connaître le nom.
            return self::usesProxy() ? null : self::ERROR_UNRESOLVED;
        }
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (self::isInternalIp($ip)) {
                    return self::ERROR_INTERNAL;
                }
            }
        }
        $resolvedIp = $ips[0];

        return null;
    }

    /**
     * LA fonction d'appel sortant du plugin. Ne lève jamais d'exception. Renvoie le code HTTP, le
     * corps et le délai « Retry-After ». Code 0 si l'appel a été refusé ou a échoué (adresse
     * refusée, redirection, erreur réseau), $error recevant alors « code : détail » (code =
     * constante ERROR_*). Les codes 4xx et 5xx sont renvoyés tels quels (une API peut répondre 404
     * « rien » ou 429 « trop de requêtes »), avec $error = ERROR_STATUS. $body : corps envoyé tel
     * quel (formulaire d'obtention d'un jeton OAuth, #852), avec l'en-tête Content-Type fourni.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    public static function send(
        string $method,
        string $url,
        array $headers = [],
        ?string &$error = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        ?string $body = null
    ): array {
        $error = null;
        $failed = ['status' => 0, 'body' => '', 'retry_after' => 0];

        $allowPrivate = self::allowsPrivateNetworks();
        $pinnedIp = null;
        $refusal = self::checkUrl($url, true, $allowPrivate, $pinnedIp);
        if ($refusal !== null) {
            $error = $refusal;

            return $failed;
        }

        $glpi12 = class_exists(\Glpi\Toolbox\HttpClient::class);
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($glpi12 && $allowPrivate && self::isInternalIp($pinnedIp ?? $host) && !self::glpiAllowsPrivateNetworks()) {
            $error = self::ERROR_GLPI_CONTEXT;

            return $failed;
        }

        $sent = [
            'User-Agent' => 'GLPI-grcmanager/'
                . (defined('PLUGIN_GRCMANAGER_VERSION') ? PLUGIN_GRCMANAGER_VERSION : 'dev'),
        ];
        foreach ($headers as $name => $value) {
            if (preg_match(self::FORBIDDEN_HEADER_PATTERN, (string) $name) !== 1) {
                $sent[(string) $name] = (string) $value;
            }
        }
        // Épinglage sur l'adresse contrôlée (sans proxy : avec un proxy, c'est lui qui se connecte).
        $pin = $pinnedIp !== null && !self::usesProxy() ? $pinnedIp : null;
        $method = strtoupper($method);
        $timeout = max(1, $timeout);

        try {
            $response = $glpi12
                ? self::sendWithGlpi12($method, $url, $host, $pin, $sent, $timeout, $body)
                : self::sendWithGlpi11($method, $url, $host, $pin, $sent, $timeout, $body);
        } catch (\Throwable $e) {
            $error = self::ERROR_TRANSPORT . ': ' . $e->getMessage();

            return $failed;
        }

        if ($response['status'] >= 300 && $response['status'] < 400) {
            $error = self::ERROR_REDIRECT . ': HTTP ' . $response['status'];

            return $failed;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $error = self::ERROR_STATUS . ': HTTP ' . $response['status'];
        }

        return $response;
    }

    /**
     * Requête GET avec en-têtes, pour une API qui répond par son code HTTP (voir send()).
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    public static function request(string $url, array $headers, ?string &$error = null): array
    {
        return self::send('GET', $url, $headers, $error);
    }

    /**
     * @return string Le corps d'une réponse 2xx, ou '' en cas d'échec, $error décrivant alors la cause.
     */
    public static function getContent(string $url, ?string &$error = null): string
    {
        $response = self::send('GET', $url, [], $error);

        return $error === null ? $response['body'] : '';
    }

    /**
     * GLPI 12 : la classe du plugin figure-t-elle dans les contextes autorisés à joindre un réseau
     * privé (constante du cœur) ?
     */
    public static function glpiAllowsPrivateNetworks(): bool
    {
        $contexts = defined('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS')
            ? constant('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS') : [];

        return is_array($contexts) && in_array(self::CONTEXT, $contexts, true);
    }

    /**
     * Rappel de la page de configuration en GLPI 12, quand le cœur n'autorise pas encore les
     * réseaux privés pour ce plugin ('' sinon).
     */
    public static function glpi12ContextHint(): string
    {
        if (!class_exists(\Glpi\Toolbox\HttpClient::class) || self::glpiAllowsPrivateNetworks()) {
            return '';
        }

        return sprintf(
            __(
                'GLPI also refuses internal addresses for this plugin: add %s to the constant '
                    . 'GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS (config/local_define.php).',
                'grcmanager'
            ),
            self::CONTEXT
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    private static function sendWithGlpi12(
        string $method,
        string $url,
        string $host,
        ?string $pin,
        array $headers,
        int $timeout,
        ?string $body = null
    ): array {
        if (!class_exists(\Glpi\Toolbox\HttpClient::class)) {
            throw new \LogicException('Glpi\Toolbox\HttpClient is not available.');
        }
        $options = [
            'headers'       => $headers,
            'timeout'       => $timeout,
            'max_duration'  => $timeout,
            'max_redirects' => 0,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }
        if ($pin !== null) {
            $options['resolve'] = [$host => $pin];
        }
        try {
            $response = (new \Glpi\Toolbox\HttpClient(self::CONTEXT))->request($method, $url, $options);
        } catch (\Throwable $e) {
            // Le client du cœur lève une exception pour toute réponse 3xx, 4xx ou 5xx, qui porte la
            // réponse (interface HttpExceptionInterface de Symfony) ; sinon, échec réseau.
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
                throw $e;
            }
        }
        $responseHeaders = $response->getHeaders(false);

        return [
            'status'      => (int) $response->getStatusCode(),
            'body'        => (string) $response->getContent(false),
            'retry_after' => (int) ($responseHeaders['retry-after'][0] ?? 0),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    private static function sendWithGlpi11(
        string $method,
        string $url,
        string $host,
        ?string $pin,
        array $headers,
        int $timeout,
        ?string $body = null
    ): array {
        $options = [
            'headers'         => $headers,
            'timeout'         => $timeout,
            'allow_redirects' => false,
            'http_errors'     => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }
        if (defined('CURLOPT_PROTOCOLS')) {
            $options['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
            if ($pin !== null) {
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
                $options['curl'][CURLOPT_RESOLVE] = [
                    sprintf('%s:%d:%s', $host, $port, str_contains($pin, ':') ? '[' . $pin . ']' : $pin),
                ];
            }
        }
        $response = \Toolbox::getGuzzleClient(['timeout' => $timeout])->request($method, $url, $options);

        return [
            'status'      => $response->getStatusCode(),
            'body'        => (string) $response->getBody(),
            'retry_after' => (int) $response->getHeaderLine('Retry-After'),
        ];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                $ips = array_merge($ips, array_column($records, 'ipv6'));
            }
        }

        return array_values(array_unique(array_map('strval', $ips)));
    }

    private static function usesProxy(): bool
    {
        global $CFG_GLPI;

        return !empty($CFG_GLPI['proxy_name']);
    }
}
